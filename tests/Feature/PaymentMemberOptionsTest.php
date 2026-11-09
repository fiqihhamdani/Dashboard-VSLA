<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Installment;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentMemberOptionsTest extends TestCase
{
    private function lend(Group $group, Member $member): void
    {
        $this->post(route('admin.transactions.store'), [
            'kind' => 'loan', 'request_key' => (string) Str::uuid(), 'group_id' => $group->id,
            'date' => '2026-09-10', 'member_id' => $member->id, 'principal' => 100000, 'rate' => '0', 'months' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_payment_options_include_only_borrowers_from_the_selected_group(): void
    {
        $group = Group::factory()->create();
        $otherGroup = Group::factory()->create();
        $borrower = Member::factory()->for($group)->create(['name' => 'Peminjam kelompok dipilih']);
        $withoutLoan = Member::factory()->for($group)->create(['name' => 'Anggota tanpa pinjaman']);
        $otherBorrower = Member::factory()->for($otherGroup)->create(['name' => 'Peminjam kelompok lain']);
        $this->actingAs(User::factory()->admin()->create());
        $this->lend($group, $borrower);
        $this->lend($otherGroup, $otherBorrower);

        $this->get(route('admin.transactions.create', ['kind' => 'payment', 'group_id' => $group->id]))
            ->assertOk()
            ->assertViewHas('members', fn ($members) => $members->modelKeys() === [$borrower->id])
            ->assertSee($borrower->name)->assertDontSee($withoutLoan->name)->assertDontSee($otherBorrower->name);
        $this->get(route('admin.transactions.create', ['kind' => 'payment', 'group_id' => $otherGroup->id]))
            ->assertOk()->assertViewHas('members', fn ($members) => $members->modelKeys() === [$otherBorrower->id]);
    }

    public function test_empty_borrower_list_explains_that_the_group_has_no_loan_records(): void
    {
        $group = Group::factory()->create();
        Member::factory()->for($group)->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('admin.transactions.create', ['kind' => 'payment', 'group_id' => $group->id]))
            ->assertOk()->assertViewHas('members', fn ($members) => $members->isEmpty())
            ->assertSee('Belum ada anggota yang memiliki catatan pinjaman di kelompok ini.')
            ->assertDontSee('Tambahkan anggota.');
        $this->get(route('admin.transactions.create', ['kind' => 'payment']))
            ->assertOk()->assertViewHas('members', fn ($members) => $members->isEmpty());
    }

    public function test_inactive_borrowers_remain_selectable_and_only_unpaid_installments_are_listed(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->for($group)->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->lend($group, $member);
        $bill = Installment::firstOrFail();
        $member->update(['is_active' => false]);
        $url = route('admin.transactions.create', ['kind' => 'payment', 'group_id' => $group->id, 'member_id' => $member->id]);

        $this->get($url)->assertOk()->assertViewHas('members', fn ($members) => $members->modelKeys() === [$member->id])
            ->assertViewHas('bills', fn ($bills) => $bills->modelKeys() === [$bill->id]);
        $this->post(route('admin.transactions.store'), [
            'kind' => 'payment', 'request_key' => (string) Str::uuid(), 'group_id' => $group->id,
            'date' => '2026-10-10', 'member_id' => $member->id, 'installment_id' => $bill->id, 'amount' => 100000,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertViewHas('members', fn ($members) => $members->modelKeys() === [$member->id])
            ->assertViewHas('bills', fn ($bills) => $bills->isEmpty())->assertSee('Tidak ada tagihan terbuka untuk anggota ini.');
    }

    public function test_saving_and_disbursement_forms_still_offer_active_members_without_loans(): void
    {
        $group = Group::factory()->create();
        $active = Member::factory()->for($group)->create();
        Member::factory()->for($group)->create(['is_active' => false]);
        $this->actingAs(User::factory()->admin()->create());

        foreach (['saving', 'loan'] as $kind) {
            $this->get(route('admin.transactions.create', ['kind' => $kind, 'group_id' => $group->id]))
                ->assertOk()->assertViewHas('members', fn ($members) => $members->modelKeys() === [$active->id]);
        }
    }
}
