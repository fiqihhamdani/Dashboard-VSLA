<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Saving;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function store(User $admin, array $data): CashEntry
    {
        return $this->write($admin, $data);
    }

    private function write(User $admin, array $data, ?CashEntry $editing = null): CashEntry
    {
        return DB::transaction(function () use ($admin, $data, $editing) {
            User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $previous = $editing ? null : CashEntry::withTrashed()->where('created_by', $admin->id)->where('request_key', $data['request_key'])->first();
            if ($previous) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    $this->fail('request_key', 'Formulir ini sudah dipakai untuk data lain. Buka formulir baru.');
                }

                return $previous;
            }
            $group = Group::whereKey($data['group_id'])->lockForUpdate()->firstOrFail();
            if (! $group->is_active && $data['kind'] !== 'payment') {
                $this->fail('group_id', 'Kelompok nonaktif tidak dapat menerima transaksi baru selain pembayaran tagihan lama.');
            }
            $amount = 0;
            $direction = 'in';
            $description = '';
            $loanDetails = [];
            switch ($data['kind']) {
                case 'saving':
                    foreach ($data['rows'] as $index => $row) {
                        $member = $this->member((int) $row['member_id'], $group, true, "rows.$index.member_id");
                        if ($data['direction'] === 'out') {
                            $this->checkWithdrawal($member, $data['type'], (int) $row['amount'], $data['date'], "rows.$index.amount");
                        }
                        $amount += (int) $row['amount'];
                    }
                    $direction = $data['direction'];
                    $description = ($direction === 'in' ? 'Setoran' : 'Penarikan').' simpanan '.$data['type'].' ('.count($data['rows']).' anggota)';
                    break;
                case 'loan':
                    $this->member((int) $data['member_id'], $group, true);
                    $rateParts = explode('.', (string) $data['rate']);
                    $bps = (int) $rateParts[0] * 100 + (int) str_pad($rateParts[1] ?? '', 2, '0');
                    $interest = intdiv((int) $data['principal'] * $bps * (int) $data['months'] + 5000, 10000);
                    $amount = (int) $data['principal'];
                    $direction = 'out';
                    $description = 'Pencairan pinjaman';
                    $loanDetails = ['principal' => $amount, 'rate_bps' => $bps, 'months' => (int) $data['months'], 'interest' => $interest, 'total' => $amount + $interest];
                    if ($loanDetails['total'] < $loanDetails['months']) {
                        $this->fail('principal', 'Total pinjaman harus cukup untuk cicilan minimal Rp1 setiap bulan.');
                    }
                    break;
                case 'payment':
                    $this->member((int) $data['member_id'], $group, false);
                    $bill = Installment::with('loan')->whereKey($data['installment_id'])->lockForUpdate()->firstOrFail();
                    if ($bill->loan->member_id !== (int) $data['member_id']) {
                        $this->fail('installment_id', 'Tagihan tidak dimiliki anggota yang dipilih.');
                    }
                    if ($data['date'] < $bill->loan->disbursed_at->toDateString()) {
                        $this->fail('date', 'Tanggal pembayaran tidak boleh sebelum tanggal pencairan.');
                    }
                    $amount = (int) $data['amount'];
                    // A locking read sees payments committed while this transaction waited for the bill lock.
                    $paid = (int) $bill->payments()->lockForUpdate()->get()->sum('amount');
                    if ($amount > $bill->amount - $paid) {
                        $this->fail('amount', 'Pembayaran melebihi sisa tagihan. Periksa nominal yang masih terbuka.');
                    }
                    $description = 'Pembayaran angsuran ke-'.$bill->sequence;
                    break;
                case 'other': $amount = (int) $data['amount'];
                    $direction = $data['direction'];
                    $description = $data['description'];
                    break;
            }
            $attributes = ['group_id' => $group->id, 'date' => $data['date'], 'direction' => $direction, 'category' => $data['kind'], 'amount' => $amount, 'description' => $description] + ($data['kind'] === 'loan' && array_key_exists('proof_url', $data) ? ['proof_url' => $data['proof_url']] : []);
            if ($editing) {
                $editing->update($attributes);
                $cash = $editing;
            } else {
                $cash = CashEntry::create($attributes + ['created_by' => $admin->id, 'request_key' => $data['request_key'], 'fingerprint' => $fingerprint]);
            }
            if ($data['kind'] === 'saving') {
                foreach ($data['rows'] as $row) {
                    Saving::create(['cash_entry_id' => $cash->id, 'member_id' => $row['member_id'], 'type' => $data['type'], 'amount' => (int) $row['amount']]);
                }
            }
            if ($data['kind'] === 'loan') {
                $existing = $editing ? Loan::where('cash_entry_id', $cash->id)->firstOrFail() : null;
                if ($existing && $existing->member_id !== (int) $data['member_id'] && Payment::whereHas('installment', fn ($q) => $q->where('loan_id', $existing->id))->exists()) {
                    $this->fail('member_id', 'Pinjaman yang sudah dibayar tidak dapat dialihkan ke anggota lain.');
                }
                $loan = $existing ?? new Loan;
                $loan->fill($loanDetails + ['member_id' => $data['member_id'], 'cash_entry_id' => $cash->id, 'disbursed_at' => $data['date']])->save();
                $part = intdiv($loan->total, $loan->months);
                $anchor = CarbonImmutable::parse($data['date']);
                foreach ($loan->installments()->with('payments')->lockForUpdate()->get() as $oldBill) {
                    $newAmount = $oldBill->sequence === $loan->months ? $loan->total - $part * ($loan->months - 1) : $part;
                    if ($oldBill->payments->isNotEmpty() && ($oldBill->sequence > $loan->months || $oldBill->payments->sum('amount') > $newAmount)) {
                        $this->fail('principal', 'Perubahan menghapus cicilan yang sudah dibayar atau membuat tagihan lebih kecil daripada pembayarannya.');
                    }
                    if ($oldBill->payments->contains(fn ($payment) => $payment->paid_at->toDateString() < $data['date'])) {
                        $this->fail('date', 'Tanggal pencairan baru tidak boleh setelah pembayaran yang sudah tercatat.');
                    }
                }
                for ($i = 1; $i <= $loan->months; $i++) {
                    $billData = ['due_date' => $anchor->addMonthsNoOverflow($i)->toDateString(), 'amount' => $i === $loan->months ? $loan->total - $part * ($i - 1) : $part];
                    $scheduled = $loan->installments()->withTrashed()->firstOrNew(['sequence' => $i]);
                    $scheduled->fill($billData);
                    if ($scheduled->trashed()) {
                        $scheduled->restore();
                    } else {
                        $scheduled->save();
                    }
                }
                $loan->installments()->where('sequence', '>', $loan->months)->delete();
            }
            if ($data['kind'] === 'payment') {
                $payment = $editing ? Payment::withTrashed()->where('cash_entry_id', $cash->id)->firstOrFail() : new Payment;
                $payment->fill(['installment_id' => $bill->id, 'cash_entry_id' => $cash->id, 'paid_at' => $data['date'], 'amount' => $amount]);
                if ($payment->trashed()) {
                    $payment->restore();
                } else {
                    $payment->save();
                }
            }

            return $cash;
        }, 3);
    }

    public function revise(User $admin, CashEntry $cash, array $data, string $reason, int $version): CashEntry
    {
        return DB::transaction(function () use ($admin, $cash, $data, $reason, $version): CashEntry {
            $locked = $this->lockCorrection($admin, $cash, [(int) $data['group_id']]);
            $this->checkVersion($locked, $version);
            if ($data['kind'] !== $locked->category) {
                $this->fail('kind', 'Jenis transaksi tidak dapat diubah.');
            }
            $before = $this->snapshot($locked);
            $pairs = $locked->savings()->get(['member_id', 'type'])->toArray();
            $locked->savings()->delete();
            Payment::where('cash_entry_id', $locked->id)->delete();
            $updated = $this->write($admin, $data, $locked);
            $pairs = array_merge($pairs, $updated->savings()->get(['member_id', 'type'])->toArray());
            $this->validateSavingHistory($pairs);
            $updated->increment('version');
            $this->audit($admin, $updated, 'edit', $reason, $before);

            return $updated;
        }, 3);
    }

    public function cancel(User $admin, CashEntry $cash, string $reason, int $version): CashEntry
    {
        return DB::transaction(function () use ($admin, $cash, $reason, $version): CashEntry {
            $locked = $this->lockCorrection($admin, $cash);
            if ($locked->trashed()) {
                return $locked;
            }
            $this->checkVersion($locked, $version);
            $before = $this->snapshot($locked);
            $pairs = $locked->savings()->get(['member_id', 'type'])->toArray();
            $loan = Loan::where('cash_entry_id', $locked->id)->lockForUpdate()->first();
            if ($loan) {
                if (Payment::whereHas('installment', fn ($q) => $q->where('loan_id', $loan->id))->lockForUpdate()->exists()) {
                    $this->fail('cash', 'Pinjaman masih memiliki pembayaran. Batalkan pembayaran terkait terlebih dahulu sebelum membatalkan pencairan.');
                }
                $loan->installments()->delete();
                $loan->delete();
            }
            $locked->savings()->delete();
            Payment::where('cash_entry_id', $locked->id)->delete();
            $locked->increment('version');
            $locked->delete();
            $this->validateSavingHistory($pairs);
            $this->audit($admin, $locked, 'cancel', $reason, $before);

            return $locked;
        }, 3);
    }

    public function changeProof(User $admin, CashEntry $cash, ?string $url): CashEntry
    {
        return DB::transaction(function () use ($admin, $cash, $url): CashEntry {
            $locked = $this->lockCorrection($admin, $cash);
            if ($locked->trashed()) {
                $this->fail('cash', 'Transaksi dibatalkan tidak dapat diubah.');
            }
            $before = $this->snapshot($locked);
            $locked->update(['proof_url' => $url]);
            $locked->increment('version');
            $this->audit($admin, $locked, 'proof', 'Perubahan tautan bukti pencairan.', $before);

            return $locked;
        }, 3);
    }

    private function lockCorrection(User $admin, CashEntry $cash, array $additionalGroups = []): CashEntry
    {
        User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
        Group::whereIn('id', array_unique([$cash->group_id, ...$additionalGroups]))->orderBy('id')->lockForUpdate()->get();
        $locked = CashEntry::withTrashed()->whereKey($cash->id)->lockForUpdate()->firstOrFail();
        if ($locked->group_id !== $cash->group_id) {
            $this->fail('version', 'Transaksi telah dipindahkan admin lain. Muat ulang halaman.');
        }

        return $locked;
    }

    private function checkVersion(CashEntry $cash, int $version): void
    {
        if ($cash->trashed()) {
            $this->fail('cash', 'Transaksi dibatalkan tidak dapat diedit.');
        }
        if ($cash->version !== $version) {
            $this->fail('version', 'Transaksi telah diubah admin lain. Muat ulang dan periksa nilai terbaru sebelum menyimpan.');
        }
    }

    private function validateSavingHistory(array $pairs): void
    {
        $seen = [];
        foreach ($pairs as $pair) {
            $key = $pair['member_id'].':'.$pair['type'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            Member::whereKey($pair['member_id'])->lockForUpdate()->firstOrFail();
            $movements = Saving::query()->join('cash_entries', 'cash_entries.id', '=', 'savings.cash_entry_id')
                ->whereNull('cash_entries.deleted_at')->where('savings.member_id', $pair['member_id'])->where('savings.type', $pair['type'])
                ->select('cash_entries.direction', 'savings.amount')->orderBy('cash_entries.date')->orderBy('cash_entries.id')->get();
            $balance = 0;
            foreach ($movements as $movement) {
                $balance += $movement->direction === 'in' ? (int) $movement->amount : -(int) $movement->amount;
                if ($balance < 0) {
                    $this->fail('rows', 'Koreksi ditolak karena membuat saldo simpanan '.$pair['type'].' negatif pada riwayat anggota #'.$pair['member_id'].'.');
                }
            }
        }
    }

    private function snapshot(CashEntry $cash): array
    {
        $record = CashEntry::withTrashed()->findOrFail($cash->id);
        $loan = Loan::with(['installments' => fn ($q) => $q->with('payments')->orderBy('sequence')])->where('cash_entry_id', $cash->id)->first();

        return ['cash' => $record->toArray(), 'savings' => $record->savings()->get()->toArray(), 'loan' => $loan?->toArray(), 'payment' => Payment::where('cash_entry_id', $cash->id)->first()?->toArray()];
    }

    private function audit(User $admin, CashEntry $cash, string $action, string $reason, array $before): void
    {
        $cash->revisions()->create(['admin_id' => $admin->id, 'action' => $action, 'reason' => $reason, 'before' => $before, 'after' => $this->snapshot($cash)]);
    }

    private function member(int $id, Group $group, bool $active, string $field = 'member_id'): Member
    {
        $member = Member::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($member->group_id !== $group->id || ($active && ! $member->is_active)) {
            $this->fail($field, 'Pilih anggota aktif dari kelompok yang dipilih.');
        }

        return $member;
    }

    private function checkWithdrawal(Member $member, string $type, int $amount, string $date, string $field): void
    {
        $movements = Saving::query()->join('cash_entries', 'cash_entries.id', '=', 'savings.cash_entry_id')->whereNull('cash_entries.deleted_at')->where('member_id', $member->id)->where('type', $type)->select('cash_entries.date', 'cash_entries.direction', 'savings.amount', 'cash_entries.id')->orderBy('cash_entries.date')->orderBy('cash_entries.id')->get();
        $balance = 0;
        $applied = false;
        foreach ($movements as $movement) {
            if (! $applied && $movement->date > $date) {
                $balance -= $amount;
                if ($balance < 0) {
                    $this->fail($field, 'Saldo simpanan '.$type.' belum cukup pada tanggal penarikan.');
                }
                $applied = true;
            }
            $balance += $movement->direction === 'in' ? (int) $movement->amount : -(int) $movement->amount;
            if ($balance < 0) {
                $this->fail($field, 'Saldo simpanan '.$type.' tidak cukup pada tanggal ini atau akan membuat saldo riwayat berikutnya negatif.');
            }
        }
        if (! $applied) {
            $balance -= $amount;
        }
        if ($balance < 0) {
            $this->fail($field, 'Saldo simpanan '.$type.' tidak cukup untuk penarikan ini.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
