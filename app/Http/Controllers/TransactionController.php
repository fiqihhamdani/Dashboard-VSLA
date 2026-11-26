<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionRequest;
use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Payment;
use App\Rules\GoogleDriveUrl;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function form(Request $request, string $kind): View
    {
        abort_unless(in_array($kind, ['saving', 'loan', 'payment', 'other'], true), 404);
        $request->validate(['group_id' => 'nullable|integer|exists:groups,id', 'member_id' => 'nullable|integer|exists:members,id']);
        $groupId = $request->old('group_id', $request->input('group_id'));
        $memberId = $request->old('member_id', $request->input('member_id'));
        $groupId = is_scalar($groupId) && ctype_digit((string) $groupId) ? (int) $groupId : null;
        $memberId = is_scalar($memberId) && ctype_digit((string) $memberId) ? (int) $memberId : null;
        $eligible = Member::with('group')->where('group_id', $groupId)->forTransaction($kind);
        $members = (clone $eligible)->orderBy('name')->orderBy('id')->limit(20)->get();
        $selectedIds = collect($request->old('rows', []))->filter(fn ($row) => is_array($row))
            ->pluck('member_id')->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id))->take(100)->push($memberId)->filter();
        $selectedMembers = (clone $eligible)->whereIn('id', $selectedIds)->get()->keyBy('id');
        if (! $selectedMembers->has($memberId)) {
            $memberId = null;
        }
        $bills = Installment::with('loan')->withSum('payments', 'amount')->open()
            ->whereHas('loan', fn ($q) => $q->where('member_id', $memberId))
            ->orderBy('due_date')->orderBy('id')->get();

        return view('admin.transaction', compact('kind', 'groupId', 'memberId', 'members', 'selectedMembers', 'bills') + ['groups' => Group::orderBy('name')->get(), 'requestKey' => $request->old('request_key', (string) Str::uuid())]);
    }

    public function members(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => 'required|integer|exists:groups,id',
            'kind' => ['required', Rule::in(['saving', 'loan', 'payment'])],
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $members = Member::with('group')->where('group_id', $data['group_id'])->forTransaction($data['kind'])
            ->when($data['q'] ?? null, fn ($q, $name) => $q->where('name', 'like', "%$name%"))
            ->orderBy('name')->orderBy('id')->simplePaginate(20);

        return response()->json(['data' => $members->map(fn ($member) => [
            'id' => $member->id, 'group_id' => $member->group_id,
            'label' => $member->name.' · '.$member->group->name.' · '.$member->group->code.' · #'.$member->id,
        ])->values(), 'has_more' => $members->hasMorePages()]);
    }

    public function deposits(CashEntry $cash): JsonResponse
    {
        $cash->load(['group', 'savings.member']);
        $total = (int) $cash->savings->sum('amount');

        return response()->json([
            'id' => $cash->id, 'date' => $cash->date->format('d/m/Y'), 'group' => $cash->group->name,
            'direction' => $cash->direction === 'in' ? 'Kas masuk' : 'Kas keluar', 'description' => $cash->description,
            'member_count' => $cash->savings->pluck('member_id')->unique()->count(),
            'rows' => $cash->savings->map(fn ($saving) => [
                'id' => $saving->id, 'member_id' => $saving->member_id, 'member' => $saving->member->name,
                'type' => ucfirst($saving->type), 'amount' => $saving->amount,
            ])->values(),
            'total' => $total, 'cash_total' => $cash->amount, 'matches' => $total === $cash->amount,
        ]);
    }

    public function store(TransactionRequest $request, TransactionService $service): RedirectResponse
    {
        $cash = $service->store($request->user(), $request->validated());

        if ($cash->trashed()) {
            return redirect()->route('admin.cash.show', $cash)->with('status', 'Transaksi ini sudah dibatalkan. Pengiriman ulang tidak membuat catatan keuangan baru.');
        }

        if ($cash->category === 'payment') {
            $member = Member::whereKey($request->integer('member_id'))->forTransaction('payment')->first();

            return redirect()->route('admin.transactions.create', array_filter(['kind' => 'payment', 'group_id' => $cash->group_id, 'member_id' => $member?->id]))
                ->with('status', 'Pembayaran berhasil disimpan. Pilihan anggota dan sisa tagihan telah diperbarui.')->with('receipt_id', $cash->id);
        }

        return redirect()->route('admin.cash.show', $cash)->with('status', 'Transaksi tersimpan. Pengiriman ulang formulir yang sama tidak akan membuat transaksi ganda.');
    }

    public function show(CashEntry $cash): View
    {
        $cash->load(['group', 'savings.member', 'revisions' => fn ($q) => $q->with('admin')->orderByDesc('id')]);

        return view('admin.receipt', compact('cash') + ['loan' => Loan::with(['member', 'installments' => fn ($q) => $q->withSum('payments', 'amount')->orderBy('sequence')])->where('cash_entry_id', $cash->id)->first(), 'payment' => Payment::with('installment.loan.member')->where('cash_entry_id', $cash->id)->first()]);
    }

    public function history(Request $request, string $kind): View
    {
        abort_unless(in_array($kind, ['saving', 'loan', 'payment', 'other'], true), 404);
        $data = $request->validate(['group_id' => 'nullable|integer|exists:groups,id', 'q' => 'nullable|string|max:100', 'start' => 'nullable|date_format:Y-m-d', 'end' => $request->filled('start') ? 'nullable|date_format:Y-m-d|after_or_equal:start' : 'nullable|date_format:Y-m-d']);
        $request->validate(['state' => 'nullable|in:active,all,cancelled']);
        $entries = CashEntry::with('group')->when($request->input('state') === 'all', fn ($q) => $q->withTrashed())->when($request->input('state') === 'cancelled', fn ($q) => $q->onlyTrashed())->where('category', $kind)
            ->when($data['group_id'] ?? null, fn ($query, $id) => $query->where('group_id', $id))
            ->when($data['q'] ?? null, fn ($query, $q) => $query->where('description', 'like', "%$q%"))
            ->when($data['start'] ?? null, fn ($query, $date) => $query->where('date', '>=', $date))
            ->when($data['end'] ?? null, fn ($query, $date) => $query->where('date', '<=', $date))
            ->orderByDesc('date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.history', compact('entries', 'kind') + ['groups' => Group::orderBy('name')->get()]);
    }

    public function edit(Request $request, CashEntry $cash): View
    {
        if ($request->boolean('fresh')) {
            $request->session()->forget('_old_input');
        }
        $kind = $cash->category;
        $cash->load('savings.member');
        $loan = Loan::where('cash_entry_id', $cash->id)->first();
        $payment = Payment::with('installment.loan')->where('cash_entry_id', $cash->id)->first();
        $defaults = ['date' => $cash->date->toDateString(), 'direction' => $cash->direction, 'amount' => $cash->amount, 'description' => $cash->description, 'proof_url' => $cash->proof_url];
        if ($loan) {
            $defaults += ['member_id' => $loan->member_id, 'principal' => $loan->principal, 'rate' => number_format($loan->rate_bps / 100, 2, '.', ''), 'months' => $loan->months];
        }
        if ($payment) {
            $defaults += ['member_id' => $payment->installment->loan->member_id, 'installment_id' => $payment->installment_id];
        }
        if ($kind === 'saving') {
            $defaults += ['type' => $cash->savings->first()?->type, 'rows' => $cash->savings->map(fn ($saving) => ['member_id' => $saving->member_id, 'amount' => $saving->amount])->all()];
        }
        $groupId = $cash->group_id;
        $memberId = $defaults['member_id'] ?? null;
        $rows = $request->old('rows', $defaults['rows'] ?? []);
        $ids = collect(is_array($rows) ? $rows : [])->filter(is_array(...))->pluck('member_id')->push($request->old('member_id', $memberId))->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id))->take(101);
        $selectedMembers = Member::with('group')->whereIn('id', $ids)->get()->keyBy('id');
        $members = Member::where('group_id', $groupId)->orderBy('name')->limit(20)->get();
        $bills = Installment::with('loan')->withSum('payments', 'amount')->whereHas('loan', fn ($q) => $q->where('member_id', $memberId))->orderBy('due_date')->orderBy('id')->get();

        return view('admin.transaction', compact('kind', 'groupId', 'memberId', 'selectedMembers', 'members', 'bills', 'defaults') + ['editing' => $cash, 'groups' => Group::orderBy('name')->get(), 'requestKey' => $cash->request_key]);
    }

    public function update(TransactionRequest $request, CashEntry $cash, TransactionService $service): RedirectResponse
    {
        $meta = $request->validate(['reason' => 'required|string|min:3|max:1000', 'version' => 'required|integer|min:0']);
        $service->revise($request->user(), $cash, $request->validated(), $meta['reason'], (int) $meta['version']);

        return redirect()->route('admin.cash.show', $cash)->with('status', 'Koreksi disimpan. Saldo, tagihan, buku kas, dan laporan sudah mengikuti nilai terbaru.');
    }

    public function cancel(Request $request, CashEntry $cash, TransactionService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000', 'version' => 'required|integer|min:0', 'confirmed' => 'accepted']);
        $service->cancel($request->user(), $cash, $data['reason'], (int) $data['version']);

        return redirect()->route('admin.cash.show', $cash)->with('status', 'Transaksi dibatalkan. Nilainya dikeluarkan dari perhitungan keuangan; riwayat tetap tersimpan.');
    }

    public function proof(Request $request, CashEntry $cash, TransactionService $service): RedirectResponse
    {
        abort_unless($cash->category === 'loan', 404);
        $data = $request->validate(['proof_url' => ['present', 'nullable', 'string', 'max:2048', new GoogleDriveUrl]]);
        $service->changeProof($request->user(), $cash, $data['proof_url']);

        return redirect()->route('admin.cash.show', $cash)->with('status', 'Tautan bukti pencairan diperbarui. File Google Drive tidak diubah atau dihapus.');
    }
}
