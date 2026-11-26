<?php

namespace App\Http\Controllers;

use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Payment;
use App\Services\ExcelImport;
use App\Services\ExcelReport;
use App\Services\ExcelWorkbook;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelController extends Controller
{
    public function form(string $module): View
    {
        $this->importable($module);

        return view('admin.import', compact('module'));
    }

    public function template(string $module, ExcelReport $excel): BinaryFileResponse
    {
        $this->importable($module);
        $instructions = [
            ['Petunjuk', 'Ketentuan'],
            ['Format', 'Sheet Data; jangan mengubah nama/urutan kolom. Maksimal 500 baris, 5 MB (20 MB terurai).'],
            ['Wajib', 'Semua kolom wajib kecuali link_legalitas dan link_bukti. Tidak menerima formula, macro, atau sumber eksternal.'],
            ['Tanggal', 'Teks YYYY-MM-DD, misalnya 2026-11-27. Jangan gunakan serial tanggal Excel.'],
            ['Angka', 'Rupiah bulat tanpa Rp/pemisah ribuan; bunga_bulanan memakai titik desimal. aktif: 1 atau 0.'],
            ['Referensi', 'kelompok_id, anggota_id dan tagihan_id harus sudah ada. Lihat ID pada daftar/detail dan hasil export.'],
            ['Transaksi', 'kode_transaksi berupa UUID unik per transaksi untuk admin yang sama. Simpan kode tetap saat mencoba ulang file.'],
            ['Simpanan', 'Satu anggota per baris dan kode_transaksi. jenis: pokok/wajib/sukarela; arah: in/out.'],
            ['Duplikasi', 'Kode kelompok, nama anggota dalam kelompok yang sama, atau kode transaksi berulang ditolak. Tidak ada overwrite.'],
            ['Keamanan', 'Nilai identitas dan telepon, jika disertakan pada sistem di masa depan, harus berupa teks. Jangan menambah kolom di luar template.'],
            ['Drive', 'Hanya URL file https://drive.google.com. Atur izin file sendiri; aplikasi tidak mengubah izin atau menghapus file.'],
            ['Penyimpanan', 'Pratinjau tidak menyimpan transaksi. Konfirmasi divalidasi ulang; seluruh file dibatalkan jika satu baris gagal.'],
        ];

        return $this->download($excel->workbook(['Data' => [ExcelImport::HEADERS[$module]], 'Petunjuk' => $instructions]), 'template-'.$module.'.xlsx');
    }

    public function preview(Request $request, string $module, ExcelWorkbook $workbook, ExcelImport $import): View
    {
        $this->importable($module);
        $request->validate(['file' => 'required|file|mimes:xlsx|extensions:xlsx|max:5120']);
        $rows = $workbook->read($request->file('file')->getRealPath(), ExcelImport::HEADERS[$module]);
        try {
            $rowErrors = $import->preview($module, $rows, $request->user());
        } catch (QueryException) {
            throw ValidationException::withMessages(['file' => 'Data terkait berubah atau tidak lagi tersedia. Periksa referensi dan coba ulang. Tidak ada data yang disimpan.']);
        }
        $token = null;
        if (! $rowErrors) {
            $token = (string) Str::uuid();
            $saved = Storage::disk('local')->put('imports/'.$token.'.json', json_encode(['module' => $module, 'admin_id' => $request->user()->id, 'expires' => now()->addMinutes(30)->timestamp, 'rows' => $rows], JSON_THROW_ON_ERROR));
            if (! $saved) {
                throw ValidationException::withMessages(['file' => 'Pratinjau tidak dapat disimpan. Periksa penyimpanan server lalu coba ulang.']);
            }
            $request->session()->put('excel_import.'.$token, $module);
        }

        return view('admin.import-preview', compact('module', 'rows', 'rowErrors', 'token'));
    }

    public function commit(Request $request, string $module, ExcelImport $import): RedirectResponse
    {
        $this->importable($module);
        $request->validate(['token' => 'required|uuid', 'confirmed' => 'accepted']);
        $token = $request->string('token')->toString();
        abort_unless($request->session()->get('excel_import.'.$token) === $module, 419);
        $path = 'imports/'.$token.'.json';
        $stored = Storage::disk('local')->get($path);
        $payload = $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! $payload || $payload['module'] !== $module || $payload['admin_id'] !== $request->user()->id || $payload['expires'] < now()->timestamp) {
            Storage::disk('local')->delete($path);
            $request->session()->forget('excel_import.'.$token);
            throw ValidationException::withMessages(['file' => 'Pratinjau kedaluwarsa atau tidak tersedia. Unggah ulang file.']);
        }
        try {
            $import->commit($module, $payload['rows'], $request->user());
        } catch (QueryException) {
            throw ValidationException::withMessages(['file' => 'Data berubah saat import. Seluruh file dibatalkan; periksa duplikasi dan referensi lalu unggah ulang.']);
        }
        Storage::disk('local')->delete($path);
        $request->session()->forget('excel_import.'.$token);

        return redirect()->route('admin.excel.form', $module)->with('status', count($payload['rows']).' baris berhasil diimport. Semua data tersimpan; tidak ada data lama yang ditimpa.');
    }

    public function export(Request $request, string $module, ExcelReport $excel): BinaryFileResponse
    {
        abort_unless(in_array($module, [...array_keys(ExcelImport::HEADERS), 'loans'], true), 404);
        $filters = $request->validate(['scope' => 'required|in:all,filtered', 'q' => 'nullable|string|max:100', 'group_id' => 'nullable|integer|exists:groups,id', 'is_active' => 'nullable|boolean', 'start' => 'nullable|date_format:Y-m-d', 'end' => $request->filled('start') ? 'nullable|date_format:Y-m-d|after_or_equal:start' : 'nullable|date_format:Y-m-d']);
        if ($filters['scope'] === 'all') {
            $filters = [];
        }
        $query = match ($module) {
            'groups' => Group::query()->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($sub) => $sub->where('name', 'like', "%$value%")->orWhere('code', 'like', "%$value%"))),
            'members' => Member::with('group')->when($filters['group_id'] ?? null, fn ($q, $id) => $q->where('group_id', $id))->when($filters['q'] ?? null, fn ($q, $value) => $q->where('name', 'like', "%$value%"))->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active'])),
            'loans' => Loan::with(['member.group', 'cashEntry', 'installments' => fn ($q) => $q->withSum('payments', 'amount')])->when($filters['group_id'] ?? null, fn ($q, $id) => $q->whereHas('member', fn ($m) => $m->where('group_id', $id)))->when($filters['q'] ?? null, fn ($q, $value) => $q->whereHas('member', fn ($m) => $m->where('name', 'like', "%$value%"))),
            default => CashEntry::with(['group', 'savings.member'])->where('category', $module)->when($filters['group_id'] ?? null, fn ($q, $id) => $q->where('group_id', $id))->when($filters['q'] ?? null, fn ($q, $value) => $q->where('description', 'like', "%$value%"))->when($filters['start'] ?? null, fn ($q, $date) => $q->where('date', '>=', $date))->when($filters['end'] ?? null, fn ($q, $date) => $q->where('date', '<=', $date)),
        };
        if ((clone $query)->count() > 10000) {
            throw ValidationException::withMessages(['export' => 'Export maksimal 10.000 catatan. Persempit filter sebelum mengunduh.']);
        }
        $rows = match ($module) {
            'groups' => [['ID kelompok', 'Kode kelompok', 'Nama', 'Lokasi', 'Aktif', 'Link legalitas Google Drive']],
            'members' => [['ID anggota', 'ID kelompok', 'Kode kelompok', 'Nama', 'Aktif']],
            'loans' => [['ID pinjaman', 'ID kas', 'ID anggota', 'Nama anggota', 'ID kelompok', 'Tanggal pencairan', 'Pokok', 'Bunga bulanan (%)', 'Tenor', 'Bunga total', 'Total tagihan', 'Sisa tagihan', 'Link bukti pencairan']],
            'saving' => [['ID kas', 'Kode transaksi', 'ID kelompok', 'Kode kelompok', 'Tanggal', 'Arah', 'ID anggota', 'Nama anggota', 'Jenis simpanan', 'Nominal', 'Total kas']],
            'loan' => [['ID kas', 'Kode transaksi', 'ID kelompok', 'Kode kelompok', 'Tanggal', 'Arah', 'Nominal', 'Uraian', 'Link bukti pencairan', 'ID pinjaman', 'ID anggota']],
            'payment' => [['ID kas', 'Kode transaksi', 'ID kelompok', 'Kode kelompok', 'Tanggal', 'Arah', 'Nominal', 'Uraian', 'ID tagihan', 'ID pinjaman', 'ID anggota']],
            default => [['ID kas', 'Kode transaksi', 'ID kelompok', 'Kode kelompok', 'Tanggal', 'Arah', 'Nominal', 'Uraian', 'Link bukti pencairan']],
        };
        $payments = $module === 'payment' ? Payment::with('installment.loan')->whereIn('cash_entry_id', (clone $query)->pluck('id'))->get()->keyBy('cash_entry_id') : collect();
        $loans = $module === 'loan' ? Loan::whereIn('cash_entry_id', (clone $query)->pluck('id'))->get()->keyBy('cash_entry_id') : collect();
        foreach ($query->orderBy('id')->get() as $record) {
            if ($module === 'groups') {
                $rows[] = [$record->id, $record->code, $record->name, $record->location, (int) $record->is_active, $record->legality_url ?? ''];
            } elseif ($module === 'members') {
                $rows[] = [$record->id, $record->group_id, $record->group->code, $record->name, (int) $record->is_active];
            } elseif ($module === 'loans') {
                $rows[] = [$record->id, $record->cash_entry_id, $record->member_id, $record->member->name, $record->member->group_id, $record->disbursed_at->toDateString(), $record->principal, number_format($record->rate_bps / 100, 2, '.', ''), $record->months, $record->interest, $record->total, $record->total - (int) $record->installments->sum('payments_sum_amount'), $record->cashEntry->proof_url ?? ''];
            } elseif ($module === 'saving') {
                foreach ($record->savings as $saving) {
                    $rows[] = [$record->id, $record->request_key, $record->group_id, $record->group->code, $record->date->toDateString(), $record->direction, $saving->member_id, $saving->member->name, $saving->type, $saving->amount, $record->amount];
                }
            } else {
                $row = [$record->id, $record->request_key, $record->group_id, $record->group->code, $record->date->toDateString(), $record->direction, $record->amount, $record->description];
                $payment = $payments->get($record->id);
                if ($module === 'loan') {
                    $loan = $loans->get($record->id);
                    $rows[] = [...$row, $record->proof_url ?? '', $loan?->id, $loan?->member_id];

                    continue;
                }
                $rows[] = [...$row, ...($module === 'payment' ? [$payment?->installment_id, $payment?->installment->loan_id, $payment?->installment->loan->member_id] : [$record->proof_url ?? ''])];
            }
            if (count($rows) > 10001) {
                throw ValidationException::withMessages(['export' => 'Export maksimal 10.000 baris. Persempit filter.']);
            }
        }

        return $this->download($excel->workbook(['Data' => $rows]), 'VSLA-'.$module.'-'.now()->format('Y-m-d').'.xlsx');
    }

    private function importable(string $module): void
    {
        abort_unless(array_key_exists($module, ExcelImport::HEADERS), 404);
    }

    private function download(string $path, string $name): BinaryFileResponse
    {
        return response()->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
}
