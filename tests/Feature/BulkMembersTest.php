<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

class BulkMembersTest extends TestCase
{
    public function test_admin_adds_many_members_to_one_group_with_shared_status(): void
    {
        $group = Group::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('admin.members.bulk'), [
            'group_id' => $group->id, 'is_active' => '0',
            'members' => [['name' => 'Siti Aminah'], ['name' => ' Budi Santoso '], ['name' => 'Dewi Lestari']],
        ])->assertRedirect(route('admin.members', ['group_id' => $group->id]))
            ->assertSessionHas('status', '3 anggota berhasil ditambahkan ke kelompok yang dipilih.');

        $this->assertDatabaseCount('members', 3);
        foreach (['Siti Aminah', 'Budi Santoso', 'Dewi Lestari'] as $name) {
            $this->assertDatabaseHas('members', ['name' => $name, 'group_id' => $group->id, 'is_active' => 0]);
        }
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_invalid_row_preserves_the_list_and_creates_no_members(): void
    {
        $group = Group::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $data = ['group_id' => $group->id, 'is_active' => 1, 'members' => [['name' => 'Nama valid'], ['name' => '   ']]];

        $this->followingRedirects()->from(route('admin.members'))->post(route('admin.members.bulk'), $data)
            ->assertOk()
            ->assertSee('Nama valid')
            ->assertSee('Nama anggota pada baris 2 wajib diisi.');

        $this->assertDatabaseCount('members', 0);
    }

    public function test_missing_group_or_status_and_long_names_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.members.bulk'), ['members' => [['name' => str_repeat('A', 256)]]])
            ->assertSessionHasErrors(['group_id', 'is_active', 'members.0.name']);
        $this->assertDatabaseCount('members', 0);
    }

    public function test_empty_or_oversized_batches_are_rejected_but_100_members_are_allowed(): void
    {
        $group = Group::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $data = ['group_id' => $group->id, 'is_active' => 1, 'members' => []];
        $this->post(route('admin.members.bulk'), $data)->assertSessionHasErrors('members');
        $data['members'] = array_fill(0, 101, ['name' => 'Anggota uji']);
        $this->post(route('admin.members.bulk'), $data)->assertSessionHasErrors(['members' => 'Maksimal 100 anggota dalam satu kali penyimpanan.']);
        $this->assertDatabaseCount('members', 0);

        array_pop($data['members']);
        $this->post(route('admin.members.bulk'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('members', 100);
    }

    public function test_bulk_creation_requires_an_admin(): void
    {
        $group = Group::factory()->create();
        $data = ['group_id' => $group->id, 'is_active' => 1, 'members' => [['name' => 'Anggota uji']]];
        $this->post(route('admin.members.bulk'), $data)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post(route('admin.members.bulk'), $data)->assertForbidden();
        $this->assertDatabaseCount('members', 0);
    }

    public function test_failure_on_later_member_rolls_back_the_entire_batch(): void
    {
        $group = Group::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        Exceptions::fake();
        Member::creating(function (Member $member): void {
            if ($member->name === 'Gagal') {
                throw new RuntimeException('Simulated member creation failure');
            }
        });
        try {
            $this->post(route('admin.members.bulk'), ['group_id' => $group->id, 'is_active' => 1, 'members' => [['name' => 'Valid'], ['name' => 'Gagal']]])->assertServerError();
            $this->assertDatabaseCount('members', 0);
            Exceptions::assertReported(RuntimeException::class);
        } finally {
            Member::flushEventListeners();
        }
    }

    public function test_duplicate_names_remain_distinct_members_and_existing_records_are_untouched(): void
    {
        $group = Group::factory()->create();
        $otherGroup = Group::factory()->create();
        $existing = Member::factory()->for($otherGroup)->create(['name' => 'Siti']);
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.members.bulk'), ['group_id' => $group->id, 'is_active' => 1, 'members' => [['name' => 'Siti'], ['name' => 'Siti']]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('members', 3);
        $this->assertDatabaseHas('members', ['id' => $existing->id, 'group_id' => $otherGroup->id]);
        $this->assertSame(2, Member::where('group_id', $group->id)->count());
    }
}
