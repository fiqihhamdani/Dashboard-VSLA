<?php

namespace Tests\Feature;

use App\Models\FinancialRevision;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Payment;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class ConcurrentCorrectionsTest extends TestCase
{
    public function refreshDatabase(): void
    {
        $this->beforeRefreshingDatabase();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        });
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_payment_waiting_for_correction_cannot_use_the_old_installment_amount(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl for concurrent financial corrections.');
        }
        $member = Member::factory()->create();
        $admins = User::factory()->admin()->count(2)->create();
        $base = ['group_id' => $member->group_id, 'member_id' => $member->id, 'date' => '2026-11-20'];
        $loanData = $base + ['kind' => 'loan', 'request_key' => (string) Str::uuid(), 'principal' => 100000, 'rate' => '0', 'months' => 1];
        $cash = app(TransactionService::class)->store($admins[0], $loanData);
        $bill = Installment::firstOrFail();
        $paymentData = $base + ['kind' => 'payment', 'request_key' => (string) Str::uuid(), 'installment_id' => $bill->id, 'amount' => 70000];
        $directory = sys_get_temp_dir().'/vsla-correction-'.Str::uuid();
        mkdir($directory, 0700);
        DB::purge();
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        if ($i === 0) {
                            DB::transaction(function () use ($admins, $cash, $loanData, $directory): void {
                                app(TransactionService::class)->revise($admins[0], $cash, array_replace($loanData, ['principal' => 60000]), 'Koreksi sebelum pembayaran', 0);
                                file_put_contents($directory.'/first-ready', '1');
                                $this->waitForFile($directory.'/second-snapshot');
                            });
                            file_put_contents($directory.'/first-result', 'corrected');
                        } else {
                            $this->waitForFile($directory.'/first-ready');
                            DB::listen(function ($query) use ($directory): void {
                                if (str_contains($query->sql, 'cash_entries') && str_contains($query->sql, 'request_key')) {
                                    file_put_contents($directory.'/second-snapshot', '1');
                                }
                            });
                            try {
                                app(TransactionService::class)->store($admins[1], $paymentData);
                                file_put_contents($directory.'/second-result', 'unexpected payment');
                            } catch (ValidationException $exception) {
                                file_put_contents($directory.'/second-result', isset($exception->errors()['amount']) ? 'overpayment rejected' : 'wrong validation');
                            }
                        }
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($directory.'/error-'.$i, $exception->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            $this->assertSame('corrected', file_get_contents($directory.'/first-result'));
            $this->assertSame('overpayment rejected', file_get_contents($directory.'/second-result'));
            $this->assertSame(60000, Loan::firstOrFail()->total);
            $this->assertSame(60000, $bill->fresh()->remaining());
            $this->assertSame(0, Payment::count());
            $this->assertSame(1, FinancialRevision::count());
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 8;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Concurrent correction synchronization timed out.');
            }
            usleep(10000);
        }
    }
}
