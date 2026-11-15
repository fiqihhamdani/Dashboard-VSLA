<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Saving;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function store(User $admin, array $data): CashEntry
    {
        return DB::transaction(function () use ($admin, $data) {
            User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $previous = CashEntry::where('created_by', $admin->id)->where('request_key', $data['request_key'])->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    $this->fail('request_key', 'Formulir ini sudah dipakai untuk data lain. Buka formulir baru.');
                }

                return $previous;
            }
            $group = Group::whereKey($data['group_id'])->lockForUpdate()->firstOrFail();
            if (! $group->is_active && $data['kind'] !== 'payment') {
                $this->fail('group_id', 'Kelompok nonaktif tidak dapat menerima transaksi baru selain pembayaran tagihan lama.');
            }
            $amount = 0;
            $direction = 'in';
            $description = '';
            $loanDetails = [];
            switch ($data['kind']) {
                case 'saving':
                    foreach ($data['rows'] as $index => $row) {
                        $member = $this->member((int) $row['member_id'], $group, true, "rows.$index.member_id");
                        if ($data['direction'] === 'out') {
                            $this->checkWithdrawal($member, $data['type'], (int) $row['amount'], $data['date'], "rows.$index.amount");
                        }
                        $amount += (int) $row['amount'];
                    }
                    $direction = $data['direction'];
                    $description = ($direction === 'in' ? 'Setoran' : 'Penarikan').' simpanan '.$data['type'].' ('.count($data['rows']).' anggota)';
                    break;
                case 'loan':
                    $this->member((int) $data['member_id'], $group, true);
                    $rateParts = explode('.', (string) $data['rate']);
                    $bps = (int) $rateParts[0] * 100 + (int) str_pad($rateParts[1] ?? '', 2, '0');
                    $interest = intdiv((int) $data['principal'] * $bps * (int) $data['months'] + 5000, 10000);
                    $amount = (int) $data['principal'];
                    $direction = 'out';
                    $description = 'Pencairan pinjaman';
                    $loanDetails = ['principal' => $amount, 'rate_bps' => $bps, 'months' => (int) $data['months'], 'interest' => $interest, 'total' => $amount + $interest];
                    if ($loanDetails['total'] < $loanDetails['months']) {
                        $this->fail('principal', 'Total pinjaman harus cukup untuk cicilan minimal Rp1 setiap bulan.');
                    }
                    break;
                case 'payment':
                    $this->member((int) $data['member_id'], $group, false);
                    $bill = Installment::with('loan')->whereKey($data['installment_id'])->lockForUpdate()->firstOrFail();
                    if ($bill->loan->member_id !== (int) $data['member_id']) {
                        $this->fail('installment_id', 'Tagihan tidak dimiliki anggota yang dipilih.');
                    }
                    if ($data['date'] < $bill->loan->disbursed_at->toDateString()) {
                        $this->fail('date', 'Tanggal pembayaran tidak boleh sebelum tanggal pencairan.');
                    }
                    $amount = (int) $data['amount'];
                    // A locking read sees payments committed while this transaction waited for the bill lock.
                    $paid = (int) $bill->payments()->lockForUpdate()->get()->sum('amount');
                    if ($amount > $bill->amount - $paid) {
                        $this->fail('amount', 'Pembayaran melebihi sisa tagihan. Periksa nominal yang masih terbuka.');
                    }
                    $description = 'Pembayaran angsuran ke-'.$bill->sequence;
                    break;
                case 'other': $amount = (int) $data['amount'];
                    $direction = $data['direction'];
                    $description = $data['description'];
                    break;
            }
            $cash = CashEntry::create(['group_id' => $group->id, 'created_by' => $admin->id, 'request_key' => $data['request_key'], 'fingerprint' => $fingerprint, 'date' => $data['date'], 'direction' => $direction, 'category' => $data['kind'], 'amount' => $amount, 'description' => $description]);
            if ($data['kind'] === 'saving') {
                foreach ($data['rows'] as $row) {
                    Saving::create(['cash_entry_id' => $cash->id, 'member_id' => $row['member_id'], 'type' => $data['type'], 'amount' => (int) $row['amount']]);
                }
            }
            if ($data['kind'] === 'loan') {
                $loan = Loan::create($loanDetails + ['member_id' => $data['member_id'], 'cash_entry_id' => $cash->id, 'disbursed_at' => $data['date']]);
                $part = intdiv($loan->total, $loan->months);
                $anchor = CarbonImmutable::parse($data['date']);
                for ($i = 1; $i <= $loan->months; $i++) {
                    $loan->installments()->create(['sequence' => $i, 'due_date' => $anchor->addMonthsNoOverflow($i)->toDateString(), 'amount' => $i === $loan->months ? $loan->total - $part * ($i - 1) : $part]);
                }
            }
            if ($data['kind'] === 'payment') {
                Payment::create(['installment_id' => $bill->id, 'cash_entry_id' => $cash->id, 'paid_at' => $data['date'], 'amount' => $amount]);
            }

            return $cash;
        }, 3);
    }

    private function member(int $id, Group $group, bool $active, string $field = 'member_id'): Member
    {
        $member = Member::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($member->group_id !== $group->id || ($active && ! $member->is_active)) {
            $this->fail($field, 'Pilih anggota aktif dari kelompok yang dipilih.');
        }

        return $member;
    }

    private function checkWithdrawal(Member $member, string $type, int $amount, string $date, string $field): void
    {
        $movements = Saving::query()->join('cash_entries', 'cash_entries.id', '=', 'savings.cash_entry_id')->where('member_id', $member->id)->where('type', $type)->select('cash_entries.date', 'cash_entries.direction', 'savings.amount', 'cash_entries.id')->orderBy('cash_entries.date')->orderBy('cash_entries.id')->get();
        $balance = 0;
        $applied = false;
        foreach ($movements as $movement) {
            if (! $applied && $movement->date > $date) {
                $balance -= $amount;
                if ($balance < 0) {
                    $this->fail($field, 'Saldo simpanan '.$type.' belum cukup pada tanggal penarikan.');
                }
                $applied = true;
            }
            $balance += $movement->direction === 'in' ? (int) $movement->amount : -(int) $movement->amount;
            if ($balance < 0) {
                $this->fail($field, 'Saldo simpanan '.$type.' tidak cukup pada tanggal ini atau akan membuat saldo riwayat berikutnya negatif.');
            }
        }
        if (! $applied) {
            $balance -= $amount;
        }
        if ($balance < 0) {
            $this->fail($field, 'Saldo simpanan '.$type.' tidak cukup untuk penarikan ini.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
