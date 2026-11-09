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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function form(Request $request, string $kind): View
    {
        abort_unless(in_array($kind, ['saving', 'loan', 'payment', 'other'], true), 404);
        $request->validate(['group_id' => 'nullable|integer|exists:groups,id', 'member_id' => 'nullable|integer|exists:members,id']);
        $groupId = $request->old('group_id', $request->input('group_id'));
        $memberId = $request->old('member_id', $request->input('member_id'));
        $members = Member::with('group')->where('group_id', $groupId)->when($kind !== 'payment', fn ($q) => $q->where('is_active', true))->orderBy('name')->orderBy('id')->get();
        $bills = Installment::with(['loan', 'payments'])->whereHas('loan', fn ($q) => $q->where('member_id', $memberId)->whereHas('member', fn ($m) => $m->where('group_id', $groupId)))->orderBy('due_date')->orderBy('id')->get()->filter(fn ($b) => $b->amount > $b->payments->sum('amount'));

        return view('admin.transaction', compact('kind', 'groupId', 'memberId', 'members', 'bills') + ['groups' => Group::orderBy('name')->get(), 'requestKey' => $request->old('request_key', (string) Str::uuid())]);
    }

    public function store(TransactionRequest $request, TransactionService $service): RedirectResponse
    {
        $cash = $service->store($request->user(), $request->validated());

        return redirect()->route('admin.cash.show', $cash)->with('status', 'Transaksi tersimpan. Pengiriman ulang formulir yang sama tidak akan membuat transaksi ganda.');
    }

    public function show(CashEntry $cash): View
    {
        $cash->load(['group', 'savings.member']);

        return view('admin.receipt', compact('cash') + ['loan' => Loan::with(['member', 'installments' => fn ($q) => $q->withSum('payments', 'amount')->orderBy('sequence')])->where('cash_entry_id', $cash->id)->first(), 'payment' => Payment::with('installment.loan.member')->where('cash_entry_id', $cash->id)->first()]);
    }
}
