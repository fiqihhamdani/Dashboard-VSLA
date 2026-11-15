<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Installment;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Saving;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminWorkflowTest extends TestCase
{
    private function transaction(Group $group, string $kind, array $extra): array
    {
        return ['kind' => $kind, 'request_key' => (string) Str::uuid(), 'group_id' => $group->id, 'date' => '2026-11-15'] + $extra;
    }

    public function test_member_registration_is_separate_and_validation_preserves_input(): void
    {
        $group = Group::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.members'))->assertOk()->assertSee('Tambah Anggota')->assertDontSee('name="name"', false);
        $this->get(route('admin.members.create'))->assertOk()->assertSee('name="name"', false);
        $data = ['name' => 'Siti Aminah', 'group_id' => $group->id, 'is_active' => 1];
        $this->from(route('admin.members.create'))->post(route('admin.members.store'), array_diff_key($data, ['is_active' => true]))
            ->assertRedirect(route('admin.members.create'))->assertSessionHasErrors('is_active')->assertSessionHasInput('name', 'Siti Aminah');
        $this->post(route('admin.members.store'), $data)->assertRedirect(route('admin.members'))->assertSessionHas('status');
        $this->get(route('admin.members'))->assertOk()->assertSee('Siti Aminah');
        $this->assertDatabaseHas('members', $data);
    }

    public function test_member_filters_and_pagination_keep_the_context(): void
    {
        $group = Group::factory()->create();
        Member::factory()->count(22)->for($group)->create(['name' => 'Siti', 'is_active' => false]);
        Member::factory()->for($group)->create(['name' => 'Siti aktif']);
        Member::factory()->create(['name' => 'Siti kelompok lain', 'is_active' => false]);
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.members', ['group_id' => $group->id, 'is_active' => '0', 'q' => 'Sit', 'page' => 2]))
            ->assertOk()->assertViewHas('members', fn ($members) => $members->total() === 22 && $members->count() === 2)
            ->assertDontSee('Siti aktif')->assertDontSee('Siti kelompok lain');
    }

    public function test_search_distinguishes_duplicate_names_and_limits_results_by_group(): void
    {
        $group = Group::factory()->create(['name' => 'Melati']);
        $members = Member::factory()->count(24)->for($group)->create(['name' => 'Siti Aminah']);
        Member::factory()->create(['name' => 'Siti di kelompok lain']);
        Member::factory()->for($group)->create(['name' => 'Siti nonaktif', 'is_active' => false]);
        $this->actingAs(User::factory()->admin()->create());
        foreach (['saving', 'loan'] as $kind) {
            $params = ['group_id' => $group->id, 'kind' => $kind, 'q' => 'Ami'];
            $this->getJson(route('admin.members.search', $params))->assertOk()->assertJsonCount(20, 'data')
                ->assertJsonPath('has_more', true)->assertJsonPath('data.0.id', $members[0]->id)
                ->assertJsonPath('data.0.label', 'Siti Aminah · Melati · '.$group->code.' · #'.$members[0]->id);
            $this->getJson(route('admin.members.search', $params + ['page' => 2]))->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('has_more', false);
        }
        $this->getJson(route('admin.members.search', ['group_id' => $group->id, 'kind' => 'loan', 'q' => 'Tidak ada']))->assertJsonCount(0, 'data');
        $this->getJson(route('admin.members.search', ['group_id' => 99999, 'kind' => 'loan']))->assertUnprocessable()->assertJsonValidationErrors('group_id');
    }

    public function test_grouped_deposit_details_use_cash_id_and_reconcile_three_members(): void
    {
        $group = Group::factory()->create();
        $members = Member::factory()->count(3)->for($group)->create();
        $this->actingAs(User::factory()->admin()->create());
        $data = $this->transaction($group, 'saving', ['direction' => 'in', 'type' => 'wajib', 'rows' => [
            ['member_id' => $members[0]->id, 'amount' => 10000],
            ['member_id' => $members[1]->id, 'amount' => 25000],
            ['member_id' => $members[2]->id, 'amount' => 45000],
        ]]);
        $this->post(route('admin.transactions.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $cash = CashEntry::firstOrFail();
        $this->post(route('admin.transactions.store'), $this->transaction($group, 'other', ['direction' => 'in', 'amount' => 80000, 'description' => 'Nominal dan tanggal sama']))->assertSessionHasNoErrors();
        $this->getJson(route('admin.cash.deposits', $cash))->assertOk()->assertJsonCount(3, 'rows')
            ->assertJsonPath('member_count', 3)->assertJsonPath('total', 80000)->assertJsonPath('cash_total', 80000)->assertJsonPath('matches', true)
            ->assertJsonPath('rows.1.amount', 25000)->assertJsonPath('rows.1.member_id', $members[1]->id);
        $this->getJson(route('admin.cash.deposits', CashEntry::latest('id')->first()))->assertJsonCount(0, 'rows');
        $this->get(route('admin.ledger', ['start' => '2026-11-01', 'end' => '2026-11-30', 'group_id' => $group->id]))
            ->assertOk()->assertSee('Detail setoran')->assertSee(route('admin.cash.deposits', $cash), false);
        $this->get(route('admin.cash.show', $cash))->assertOk()->assertSee('Detail setoran');
        $this->assertDatabaseCount('savings', 3);
        $this->assertDatabaseHas('cash_entries', ['id' => $cash->id, 'amount' => 80000]);
    }

    public function test_legacy_multiple_details_count_unique_people_and_report_mismatches(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->for($group)->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.transactions.store'), $this->transaction($group, 'saving', ['direction' => 'in', 'type' => 'pokok', 'rows' => [['member_id' => $member->id, 'amount' => 10000]]]))->assertSessionHasNoErrors();
        $cash = CashEntry::firstOrFail();
        Saving::create(['cash_entry_id' => $cash->id, 'member_id' => $member->id, 'type' => 'sukarela', 'amount' => 5000]);
        $this->getJson(route('admin.cash.deposits', $cash))->assertJsonCount(2, 'rows')->assertJsonPath('member_count', 1)
            ->assertJsonPath('total', 15000)->assertJsonPath('cash_total', 10000)->assertJsonPath('matches', false);
    }

    public function test_form_restores_valid_choices_but_rejects_duplicates_and_wrong_groups(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->for($group)->create(['name' => 'Nama terpilih']);
        $other = Member::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $url = route('admin.transactions.create', ['kind' => 'saving', 'group_id' => $group->id]);
        $data = $this->transaction($group, 'saving', ['direction' => 'in', 'type' => 'wajib', 'rows' => [['member_id' => $member->id, 'amount' => 0]]]);
        $this->from($url)->post(route('admin.transactions.store'), $data)->assertRedirect($url)->assertSessionHasErrors('rows.0.amount');
        $this->get($url)->assertOk()->assertSee('Nama terpilih')->assertSee('value="'.$member->id.'" data-member-value', false);
        $data['rows'] = [['member_id' => $member->id, 'amount' => 10], ['member_id' => $member->id, 'amount' => 20]];
        $this->post(route('admin.transactions.store'), $data)->assertSessionHasErrors('rows.0.member_id');
        $data['rows'] = [['member_id' => $other->id, 'amount' => 10]];
        $this->post(route('admin.transactions.store'), $data)->assertSessionHasErrors('rows.0.member_id');
        $this->post(route('admin.transactions.store'), $this->transaction($group, 'loan', ['member_id' => $other->id, 'principal' => 100000, 'months' => 1, 'rate' => '0']))->assertSessionHasErrors('member_id');
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_partial_and_early_payments_refresh_options_and_last_payment_removes_member(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->for($group)->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.transactions.store'), $this->transaction($group, 'loan', ['member_id' => $member->id, 'principal' => 100000, 'months' => 2, 'rate' => '0']))->assertSessionHasNoErrors();
        $bills = Installment::orderBy('sequence')->get();
        $params = ['kind' => 'payment', 'group_id' => $group->id, 'member_id' => $member->id];
        $search = route('admin.members.search', ['kind' => 'payment', 'group_id' => $group->id]);
        $pay = fn ($bill, $amount) => $this->transaction($group, 'payment', ['member_id' => $member->id, 'installment_id' => $bill->id, 'amount' => $amount]);
        $this->post(route('admin.transactions.store'), $pay($bills[0], 20000))->assertRedirect(route('admin.transactions.create', $params));
        $this->get(route('admin.transactions.create', $params))->assertOk()->assertSee('30.000')->assertViewHas('bills', fn ($bills) => $bills->count() === 2);
        $this->getJson($search)->assertJsonPath('data.0.id', $member->id);
        $this->post(route('admin.transactions.store'), $pay($bills[0], 30000))->assertSessionHasNoErrors();
        $this->get(route('admin.transactions.create', $params))->assertViewHas('bills', fn ($open) => $open->modelKeys() === [$bills[1]->id]);
        $this->getJson($search)->assertJsonCount(1, 'data');
        $this->post(route('admin.transactions.store'), $pay($bills[1], 50000))->assertRedirect(route('admin.transactions.create', ['kind' => 'payment', 'group_id' => $group->id]));
        $this->getJson($search)->assertJsonCount(0, 'data');
        $this->get(route('admin.transactions.create', $params))->assertViewHas('memberId', null)->assertSee('Semua tagihan sudah lunas');
        $this->get(route('admin.loans'))->assertOk()->assertSee($member->name)->assertSee('Lunas');
        $this->assertDatabaseCount('loans', 1);
        $this->assertDatabaseCount('installments', 2);
        $this->assertDatabaseCount('payments', 3);
    }

    public function test_stale_form_from_another_admin_cannot_overpay_a_settled_bill(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->for($group)->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.transactions.store'), $this->transaction($group, 'loan', ['member_id' => $member->id, 'principal' => 100000, 'months' => 1, 'rate' => '0']))->assertSessionHasNoErrors();
        $bill = Installment::firstOrFail();
        $stale = $this->transaction($group, 'payment', ['member_id' => $member->id, 'installment_id' => $bill->id, 'amount' => 50000]);
        $this->get(route('admin.transactions.create', ['kind' => 'payment', 'group_id' => $group->id, 'member_id' => $member->id]))->assertOk();
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.transactions.store'), array_replace($stale, ['request_key' => (string) Str::uuid(), 'amount' => 100000]))->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.transactions.store'), $stale)->assertSessionHasErrors(['amount' => 'Pembayaran melebihi sisa tagihan. Periksa nominal yang masih terbuka.']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cash_entries', 2);
        $this->assertSame(100000, (int) Payment::sum('amount'));
    }

    public function test_search_and_deposit_endpoints_require_an_admin(): void
    {
        $group = Group::factory()->create();
        $admin = User::factory()->admin()->create();
        $cash = CashEntry::create(['group_id' => $group->id, 'created_by' => $admin->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'date' => '2026-11-15', 'direction' => 'in', 'category' => 'other', 'amount' => 1000, 'description' => 'Akses admin']);
        $search = route('admin.members.search', ['kind' => 'saving', 'group_id' => $group->id]);
        $detail = route('admin.cash.deposits', $cash);
        foreach ([$search, $detail, route('admin.members.create')] as $url) {
            $this->get($url)->assertRedirect(route('login'));
            $this->getJson($url)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create());
        foreach ([$search, $detail, route('admin.members.create')] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }
}
