<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class VslaTest extends TestCase
{
    private function setupGroup(): array
    {
        $admin = User::factory()->admin()->create();
        $group = Group::factory()->create();
        $members = Member::factory()->count(2)->for($group)->create();
        $this->actingAs($admin);

        return [$admin, $group, $members];
    }

    private function data(Group $group, string $kind, string $date, array $extra = []): array
    {
        return ['kind' => $kind, 'request_key' => (string) Str::uuid(), 'group_id' => $group->id, 'date' => $date] + $extra;
    }

    private function record(array $data): void
    {
        $this->post(route('admin.transactions.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
    }

    private function scenario(): array
    {
        [$admin,$group,$members] = $this->setupGroup();
        $deposit = $this->data($group, 'saving', '2026-09-01', ['direction' => 'in', 'type' => 'sukarela', 'rows' => [['member_id' => $members[0]->id, 'amount' => 100000], ['member_id' => $members[1]->id, 'amount' => 100000]]]);
        $this->record($deposit);
        $this->record($this->data($group, 'loan', '2026-09-10', ['member_id' => $members[0]->id, 'principal' => 100000, 'rate' => '10.00', 'months' => 1]));
        $bill = Installment::firstOrFail();
        $payment = $this->data($group, 'payment', '2026-10-10', ['member_id' => $members[0]->id, 'installment_id' => $bill->id, 'amount' => 40000]);
        $this->record($payment);

        return [$admin, $group, $members, $deposit, $payment, $bill];
    }

    public function test_empty_dashboard_has_zero_totals_and_no_operational_seed_data(): void
    {
        $this->get('/')->assertOk()->assertSee('Belum ada transaksi')->assertViewHas('report', fn ($r) => $r['summary']['balance'] === 0 && $r['summary']['overdue'] === 0 && $r['summary']['savings'] === 0 && ! $r['has_transactions']);
        foreach (['groups', 'members', 'cash_entries', 'savings', 'loans', 'installments', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_requested_scenario_reconciles_dashboard_cash_and_real_excel(): void
    {
        [$admin,$group,$members,$deposit,$payment,$bill] = $this->scenario();
        $filter = ['group_id' => $group->id, 'start' => '2026-09-01', 'end' => '2026-10-31'];
        $this->assertDatabaseCount('cash_entries', 3);
        $this->assertDatabaseCount('savings', 2);
        $this->assertDatabaseHas('cash_entries', ['category' => 'saving', 'amount' => 200000, 'direction' => 'in']);
        $this->assertDatabaseHas('installments', ['due_date' => '2026-10-10', 'amount' => 110000]);
        $this->assertSame(70000, $bill->remaining());
        $this->get(route('dashboard', $filter))->assertOk()->assertViewHas('report', function ($r) {
            $this->assertSame(['opening' => 0, 'balance' => 140000, 'savings' => 200000, 'loans' => 100000, 'payments' => 40000, 'overdue' => 70000, 'active_members' => 2], $r['summary']);
            $this->assertSame([100000, 140000], array_column($r['months'], 'balance'));

            return true;
        });
        $response = $this->get(route('admin.report', $filter))->assertOk()->assertDownload('VSLA-2026-09-01-2026-10-31.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $this->assertSame('140000', (string) $xml->xpath('//s:row[s:c/s:is/s:t="Saldo kas akhir"]/s:c[2]/s:v')[0]);
        $this->assertSame('70000', (string) $xml->xpath('//s:row[s:c/s:is/s:t="Tunggakan jatuh tempo"]/s:c[2]/s:v')[0]);
        $this->assertStringContainsString('&quot;Rp&quot;', $zip->getFromName('xl/styles.xml'));
        $monthly = $zip->getFromName('xl/worksheets/sheet2.xml');
        $this->assertStringContainsString('<v>100000</v>', $monthly);
        $this->assertStringContainsString('<v>140000</v>', $monthly);
        $cash = $zip->getFromName('xl/worksheets/sheet3.xml');
        $this->assertStringContainsString('<v>200000</v>', $cash);
        $zip->close();
        unlink($path);
        $this->post('/logout')->assertRedirect();
        $public = $this->get(route('dashboard', $filter))->assertOk();
        $public->assertDontSee($members[0]->name)->assertDontSee($members[1]->name)->assertDontSee('Pembayaran angsuran ke-1');
    }

    public function test_resending_each_transaction_keeps_cash_and_details_unchanged(): void
    {
        [$admin,$group,$members,$deposit,$payment] = $this->scenario();
        $this->record($deposit);
        $this->record($payment);
        $this->assertDatabaseCount('cash_entries', 3);
        $this->assertDatabaseCount('savings', 2);
        $this->assertDatabaseCount('payments', 1);
        $payment['amount'] = 10000;
        $this->post(route('admin.transactions.store'), $payment)->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('cash_entries', 3);
    }

    public function test_overpayment_and_wrong_member_leave_all_records_unchanged(): void
    {
        [$admin,$group,$members,$deposit,$payment] = $this->scenario();
        $payment['request_key'] = (string) Str::uuid();
        $payment['amount'] = 70001;
        $this->post(route('admin.transactions.store'), $payment)->assertSessionHasErrors('amount');
        $payment['amount'] = 1000;
        $payment['member_id'] = $members[1]->id;
        $this->post(route('admin.transactions.store'), $payment)->assertSessionHasErrors('installment_id');
        $this->assertDatabaseCount('cash_entries', 3);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_historical_arrears_ignore_payments_after_end_date(): void
    {
        [$admin,$group,$members,$deposit,$payment,$bill] = $this->scenario();
        $this->record($this->data($group, 'payment', '2026-11-01', ['member_id' => $members[0]->id, 'installment_id' => $bill->id, 'amount' => 70000]));
        $this->get('/?start=2026-09-01&end=2026-10-31')->assertOk()->assertViewHas('report', fn ($r) => $r['summary']['overdue'] === 70000 && $r['summary']['balance'] === 140000);
        $this->assertSame(0, $bill->remaining());
        $this->get('/?start=2026-09-01&end=2026-11-30')->assertViewHas('report', fn ($r) => $r['summary']['overdue'] === 0 && $r['summary']['balance'] === 210000);
    }

    public function test_filters_handle_opening_balance_partial_months_and_inactive_months(): void
    {
        [$admin,$one] = $this->setupGroup();
        $two = Group::factory()->create();
        foreach ([[$one, '2026-08-10', 50000], [$one, '2026-09-10', 10000], [$one, '2026-09-20', 20000], [$one, '2026-12-20', 100000], [$two, '2026-09-20', 900000]] as [$g,$date,$amount]) {
            $this->record($this->data($g, 'other', $date, ['direction' => 'in', 'amount' => $amount, 'description' => 'Kas uji privat']));
        }
        $this->get(route('dashboard', ['group_id' => $one->id, 'start' => '2026-09-15', 'end' => '2026-12-10']))->assertOk()->assertViewHas('report', function ($r) {
            $this->assertSame(60000, $r['summary']['opening']);
            $this->assertSame(80000, $r['summary']['balance']);
            $this->assertSame([20000, 0, 0, 0], array_column($r['months'], 'in'));
            $this->assertSame([80000, 80000, 80000, 80000], array_column($r['months'], 'balance'));

            return true;
        });
        $this->get('/?start=2026-09-15&end=2026-12-10')->assertViewHas('report', fn ($r) => $r['summary']['balance'] === 980000);
    }

    public function test_month_end_schedule_and_rounding_reconcile_exactly(): void
    {
        [$admin,$group,$members] = $this->setupGroup();
        $data = $this->data($group, 'loan', '2026-01-31', ['member_id' => $members[0]->id, 'principal' => 100001, 'rate' => '1.25', 'months' => 3]);
        $this->record($data);
        $this->record($data);
        $loan = Loan::firstOrFail();
        $this->assertSame(3750, $loan->interest);
        $this->assertSame(103751, $loan->total);
        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30'], $loan->installments->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->assertSame([34583, 34583, 34585], $loan->installments->pluck('amount')->all());
        $this->assertDatabaseCount('cash_entries', 1);
        $this->assertDatabaseCount('loans', 1);
    }

    public function test_withdrawal_checks_type_balance_and_rolls_back_entire_batch(): void
    {
        [$admin,$group,$members] = $this->setupGroup();
        $this->record($this->data($group, 'saving', '2026-09-01', ['direction' => 'in', 'type' => 'wajib', 'rows' => [['member_id' => $members[0]->id, 'amount' => 100000]]]));
        $withdraw = $this->data($group, 'saving', '2026-09-02', ['direction' => 'out', 'type' => 'wajib', 'rows' => [['member_id' => $members[0]->id, 'amount' => 50000], ['member_id' => $members[1]->id, 'amount' => 1]]]);
        $this->post(route('admin.transactions.store'), $withdraw)->assertSessionHasErrors('rows.1.amount');
        $this->assertDatabaseCount('cash_entries', 1);
        $this->assertDatabaseCount('savings', 1);
        array_pop($withdraw['rows']);
        $this->record($withdraw);
        $this->assertDatabaseHas('cash_entries', ['direction' => 'out', 'amount' => 50000]);
        $withdraw['request_key'] = (string) Str::uuid();
        $withdraw['type'] = 'sukarela';
        $this->post(route('admin.transactions.store'), $withdraw)->assertSessionHasErrors('rows.0.amount');
    }

    public function test_backdated_withdrawal_cannot_make_future_balance_negative(): void
    {
        [$admin,$group,$members] = $this->setupGroup();
        foreach ([['2026-09-01', 'in', 100000], ['2026-10-01', 'out', 100000]] as [$date,$direction,$amount]) {
            $this->record($this->data($group, 'saving', $date, ['direction' => $direction, 'type' => 'pokok', 'rows' => [['member_id' => $members[0]->id, 'amount' => $amount]]]));
        }
        $this->post(route('admin.transactions.store'), $this->data($group, 'saving', '2026-09-15', ['direction' => 'out', 'type' => 'pokok', 'rows' => [['member_id' => $members[0]->id, 'amount' => 1]]]))->assertSessionHasErrors('rows.0.amount');
        $this->assertDatabaseCount('cash_entries', 2);
    }

    public function test_admin_routes_require_login_and_an_admin_role(): void
    {
        foreach (['/admin', '/admin/kelompok', '/admin/anggota', '/admin/transaksi/saving', '/admin/buku-kas', '/admin/laporan.xlsx', '/admin/kas/1'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        foreach (['/admin/transaksi', '/admin/kelompok', '/admin/anggota'] as $url) {
            $this->post($url, [])->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create());
        foreach (['/admin', '/admin/kelompok', '/admin/anggota', '/admin/transaksi/saving', '/admin/buku-kas', '/admin/laporan.xlsx'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/transaksi', [])->assertForbidden();
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_group_member_creation_search_and_history_safe_editing(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/admin/kelompok', ['name' => 'Melati', 'code' => 'MLT', 'location' => 'Bogor', 'is_active' => 1])->assertSessionHasNoErrors();
        $group = Group::firstOrFail();
        $this->post('/admin/anggota', ['group_id' => $group->id, 'name' => 'Siti <script>alert(1)</script>', 'is_active' => 1])->assertSessionHasNoErrors();
        $member = Member::firstOrFail();
        $this->get('/admin/anggota?q=Siti')->assertOk()->assertSee('Siti &lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->record($this->data($group, 'saving', '2026-09-01', ['direction' => 'in', 'type' => 'pokok', 'rows' => [['member_id' => $member->id, 'amount' => 10000]]]));
        $other = Group::factory()->create();
        $this->put('/admin/anggota/'.$member->id, ['group_id' => $other->id, 'name' => 'Siti', 'is_active' => 1])->assertSessionHasErrors('group_id');
        $this->put('/admin/anggota/'.$member->id, ['group_id' => $group->id, 'name' => 'Siti', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('members', ['id' => $member->id, 'group_id' => $group->id, 'is_active' => 0]);
        $this->assertDatabaseCount('savings', 1);
    }

    public function test_wrong_group_inactive_member_duplicate_rows_and_fractional_rupiah_are_rejected(): void
    {
        [$admin,$group,$members] = $this->setupGroup();
        $other = Group::factory()->create();
        $data = $this->data($other, 'loan', '2026-09-10', ['member_id' => $members[0]->id, 'principal' => 100000, 'rate' => '0', 'months' => 1]);
        $this->post('/admin/transaksi', $data)->assertSessionHasErrors('member_id');
        $members[0]->update(['is_active' => false]);
        $data['group_id'] = $group->id;
        $this->post('/admin/transaksi', $data)->assertSessionHasErrors('member_id');
        $data = $this->data($group, 'saving', '2026-09-01', ['type' => 'pokok', 'direction' => 'in', 'rows' => [['member_id' => $members[1]->id, 'amount' => 10], ['member_id' => $members[1]->id, 'amount' => 10]]]);
        $this->post('/admin/transaksi', $data)->assertSessionHasErrors('rows.0.member_id');
        $this->post('/admin/transaksi', $this->data($group, 'other', '2026-09-01', ['direction' => 'in', 'amount' => '1.5', 'description' => 'Uji']))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_admin_pages_render_and_empty_or_invalid_filters_are_explained(): void
    {
        [$admin,$group,$members] = $this->setupGroup();
        foreach (['/admin', '/admin/kelompok', '/admin/anggota', '/admin/buku-kas', ...array_map(fn ($k) => '/admin/transaksi/'.$k.'?group_id='.$group->id, ['saving', 'loan', 'payment', 'other'])] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/?start=2026-10-31&end=2026-09-01')->assertSessionHasErrors('end');
        $this->get('/?group_id=999999')->assertSessionHasErrors('group_id');
        $this->get('/?start=2000-01-01&end=2026-01-01')->assertSessionHasErrors('end');
    }

    public function test_login_logout_and_first_admin_command(): void
    {
        $this->artisan('vsla:admin', ['email' => 'pengurus@example.test', '--name' => 'Pengurus'])->expectsQuestion('Kata sandi (minimal 12 karakter)', 'KataSandiUji123!')->expectsQuestion('Ulangi kata sandi', 'KataSandiUji123!')->assertSuccessful();
        $this->post('/login', ['email' => 'pengurus@example.test', 'password' => 'salah'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'pengurus@example.test', 'password' => 'KataSandiUji123!'])->assertRedirect(route('admin.index'));
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect(route('dashboard'));
        $this->assertGuest();
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_failure_after_cash_creation_rolls_back_cash_loan_and_schedule(): void
    {
        [$admin,$group,$members] = $this->setupGroup();
        Exceptions::fake();
        Installment::creating(function (Installment $bill) {
            if ($bill->sequence === 2) {
                throw new \RuntimeException('Simulated schedule failure');
            }
        });
        try {
            $this->post('/admin/transaksi', $this->data($group, 'loan', '2026-01-31', ['member_id' => $members[0]->id, 'principal' => 100000, 'rate' => '1', 'months' => 3]))->assertServerError();
            $this->assertDatabaseCount('cash_entries', 0);
            $this->assertDatabaseCount('loans', 0);
            $this->assertDatabaseCount('installments', 0);
            Exceptions::assertReported(\RuntimeException::class);
        } finally {
            Installment::flushEventListeners();
        }
    }

    public function test_paid_installments_are_marked_lunas_and_remain_visible_to_admin(): void
    {
        [$admin,$group,$members,$deposit,$payment,$bill] = $this->scenario();
        $this->record($this->data($group, 'payment', '2026-11-01', ['member_id' => $members[0]->id, 'installment_id' => $bill->id, 'amount' => 70000]));
        $this->get('/admin/pinjaman')->assertOk()->assertSee('Lunas')->assertSee($members[0]->name);
        $this->get('/admin/kas/'.Loan::first()->cash_entry_id)->assertOk()->assertSee('Lunas');
        $this->get('/admin/transaksi/payment?group_id='.$group->id.'&member_id='.$members[0]->id)->assertOk()->assertSee('Tidak ada tagihan terbuka');
        $payment['request_key'] = (string) Str::uuid();
        $payment['amount'] = 1;
        $this->post('/admin/transaksi', $payment)->assertSessionHasErrors('amount');
    }

    public function test_inactive_members_can_repay_existing_loans_and_payment_cannot_precede_disbursement(): void
    {
        [$admin,$group,$members,$deposit,$payment] = $this->scenario();
        $members[0]->update(['is_active' => false]);
        $payment['request_key'] = (string) Str::uuid();
        $payment['date'] = '2026-09-01';
        $payment['amount'] = 1;
        $this->post('/admin/transaksi', $payment)->assertSessionHasErrors('date');
        $payment['date'] = '2026-10-15';
        $this->record($payment);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_other_cash_resubmission_and_text_excel_cells_cannot_create_formulas(): void
    {
        [$admin,$group] = $this->setupGroup();
        $data = $this->data($group, 'other', '2026-09-01', ['direction' => 'out', 'amount' => 10000, 'description' => '=HYPERLINK("http://example.test")']);
        $this->record($data);
        $this->record($data);
        $this->assertDatabaseCount('cash_entries', 1);
        $this->get('/?start=2026-09-01&end=2026-09-30')->assertViewHas('report', fn ($r) => $r['summary']['balance'] === -10000);
        $response = $this->get('/admin/laporan.xlsx?start=2026-09-01&end=2026-09-30')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet3.xml');
        $this->assertStringContainsString('t="inlineStr"', $xml);
        $this->assertStringContainsString('=HYPERLINK', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
        $zip->close();
        unlink($path);
    }

    public function test_login_refuses_nonadmin_and_limits_repeated_wrong_passwords(): void
    {
        $user = User::factory()->create(['email' => 'anggota@example.test', 'password' => 'password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'salah'])->assertTooManyRequests();
    }

    public function test_withdrawal_before_first_deposit_is_rejected_even_if_future_balance_is_positive(): void
    {
        [$admin, $group, $members] = $this->setupGroup();
        $this->record($this->data($group, 'saving', '2026-10-01', ['direction' => 'in', 'type' => 'wajib', 'rows' => [['member_id' => $members[0]->id, 'amount' => 100000]]]));
        $this->post('/admin/transaksi', $this->data($group, 'saving', '2026-09-01', ['direction' => 'out', 'type' => 'wajib', 'rows' => [['member_id' => $members[0]->id, 'amount' => 50000]]]))->assertSessionHasErrors('rows.0.amount');
        $this->assertDatabaseCount('cash_entries', 1);
    }
}
