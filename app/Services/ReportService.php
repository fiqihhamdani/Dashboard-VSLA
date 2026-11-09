<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\Installment;
use App\Models\Member;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    private function cash(array $filter): Builder
    {
        return CashEntry::query()->when($filter['group_id'] ?? null, fn ($q, $id) => $q->where('group_id', $id));
    }

    private function balance(Builder $query): int
    {
        return (int) $query->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -CAST(amount AS SIGNED) END),0) AS balance")->value('balance');
    }

    public function dashboard(array $filter): array
    {
        return DB::transaction(fn (): array => $this->buildDashboard($filter));
    }

    public function exportData(array $filter): array
    {
        return DB::transaction(fn (): array => ['report' => $this->dashboard($filter), 'ledger' => $this->ledger($filter)]);
    }

    private function buildDashboard(array $filter): array
    {
        $start = $filter['start'];
        $end = $filter['end'];
        $opening = $this->balance($this->cash($filter)->where('date', '<', $start));
        $period = $this->cash($filter)->whereBetween('date', [$start, $end]);
        $summary = ['opening' => $opening, 'balance' => $this->balance($this->cash($filter)->where('date', '<=', $end)),
            'savings' => $this->balance($this->cash($filter)->where('category', 'saving')->where('date', '<=', $end)),
            'loans' => (int) (clone $period)->where('category', 'loan')->sum('amount'),
            'payments' => (int) (clone $period)->where('category', 'payment')->sum('amount'),
            'overdue' => 0, 'active_members' => Member::when($filter['group_id'] ?? null, fn ($q, $id) => $q->where('group_id', $id))->where('is_active', true)->count()];
        $paid = Payment::query()->where('paid_at', '<=', $end)->selectRaw('installment_id, SUM(amount) AS paid')->groupBy('installment_id');
        $summary['overdue'] = (int) Installment::query()->join('loans', 'loans.id', '=', 'installments.loan_id')->join('members', 'members.id', '=', 'loans.member_id')->leftJoinSub($paid, 'paid', fn ($join) => $join->on('paid.installment_id', '=', 'installments.id'))->where('installments.due_date', '<=', $end)->when($filter['group_id'] ?? null, fn ($q, $id) => $q->where('members.group_id', $id))->selectRaw('COALESCE(SUM(installments.amount - COALESCE(paid.paid,0)),0) AS overdue')->value('overdue');
        $buckets = (clone $period)->selectRaw("SUBSTR(date,1,7) AS month, SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) AS incoming, SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) AS outgoing, SUM(CASE WHEN category='saving' AND direction='in' THEN amount ELSE 0 END) AS deposits, SUM(CASE WHEN category='loan' THEN amount ELSE 0 END) AS loans, SUM(CASE WHEN category='payment' THEN amount ELSE 0 END) AS payments")->groupByRaw('SUBSTR(date,1,7)')->get()->keyBy('month');
        $months = [];
        $balance = $opening;
        for ($month = CarbonImmutable::parse($start)->startOfMonth(); $month->lte(CarbonImmutable::parse($end)); $month = $month->addMonth()) {
            $b = $buckets->get($month->format('Y-m'));
            $in = (int) ($b?->incoming ?? 0);
            $out = (int) ($b?->outgoing ?? 0);
            $balance += $in - $out;
            $months[] = ['month' => $month->format('Y-m'), 'label' => $month->locale('id')->translatedFormat('M Y'), 'in' => $in, 'out' => $out, 'balance' => $balance, 'deposits' => (int) ($b?->deposits ?? 0), 'loans' => (int) ($b?->loans ?? 0), 'payments' => (int) ($b?->payments ?? 0)];
        }

        return ['filter' => $filter, 'summary' => $summary, 'months' => $months, 'has_transactions' => $this->cash($filter)->where('date', '<=', $end)->exists()];
    }

    public function ledger(array $filter): Collection
    {
        $balance = $this->balance($this->cash($filter)->where('date', '<', $filter['start']));

        return $this->cash($filter)->with('group')->whereBetween('date', [$filter['start'], $filter['end']])->orderBy('date')->orderBy('id')->get()->map(function ($cash) use (&$balance) {
            $in = $cash->direction === 'in' ? $cash->amount : 0;
            $out = $cash->direction === 'out' ? $cash->amount : 0;
            $balance += $in - $out;

            return ['date' => $cash->date->toDateString(), 'group' => $cash->group->name, 'description' => $cash->description, 'in' => $in, 'out' => $out, 'balance' => $balance];
        });
    }
}
