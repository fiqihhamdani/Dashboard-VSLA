<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    public function test_overview_shows_real_balances_and_only_the_six_latest_transactions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00', 'Asia/Jakarta'));
        $group = Group::factory()->create(['name' => 'Kelompok Melati']);
        Member::factory()->count(2)->for($group)->create();
        Member::factory()->for($group)->create(['is_active' => false]);
        $this->actingAs(User::factory()->admin()->create());
        for ($i = 1; $i <= 8; $i++) {
            $this->post(route('admin.transactions.store'), [
                'kind' => 'other', 'request_key' => (string) Str::uuid(), 'group_id' => $group->id,
                'date' => '2026-11-15', 'direction' => $i === 8 ? 'out' : 'in', 'amount' => $i === 8 ? 2500 : 10000,
                'description' => 'Catatan administrasi '.$i,
            ])->assertSessionHasNoErrors();
        }

        $this->get(route('admin.index'))->assertOk()->assertSee('Kelompok Melati')
            ->assertSee('Catatan administrasi 8')->assertSee('Catatan administrasi 3')
            ->assertDontSee('Catatan administrasi 1')->assertDontSee('Catatan administrasi 2')
            ->assertSee('Senin, 16 November 2026')
            ->assertViewHas('report', fn ($report) => $report['summary']['balance'] === 67500 && $report['summary']['active_members'] === 2)
            ->assertViewHas('recentTransactions', fn ($transactions) => $transactions->count() === 6 && $transactions->first()->description === 'Catatan administrasi 8');
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Catatan administrasi 8');
        $this->assertDatabaseCount('cash_entries', 8);
    }

    public function test_empty_overview_provides_a_real_path_to_start_recording(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))
            ->assertOk()->assertSee('Buku kas Anda dimulai di sini.')
            ->assertSee('Tambah kelompok')->assertSee(route('admin.groups'), false)
            ->assertViewHas('recentTransactions', fn ($transactions) => $transactions->isEmpty())
            ->assertViewHas('report', fn ($report) => $report['summary']['balance'] === 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }
}
