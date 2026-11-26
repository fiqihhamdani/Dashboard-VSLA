<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\FinancialRevision;
use App\Models\Group;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Saving;
use App\Models\User;
use App\Services\ExcelImport;
use App\Services\ReportService;
use App\Services\TransactionService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class FinancialCorrectionsTest extends TestCase
{
    public function test_payment_edit_immediately_updates_remaining_cash_ledger_and_reports(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $loan = $this->loan($admin, $member, 300000);
        $bill = Installment::firstOrFail();
        $data = $this->base($member, 'payment') + ['installment_id' => $bill->id, 'amount' => 100000];
        $cash = app(TransactionService::class)->store($admin, $data);
        $this->actingAs($admin)->get(route('admin.cash.edit', $cash))->assertOk()->assertSee('100000');

        $this->update($cash, $data, ['amount' => 150000])->assertRedirect(route('admin.cash.show', $cash));

        $this->assertSame(150000, $bill->remaining());
        $this->assertSame(150000, $cash->fresh()->amount);
        $this->assertSame(150000, Payment::firstOrFail()->amount);
        $report = $this->report();
        $this->assertSame(-150000, $report['report']['summary']['balance']);
        $this->assertSame(150000, $report['report']['summary']['payments']);
        $this->assertSame(150000, $report['ledger']->last()['in']);
        $this->assertSame(1, $cash->fresh()->version);
        $revision = FinancialRevision::firstOrFail();
        $this->assertSame(100000, $revision->before['cash']['amount']);
        $this->assertSame(150000, $revision->after['cash']['amount']);
        $this->assertSame($admin->id, $revision->admin_id);
        $this->get(route('admin.cash.show', $cash))->assertOk()->assertSee('Riwayat koreksi')->assertSee('Koreksi nominal');
        $this->assertDatabaseCount('cash_entries', 2);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_failed_payment_edit_rolls_back_payment_cash_version_and_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $this->loan($admin, $member, 100000);
        $bill = Installment::firstOrFail();
        $data = $this->base($member, 'payment') + ['installment_id' => $bill->id, 'amount' => 50000];
        $cash = app(TransactionService::class)->store($admin, $data);
        $this->actingAs($admin);

        $this->update($cash, $data, ['amount' => 100001])->assertSessionHasErrors('amount');

        $this->assertSame(50000, $cash->fresh()->amount);
        $this->assertSame(50000, $bill->remaining());
        $this->assertSame(50000, Payment::firstOrFail()->amount);
        $this->assertSame(0, $cash->fresh()->version);
        $this->assertDatabaseCount('financial_revisions', 0);
    }

    public function test_cancelling_payment_reopens_bill_and_excludes_cash_from_exports(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $this->loan($admin, $member, 100000);
        $bill = Installment::firstOrFail();
        $data = $this->base($member, 'payment') + ['installment_id' => $bill->id, 'amount' => 100000];
        $cash = app(TransactionService::class)->store($admin, $data);
        $this->actingAs($admin);
        $this->cancel($cash)->assertRedirect();

        $this->assertSoftDeleted($cash);
        $this->assertSame(0, Payment::count());
        $this->assertSame(100000, $bill->remaining());
        $this->assertSame(1, Member::forTransaction('payment')->count());
        $this->assertSame(-100000, $this->report()['report']['summary']['balance']);
        $this->assertSame(100000, $this->report()['report']['summary']['overdue']);
        $this->get(route('admin.cash.show', $cash))->assertOk()->assertSee('Transaksi dibatalkan')->assertDontSee('Edit transaksi');
        $this->get(route('admin.cash.edit', $cash))->assertNotFound();
        $this->get(route('admin.transactions.history', ['kind' => 'payment', 'state' => 'cancelled']))->assertOk()->assertViewHas('entries', fn ($entries) => $entries->total() === 1);
        $response = $this->get(route('admin.excel.export', ['module' => 'payment', 'scope' => 'all']))->assertOk();
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertSame(1, substr_count($zip->getFromName('xl/worksheets/sheet1.xml'), '<row '));
        $zip->close();
        $this->cancel($cash)->assertRedirect();
        $this->assertDatabaseCount('financial_revisions', 1);
        $this->post(route('admin.transactions.store'), $data)->assertRedirect();
        $this->assertSame(0, Payment::count());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cash_entries', 2);
    }

    public function test_loan_edit_keeps_paid_installment_ids_and_recalculates_original_formula(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $data = $this->base($member, 'loan') + ['principal' => 100000, 'rate' => '2', 'months' => 2];
        $cash = app(TransactionService::class)->store($admin, $data);
        $bill = Installment::orderBy('sequence')->firstOrFail();
        app(TransactionService::class)->store($admin, $this->base($member, 'payment') + ['installment_id' => $bill->id, 'amount' => 10000]);
        $this->actingAs($admin);
        $this->update($cash, $data, ['principal' => 200001, 'months' => 3])->assertRedirect();

        $loan = Loan::firstOrFail();
        $this->assertSame(12000, $loan->interest);
        $this->assertSame(212001, $loan->total);
        $this->assertSame([70667, 70667, 70667], $loan->installments()->orderBy('sequence')->pluck('amount')->all());
        $this->assertSame($bill->id, Payment::firstOrFail()->installment_id);
        $this->assertSame(60667, $bill->fresh()->remaining());
        $this->assertSame(-190001, $this->report()['report']['summary']['balance']);
    }

    public function test_loan_edit_rejects_paid_installment_reduction_and_date_after_payment(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $data = $this->base($member, 'loan') + ['principal' => 100000, 'rate' => '0', 'months' => 2];
        $cash = app(TransactionService::class)->store($admin, $data);
        $bill = Installment::where('sequence', 2)->firstOrFail();
        app(TransactionService::class)->store($admin, $this->base($member, 'payment') + ['installment_id' => $bill->id, 'amount' => 40000]);
        $this->actingAs($admin);
        $this->update($cash, $data, ['principal' => 60000])->assertSessionHasErrors('principal');
        $this->update($cash, $data, ['months' => 1])->assertSessionHasErrors('principal');
        $this->update($cash, $data, ['date' => '2026-11-21'])->assertSessionHasErrors('date');
        $this->assertSame(100000, Loan::firstOrFail()->total);
        $this->assertSame(100000, $cash->fresh()->amount);
        $this->assertSame(40000, Payment::firstOrFail()->amount);
        $this->assertDatabaseCount('financial_revisions', 0);
    }

    public function test_loan_cancellation_requires_payments_cancelled_first_and_removes_open_bills(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $loanCash = $this->loan($admin, $member, 100000);
        $bill = Installment::firstOrFail();
        $payment = app(TransactionService::class)->store($admin, $this->base($member, 'payment') + ['installment_id' => $bill->id, 'amount' => 10000]);
        $this->actingAs($admin);
        $this->cancel($loanCash)->assertSessionHasErrors('cash');
        $this->assertSame(1, Loan::count());
        $this->cancel($payment)->assertRedirect();
        $this->cancel($loanCash)->assertRedirect();

        $this->assertSame(0, Loan::count());
        $this->assertSame(0, Installment::count());
        $this->assertSame(0, $this->report()['report']['summary']['overdue']);
        $this->assertSame(0, $this->report()['report']['summary']['balance']);
        $this->assertSame(0, Member::forTransaction('payment')->count());
        $this->assertDatabaseCount('loans', 1);
        $this->assertDatabaseCount('installments', 1);
        $this->delete(route('admin.members.destroy', $member))->assertSessionHasErrors('member');
    }

    public function test_saving_corrections_cannot_remove_funds_used_by_later_withdrawals(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $data = $this->base($member, 'saving') + ['type' => 'wajib', 'direction' => 'in', 'rows' => [['member_id' => $member->id, 'amount' => 10000]]];
        $deposit = app(TransactionService::class)->store($admin, $data);
        app(TransactionService::class)->store($admin, array_replace($this->base($member, 'saving'), ['date' => '2026-11-21', 'type' => 'wajib', 'direction' => 'out', 'rows' => [['member_id' => $member->id, 'amount' => 8000]]]));
        $this->actingAs($admin);
        $this->update($deposit, $data, ['rows' => [['member_id' => $member->id, 'amount' => 7000]]])->assertSessionHasErrors('rows');
        $this->update($deposit, $data, ['date' => '2026-11-22'])->assertSessionHasErrors('rows');
        $this->cancel($deposit)->assertSessionHasErrors('rows');
        $this->assertSame(10000, $deposit->fresh()->amount);
        $this->assertSame(2, Saving::count());
        $this->assertSame(2000, $this->report()['report']['summary']['savings']);
        $this->assertDatabaseCount('financial_revisions', 0);
        $this->update($deposit, $data, ['rows' => [['member_id' => $member->id, 'amount' => 12000]]])->assertRedirect();
        $this->assertSame(4000, $this->report()['report']['summary']['savings']);
        $this->assertSame(2, Saving::count());
        $this->assertDatabaseCount('savings', 3);
    }

    public function test_saving_withdrawal_cancel_restores_balance_and_new_withdrawal_ignores_cancelled_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $service = app(TransactionService::class);
        $service->store($admin, $this->base($member, 'saving') + ['type' => 'pokok', 'direction' => 'in', 'rows' => [['member_id' => $member->id, 'amount' => 10000]]]);
        $cash = $service->store($admin, $this->base($member, 'saving') + ['type' => 'pokok', 'direction' => 'out', 'rows' => [['member_id' => $member->id, 'amount' => 7000]]]);
        $this->actingAs($admin);
        $this->cancel($cash)->assertRedirect();
        $this->assertSame(10000, $this->report()['report']['summary']['savings']);
        $service->store($admin, $this->base($member, 'saving') + ['type' => 'pokok', 'direction' => 'out', 'rows' => [['member_id' => $member->id, 'amount' => 10000]]]);
        $this->assertSame(0, $this->report()['report']['summary']['savings']);
    }

    public function test_other_cash_edit_moves_period_and_direction_and_cancel_removes_effect(): void
    {
        $admin = User::factory()->admin()->create();
        $group = Group::factory()->create();
        $data = ['kind' => 'other', 'request_key' => (string) Str::uuid(), 'group_id' => $group->id, 'date' => '2026-11-20', 'amount' => 10000, 'direction' => 'in', 'description' => 'Awal'];
        $cash = app(TransactionService::class)->store($admin, $data);
        $this->actingAs($admin);
        $this->get(route('admin.cash.edit', $cash))->assertOk()->assertSee('Awal');
        $this->update($cash, $data, ['date' => '2026-12-01', 'direction' => 'out', 'amount' => 20000, 'description' => 'Koreksi ATK'])->assertRedirect();
        $report = $this->report();
        $this->assertSame(-20000, $report['report']['summary']['balance']);
        $this->assertSame(20000, $report['ledger']->first()['out']);
        $this->assertSame('Koreksi ATK', $report['ledger']->first()['description']);
        $this->cancel($cash->fresh())->assertRedirect();
        $this->assertSame(0, $this->report()['report']['summary']['balance']);
        $this->assertDatabaseCount('financial_revisions', 2);
    }

    public function test_stale_forms_cannot_overwrite_newer_changes_and_reason_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $data = $this->base($member, 'loan') + ['principal' => 100000, 'rate' => '0', 'months' => 1];
        $cash = app(TransactionService::class)->store($admin, $data);
        $this->actingAs($admin);
        $this->put(route('admin.cash.update', $cash), $data + ['version' => 0])->assertSessionHasErrors('reason');
        $this->delete(route('admin.cash.cancel', $cash), ['version' => 0, 'reason' => 'Pembatalan'])->assertSessionHasErrors('confirmed');
        $this->update($cash, $data, ['principal' => 150000])->assertRedirect();
        $this->update($cash, $data, ['principal' => 200000])->assertSessionHasErrors('version');
        $this->cancel($cash)->assertSessionHasErrors('version');
        $this->assertSame(150000, $cash->fresh()->amount);
        $this->assertDatabaseCount('financial_revisions', 1);
    }

    public function test_financial_correction_endpoints_reject_non_admin_and_kind_switch(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $cash = $this->loan($admin, $member, 100000);
        $this->get(route('admin.cash.edit', $cash))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get(route('admin.cash.edit', $cash))->assertForbidden();
        $this->put(route('admin.cash.update', $cash), [])->assertForbidden();
        $this->cancel($cash)->assertForbidden();
        $this->actingAs($admin);
        $data = $this->base($member, 'other') + ['amount' => 100, 'direction' => 'in', 'description' => 'Tidak boleh'];
        $this->update($cash, $data)->assertSessionHasErrors('kind');
        $this->assertSame('loan', $cash->fresh()->category);
    }

    public function test_shrinking_and_expanding_unpaid_schedule_reuses_archived_sequences(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $data = $this->base($member, 'loan') + ['principal' => 100001, 'rate' => '0', 'months' => 3];
        $cash = app(TransactionService::class)->store($admin, $data);
        $ids = Installment::orderBy('sequence')->pluck('id')->all();
        $this->actingAs($admin);
        $this->update($cash, $data, ['months' => 1])->assertRedirect();
        $this->assertSame(1, Installment::count());
        $this->update($cash->fresh(), $data, ['months' => 3])->assertRedirect();
        $this->assertSame($ids, Installment::orderBy('sequence')->pluck('id')->all());
        $this->assertSame([33333, 33333, 33335], Installment::orderBy('sequence')->pluck('amount')->all());
        $this->assertDatabaseCount('installments', 3);
    }

    public function test_audit_failure_rolls_back_the_entire_financial_correction(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $cash = $this->loan($admin, $member, 100000);
        FinancialRevision::creating(function (): void {
            throw new \RuntimeException('Simulasi audit gagal');
        });
        try {
            app(TransactionService::class)->revise($admin, $cash, $this->base($member, 'loan') + ['principal' => 200000, 'rate' => '0', 'months' => 2], 'Koreksi uji', 0);
            $this->fail('Koreksi seharusnya gagal ketika audit gagal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi audit gagal', $exception->getMessage());
        } finally {
            FinancialRevision::flushEventListeners();
        }
        $this->assertSame(100000, $cash->fresh()->amount);
        $this->assertSame(0, $cash->fresh()->version);
        $this->assertSame(100000, Loan::firstOrFail()->total);
        $this->assertSame(1, Installment::count());
        $this->assertDatabaseCount('financial_revisions', 0);
    }

    public function test_editing_paid_in_full_payment_keeps_its_bill_available_and_other_payments_counted(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $this->loan($admin, $member, 100000);
        $bill = Installment::firstOrFail();
        $data = $this->base($member, 'payment') + ['installment_id' => $bill->id, 'amount' => 60000];
        $cash = app(TransactionService::class)->store($admin, $data);
        app(TransactionService::class)->store($admin, $this->base($member, 'payment') + ['installment_id' => $bill->id, 'amount' => 40000]);
        $this->actingAs($admin);
        $this->get(route('admin.cash.edit', $cash))->assertOk()->assertSee('Simpan koreksi')->assertViewHas('bills', fn ($bills) => $bills->contains('id', $bill->id));
        $this->update($cash, $data, ['amount' => 70000])->assertSessionHasErrors('amount');
        $this->update($cash, $data, ['amount' => 50000])->assertRedirect();
        $this->assertSame(10000, $bill->remaining());
        $this->assertSame(90000, (int) Payment::sum('amount'));
    }

    public function test_cancelled_transaction_request_key_is_still_rejected_by_import(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $data = $this->base($member, 'other') + ['direction' => 'in', 'amount' => 10000, 'description' => 'Kas'];
        $cash = app(TransactionService::class)->store($admin, $data);
        app(TransactionService::class)->cancel($admin, $cash, 'Pembatalan uji', 0);
        $rows = [['number' => 2, 'data' => ['kode_transaksi' => $data['request_key'], 'kelompok_id' => $member->group_id, 'tanggal' => '2026-11-20', 'arah' => 'in', 'nominal' => 10000, 'uraian' => 'Kas']]];
        $errors = app(ExcelImport::class)->preview('other', $rows, $admin);
        $this->assertArrayHasKey(2, $errors);
        $this->assertSame(0, CashEntry::count());
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_original_form_replay_after_edit_or_cancellation_never_overwrites_the_correction(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $data = $this->base($member, 'loan') + ['principal' => 100000, 'rate' => '0', 'months' => 1];
        $this->actingAs($admin)->post(route('admin.transactions.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $cash = CashEntry::firstOrFail();
        $this->update($cash, $data, ['principal' => 150000])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('admin.transactions.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(150000, $cash->fresh()->amount);
        $this->cancel($cash->fresh())->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('admin.transactions.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('admin.cash.show', $cash));
        $this->assertSame(0, CashEntry::count());
        $this->assertDatabaseCount('cash_entries', 1);
        $this->assertDatabaseCount('financial_revisions', 2);
    }

    private function base(Member $member, string $kind): array
    {
        return ['kind' => $kind, 'request_key' => (string) Str::uuid(), 'group_id' => $member->group_id, 'member_id' => $member->id, 'date' => '2026-11-20'];
    }

    private function loan(User $admin, Member $member, int $principal): CashEntry
    {
        return app(TransactionService::class)->store($admin, $this->base($member, 'loan') + ['principal' => $principal, 'rate' => '0', 'months' => 1]);
    }

    private function update(CashEntry $cash, array $data, array $overrides = []): TestResponse
    {
        return $this->put(route('admin.cash.update', $cash), array_replace($data, ['version' => $cash->version, 'reason' => 'Koreksi nominal'], $overrides));
    }

    private function cancel(CashEntry $cash): TestResponse
    {
        return $this->delete(route('admin.cash.cancel', $cash), ['version' => $cash->version, 'reason' => 'Pembatalan transaksi salah', 'confirmed' => 1]);
    }

    private function report(): array
    {
        return app(ReportService::class)->exportData(['start' => '2026-01-01', 'end' => '2026-12-31']);
    }
}
