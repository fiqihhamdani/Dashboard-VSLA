<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionRequest;
use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Payment;
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

        if ($cash->category === 'payment') {
            $member = Member::whereKey($request->integer('member_id'))->forTransaction('payment')->first();

            return redirect()->route('admin.transactions.create', array_filter(['kind' => 'payment', 'group_id' => $cash->group_id, 'member_id' => $member?->id]))
                ->with('status', 'Pembayaran berhasil disimpan. Pilihan anggota dan sisa tagihan telah diperbarui.')->with('receipt_id', $cash->id);
        }

        return redirect()->route('admin.cash.show', $cash)->with('status', 'Transaksi tersimpan. Pengiriman ulang formulir yang sama tidak akan membuat transaksi ganda.');
    }

    public function show(CashEntry $cash): View
    {
        $cash->load(['group', 'savings.member']);

        return view('admin.receipt', compact('cash') + ['loan' => Loan::with(['member', 'installments' => fn ($q) => $q->withSum('payments', 'amount')->orderBy('sequence')])->where('cash_entry_id', $cash->id)->first(), 'payment' => Payment::with('installment.loan.member')->where('cash_entry_id', $cash->id)->first()]);
    }
}
