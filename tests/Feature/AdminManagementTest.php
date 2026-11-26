<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Services\ExcelImport;
use App\Services\ExcelReport;
use App\Services\ExcelWorkbook;
use App\Services\TransactionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
use ZipArchive;

class AdminManagementTest extends TestCase
{
    public function test_group_and_member_crud_protects_existing_relations(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $url = 'https://drive.google.com/file/d/legal_123/view';
        $this->post(route('admin.groups.store'), ['name' => 'Baru', 'code' => '001', 'location' => 'Desa', 'is_active' => 1, 'legality_url' => $url])->assertRedirect();
        $group = Group::firstOrFail();
        $this->get(route('admin.groups.show', $group))->assertOk()->assertSee('Lihat Legalitas')->assertSee('rel="noopener noreferrer"', false);
        $this->put(route('admin.groups.update', $group), ['name' => 'Diubah', 'code' => '001', 'location' => 'Desa', 'is_active' => 1, 'legality_url' => ''])->assertRedirect();
        $this->assertNull($group->fresh()->legality_url);
        $this->post(route('admin.members.store'), ['name' => 'Anggota', 'group_id' => $group->id, 'is_active' => 1])->assertRedirect();
        $member = Member::firstOrFail();
        $this->get(route('admin.members.show', $member))->assertOk()->assertSee('Anggota');
        $this->get(route('admin.members.edit', $member))->assertOk()->assertSee('Anggota');
        $this->put(route('admin.members.update', $member), ['name' => 'Nama diubah', 'group_id' => $group->id, 'is_active' => 0])->assertRedirect();
        $this->assertSame('Nama diubah', $member->fresh()->name);
        $this->delete(route('admin.groups.destroy', $group))->assertSessionHasErrors('group');
        $this->delete(route('admin.members.destroy', $member))->assertRedirect();
        $this->assertModelMissing($member);
        $this->delete(route('admin.groups.destroy', $group))->assertRedirect();
        $this->assertModelMissing($group);
    }

    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['https://drive.google.com.evil.test/file/d/123/view'])]
    #[TestWith(['https://evil.test@drive.google.com/file/d/123/view'])]
    #[TestWith(['http://drive.google.com/file/d/123/view'])]
    #[TestWith(['https://drive.google.com/drive/folders/123'])]
    public function test_invalid_drive_links_are_rejected(string $url): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.groups.store'), ['name' => 'G', 'code' => 'G', 'location' => 'L', 'is_active' => 1, 'legality_url' => $url])->assertSessionHasErrors('legality_url');
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_proof_changes_do_not_change_financial_records_or_idempotency(): void
    {
        $admin = User::factory()->admin()->create();
        $group = Group::factory()->create();
        $member = Member::factory()->for($group)->create();
        $data = ['kind' => 'loan', 'request_key' => (string) Str::uuid(), 'group_id' => $group->id, 'member_id' => $member->id, 'date' => '2026-11-20', 'principal' => 100000, 'rate' => '2', 'months' => 2, 'proof_url' => 'https://drive.google.com/file/d/proof123/view'];
        $this->actingAs($admin)->post(route('admin.transactions.store'), $data)->assertRedirect();
        $cash = CashEntry::firstOrFail();
        $this->get(route('admin.cash.show', $cash))->assertOk()->assertSee('Lihat Bukti Pencairan');
        $this->put(route('admin.cash.proof', $cash), ['proof_url' => 'javascript:alert(1)'])->assertSessionHasErrors('proof_url');
        $this->put(route('admin.cash.proof', $cash), ['proof_url' => 'https://drive.google.com/open?id=new_123'])->assertRedirect();
        $this->assertSame('https://drive.google.com/open?id=new_123', $cash->fresh()->proof_url);
        $this->put(route('admin.cash.proof', $cash), ['proof_url' => ''])->assertRedirect();
        $this->assertNull($cash->fresh()->proof_url);
        $this->post(route('admin.transactions.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('cash_entries', 1);
        $this->assertDatabaseCount('loans', 1);
        $this->assertDatabaseCount('installments', 2);
        $this->assertSame(104000, Loan::firstOrFail()->total);
        $this->assertSame(100000, $cash->fresh()->amount);
        $this->assertNull($cash->fresh()->proof_url);
        $this->delete(route('admin.members.destroy', $member))->assertSessionHasErrors('member');
        $this->delete(route('admin.groups.destroy', $group))->assertSessionHasErrors('group');
    }

    public function test_old_transactions_without_proof_still_render_and_lists_are_connected(): void
    {
        $admin = User::factory()->admin()->create();
        $group = Group::factory()->create();
        $member = Member::factory()->for($group)->create();
        $cash = app(TransactionService::class)->store($admin, ['kind' => 'loan', 'request_key' => (string) Str::uuid(), 'group_id' => $group->id, 'member_id' => $member->id, 'date' => '2026-11-20', 'principal' => 100000, 'rate' => '0', 'months' => 1]);
        $this->actingAs($admin)->get(route('admin.cash.show', $cash))->assertOk()->assertSee('Belum dilampirkan');
        foreach (['saving', 'loan', 'payment', 'other'] as $kind) {
            $this->get(route('admin.transactions.history', $kind))->assertOk();
            $this->get(route('admin.excel.form', $kind))->assertOk();
        }
        $this->get(route('admin.groups'))->assertOk();
        $this->get(route('admin.members'))->assertOk();
        $this->get(route('admin.loans'))->assertOk();
    }

    public function test_new_admin_endpoints_refuse_guests_and_non_admins(): void
    {
        $group = Group::factory()->create();
        $member = Member::factory()->for($group)->create();
        $cash = app(TransactionService::class)->store(User::factory()->admin()->create(), ['kind' => 'other', 'request_key' => (string) Str::uuid(), 'group_id' => $group->id, 'date' => '2026-11-20', 'direction' => 'in', 'amount' => 100, 'description' => 'Test']);
        $endpoints = [
            ['get', route('admin.groups.show', $group)], ['delete', route('admin.groups.destroy', $group)],
            ['get', route('admin.members.show', $member)], ['delete', route('admin.members.destroy', $member)],
            ['get', route('admin.transactions.history', 'saving')], ['put', route('admin.cash.proof', $cash)],
            ['get', route('admin.excel.form', 'groups')], ['get', route('admin.excel.template', 'groups')],
            ['get', route('admin.excel.export', ['module' => 'groups', 'scope' => 'all'])],
            ['post', route('admin.excel.preview', 'groups')], ['post', route('admin.excel.commit', 'groups')],
        ];
        foreach ($endpoints as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        foreach ($endpoints as [$method, $url]) {
            $this->{$method}($url)->assertForbidden();
        }
    }

    public function test_group_import_requires_confirmation_then_rejects_repeat(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->admin()->create());
        $row = ['001', 'Kelompok Baru', 'Desa', '1', 'https://drive.google.com/file/d/legal/view'];
        $preview = $this->preview('groups', [$row])->assertOk()->assertViewHas('rowErrors', []);
        $this->assertDatabaseCount('groups', 0);
        $token = $preview->viewData('token');
        $this->post(route('admin.excel.commit', 'groups'), ['token' => $token])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('groups', 0);
        $this->post(route('admin.excel.commit', 'groups'), ['token' => $token, 'confirmed' => '1'])->assertRedirect();
        $this->assertDatabaseHas('groups', ['code' => '001', 'legality_url' => $row[4]]);
        $this->post(route('admin.excel.commit', 'groups'), ['token' => $token, 'confirmed' => '1'])->assertStatus(419);
        $this->preview('groups', [$row])->assertOk()->assertViewHas('token', null)->assertViewHas('rowErrors', fn ($errors) => isset($errors[2]));
        $this->assertDatabaseCount('groups', 1);
    }

    public function test_import_revalidates_and_rolls_back_all_rows_when_data_changes(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->admin()->create());
        $rows = [['A', 'A', 'Lokasi', '1', ''], ['B', 'B', 'Lokasi', '1', '']];
        $preview = $this->preview('groups', $rows)->assertOk();
        Group::factory()->create(['code' => 'B']);
        $this->post(route('admin.excel.commit', 'groups'), ['token' => $preview->viewData('token'), 'confirmed' => '1'])->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('groups', ['code' => 'A']);
        $this->assertDatabaseCount('groups', 1);
    }

    public function test_member_import_rejects_same_name_in_same_group_and_missing_references(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->admin()->create());
        $group = Group::factory()->create();
        Member::factory()->for($group)->create(['name' => 'Budi']);
        $this->preview('members', [[$group->id, 'budi', 1], [999999, 'Sari', 1]])->assertOk()->assertViewHas('token', null)->assertViewHas('rowErrors', fn ($errors) => isset($errors[2], $errors[3]));
        $this->assertDatabaseCount('members', 1);
        $preview = $this->preview('members', [[$group->id, 'Sari', 1]])->assertOk()->assertViewHas('rowErrors', []);
        $this->post(route('admin.excel.commit', 'members'), ['token' => $preview->viewData('token'), 'confirmed' => '1'])->assertRedirect();
        $this->assertDatabaseHas('members', ['name' => 'Sari', 'group_id' => $group->id]);
    }

    public function test_transaction_import_uses_original_interest_schedule_and_refuses_duplicate_request_keys(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->admin()->create());
        $member = Member::factory()->create();
        $row = [(string) Str::uuid(), $member->group_id, '2026-11-20', $member->id, 100001, '2', 3, 'https://drive.google.com/file/d/proof/view'];
        $preview = $this->preview('loan', [$row])->assertOk()->assertViewHas('rowErrors', []);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->assertDatabaseCount('loans', 0);
        $this->post(route('admin.excel.commit', 'loan'), ['token' => $preview->viewData('token'), 'confirmed' => '1'])->assertRedirect();
        $this->assertSame(106001, Loan::firstOrFail()->total);
        $this->assertSame([35333, 35333, 35335], Installment::orderBy('sequence')->pluck('amount')->all());
        $this->assertDatabaseHas('cash_entries', ['proof_url' => $row[7], 'amount' => 100001]);
        $this->preview('loan', [$row])->assertOk()->assertViewHas('token', null);
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_import_payment_saving_and_other_match_manual_financial_effects(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $this->actingAs($admin);
        app(TransactionService::class)->store($admin, ['kind' => 'loan', 'request_key' => (string) Str::uuid(), 'group_id' => $member->group_id, 'date' => '2026-11-20', 'member_id' => $member->id, 'principal' => 100000, 'rate' => '0', 'months' => 1]);
        $bill = Installment::firstOrFail();
        foreach ([
            'payment' => [(string) Str::uuid(), $member->group_id, '2026-11-21', $member->id, $bill->id, 25000],
            'saving' => [(string) Str::uuid(), $member->group_id, '2026-11-21', $member->id, 'wajib', 'in', 10000],
            'other' => [(string) Str::uuid(), $member->group_id, '2026-11-21', 'out', 5000, 'ATK'],
        ] as $module => $row) {
            $preview = $this->preview($module, [$row])->assertOk()->assertViewHas('rowErrors', []);
            $this->post(route('admin.excel.commit', $module), ['token' => $preview->viewData('token'), 'confirmed' => '1'])->assertRedirect();
        }
        $this->assertSame(75000, $bill->remaining());
        $this->assertDatabaseHas('savings', ['member_id' => $member->id, 'amount' => 10000]);
        $this->assertDatabaseHas('cash_entries', ['category' => 'other', 'amount' => 5000, 'direction' => 'out']);
        $this->assertDatabaseCount('cash_entries', 4);
        $bad = [(string) Str::uuid(), $member->group_id, '2026-11-22', $member->id, $bill->id, 80000];
        $this->preview('payment', [$bad])->assertOk()->assertViewHas('token', null);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_workbook_round_trip_preserves_text_and_rejects_formula_and_wrong_headers(): void
    {
        $excel = app(ExcelReport::class);
        $reader = app(ExcelWorkbook::class);
        $path = $excel->workbook(['Data' => [['kode', 'nama'], ['00123', '=HYPERLINK("evil")']]]);
        try {
            $this->assertSame('00123', $reader->read($path, ['kode', 'nama'])[0]['data']['kode']);
            $this->assertSame('=HYPERLINK("evil")', $reader->read($path, ['kode', 'nama'])[0]['data']['nama']);
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringNotContainsString('<f>', $xml);
            $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<row r="2">', '<row r="2"><c r="C2"><f>1+1</f><v>2</v></c>', $xml));
            $zip->close();
            $this->expectException(ValidationException::class);
            $reader->read($path, ['kode', 'nama']);
        } finally {
            unlink($path);
        }
    }

    public function test_import_refuses_bad_extension_wrong_headers_and_invalid_dates(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.excel.preview', 'groups'), ['file' => UploadedFile::fake()->create('not.csv', 2, 'text/csv')])->assertSessionHasErrors('file');
        $path = app(ExcelReport::class)->workbook(['Data' => [['wrong'], ['X']]]);
        try {
            $this->post(route('admin.excel.preview', 'groups'), ['file' => new UploadedFile($path, 'data.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)])->assertSessionHasErrors('file');
        } finally {
            unlink($path);
        }
        $group = Group::factory()->create();
        $this->preview('other', [[(string) Str::uuid(), $group->id, '27/11/2026', 'in', 100, 'Bad date']])->assertOk()->assertViewHas('token', null);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_export_all_and_filtered_and_templates_are_real_excel(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Group::factory()->create(['code' => '00123', 'name' => '=1+1']);
        Group::factory()->create(['code' => 'OTHER', 'name' => 'Second']);
        foreach (['all' => 3, 'filtered' => 2] as $scope => $count) {
            $response = $this->get(route('admin.excel.export', ['module' => 'groups', 'scope' => $scope, 'q' => '00123']))->assertOk()->assertDownload();
            $zip = new ZipArchive;
            $zip->open($response->baseResponse->getFile()->getPathname());
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertSame($count, substr_count($xml, '<row '));
            $this->assertStringContainsString('00123', $xml);
            $this->assertStringNotContainsString('<f>', $xml);
            $zip->close();
        }
        foreach (array_keys(ExcelImport::HEADERS) as $module) {
            $this->get(route('admin.excel.template', $module))->assertOk()->assertDownload('template-'.$module.'.xlsx');
            $this->get(route('admin.excel.export', ['module' => $module, 'scope' => 'all']))->assertOk()->assertDownload();
        }
        $this->get(route('admin.excel.export', ['module' => 'loans', 'scope' => 'all']))->assertOk()->assertDownload();
    }

    #[TestWith(['macro'])]
    #[TestWith(['external'])]
    #[TestWith(['doctype'])]
    #[TestWith(['rows'])]
    #[TestWith(['columns'])]
    #[TestWith(['sheet'])]
    public function test_import_rejects_unsafe_or_oversized_workbook_structure(string $kind): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $rows = [ExcelImport::HEADERS['groups'], ['G', 'Nama', 'Desa', 1, '']];
        if ($kind === 'rows') {
            $rows = [ExcelImport::HEADERS['groups'], ...array_fill(0, 501, ['G', 'Nama', 'Desa', 1, ''])];
        }
        if ($kind === 'columns') {
            $rows[1][] = 'extra';
        }
        $path = app(ExcelReport::class)->workbook([$kind === 'sheet' ? 'Wrong' : 'Data' => $rows]);
        try {
            $zip = new ZipArchive;
            $zip->open($path);
            if ($kind === 'macro') {
                $zip->addFromString('xl/vbaProject.bin', 'macro');
            } elseif ($kind === 'external') {
                $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<Relationships><Relationship Id="r1" Target="https://example.test" TargetMode="External" Type="externalLink"/></Relationships>');
            } elseif ($kind === 'doctype') {
                $zip->addFromString('xl/workbook.xml', '<!DOCTYPE workbook [<!ENTITY ex SYSTEM "file:///etc/passwd">]><workbook>&ex;</workbook>');
            }
            $zip->close();
            $this->post(route('admin.excel.preview', 'groups'), ['file' => new UploadedFile($path, 'data.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)])->assertSessionHasErrors('file');
            $this->assertDatabaseCount('groups', 0);
        } finally {
            unlink($path);
        }
    }

    public function test_import_file_size_and_expiry_are_enforced(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.excel.preview', 'groups'), ['file' => UploadedFile::fake()->create('huge.xlsx', 5121, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])->assertSessionHasErrors('file');
        $preview = $this->preview('groups', [['G', 'Nama', 'Desa', 1, '']])->assertOk();
        $this->travel(31)->minutes();
        $this->post(route('admin.excel.commit', 'groups'), ['token' => $preview->viewData('token'), 'confirmed' => 1])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('groups', 0);
        Storage::disk('local')->assertMissing('imports/'.$preview->viewData('token').'.json');
    }

    public function test_preview_token_is_bound_to_admin_and_module(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->admin()->create());
        $preview = $this->preview('groups', [['G', 'Nama', 'Desa', 1, '']])->assertOk();
        $payload = ['token' => $preview->viewData('token'), 'confirmed' => 1];
        $this->post(route('admin.excel.commit', 'members'), $payload)->assertStatus(419);
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('admin.excel.commit', 'groups'), $payload)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_failed_transaction_import_rolls_back_cash_and_children_after_revalidation(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->create();
        $this->actingAs($admin);
        $service = app(TransactionService::class);
        $service->store($admin, ['kind' => 'saving', 'request_key' => (string) Str::uuid(), 'group_id' => $member->group_id, 'date' => '2026-11-20', 'type' => 'wajib', 'direction' => 'in', 'rows' => [['member_id' => $member->id, 'amount' => 10000]]]);
        $rows = [
            [(string) Str::uuid(), $member->group_id, '2026-11-21', $member->id, 'wajib', 'out', 4000],
            [(string) Str::uuid(), $member->group_id, '2026-11-21', $member->id, 'wajib', 'out', 4000],
        ];
        $preview = $this->preview('saving', $rows)->assertOk()->assertViewHas('rowErrors', []);
        $this->assertDatabaseCount('cash_entries', 1);
        $service->store($admin, ['kind' => 'saving', 'request_key' => (string) Str::uuid(), 'group_id' => $member->group_id, 'date' => '2026-11-21', 'type' => 'wajib', 'direction' => 'out', 'rows' => [['member_id' => $member->id, 'amount' => 3000]]]);
        $this->post(route('admin.excel.commit', 'saving'), ['token' => $preview->viewData('token'), 'confirmed' => 1])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('cash_entries', 2);
        $this->assertDatabaseCount('savings', 2);
    }

    public function test_cash_filters_and_report_all_export_use_source_transactions(): void
    {
        $admin = User::factory()->admin()->create();
        $group = Group::factory()->create();
        $otherGroup = Group::factory()->create();
        $service = app(TransactionService::class);
        foreach ([[$group, '2026-11-01', 100], [$otherGroup, '2026-11-02', 200]] as [$g, $date, $amount]) {
            $service->store($admin, ['kind' => 'other', 'request_key' => (string) Str::uuid(), 'group_id' => $g->id, 'date' => $date, 'direction' => 'in', 'amount' => $amount, 'description' => 'Cash']);
        }
        $this->actingAs($admin);
        $this->get(route('admin.transactions.history', ['kind' => 'other', 'end' => '2026-11-01']))->assertOk()->assertViewHas('entries', fn ($entries) => $entries->total() === 1);
        foreach (['filtered' => 2, 'all' => 3] as $scope => $count) {
            $response = $this->get(route('admin.excel.export', ['module' => 'other', 'scope' => $scope, 'group_id' => $group->id]))->assertOk();
            $zip = new ZipArchive;
            $zip->open($response->baseResponse->getFile()->getPathname());
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertSame($count, substr_count($xml, '<row '));
            $this->assertStringContainsString('<v>100</v>', $xml);
            $zip->close();
        }
        $response = $this->get(route('admin.report', ['scope' => 'all', 'group_id' => $group->id]))->assertOk();
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('<v>300</v>', $zip->getFromName('xl/worksheets/sheet3.xml'));
        $zip->close();
    }

    private function preview(string $module, array $data): TestResponse
    {
        $path = app(ExcelReport::class)->workbook(['Data' => [ExcelImport::HEADERS[$module], ...$data]]);
        try {
            return $this->post(route('admin.excel.preview', $module), ['file' => new UploadedFile($path, 'data.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)]);
        } finally {
            unlink($path);
        }
    }
}
