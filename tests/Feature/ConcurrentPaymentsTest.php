<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Installment;
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

class ConcurrentPaymentsTest extends TestCase
{
    /** These processes must see committed fixtures and independent MySQL snapshots. */
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
    public function test_two_admins_cannot_overpay_when_the_second_snapshot_predates_the_first_commit(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl to exercise concurrent row locks.');
        }
        $group = Group::factory()->create();
        $member = Member::factory()->for($group)->create();
        $admins = User::factory()->admin()->count(2)->create();
        $base = ['group_id' => $group->id, 'date' => '2026-11-15', 'member_id' => $member->id];
        app(TransactionService::class)->store($admins[0], $base + ['kind' => 'loan', 'request_key' => (string) Str::uuid(), 'principal' => 100000, 'months' => 1, 'rate' => '0']);
        $bill = Installment::firstOrFail();
        $data = $base + ['kind' => 'payment', 'request_key' => (string) Str::uuid(), 'installment_id' => $bill->id, 'amount' => 70000];
        $directory = sys_get_temp_dir().'/vsla-concurrency-'.Str::uuid();
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
                            DB::transaction(function () use ($admins, $data, $directory): void {
                                app(TransactionService::class)->store($admins[0], $data);
                                file_put_contents($directory.'/first-ready', '1');
                                $this->waitForFile($directory.'/second-snapshot');
                            });
                            file_put_contents($directory.'/first-result', 'saved');
                        } else {
                            $this->waitForFile($directory.'/first-ready');
                            DB::listen(function ($query) use ($directory): void {
                                if (str_contains($query->sql, 'cash_entries') && str_contains($query->sql, 'fingerprint') === false && str_contains($query->sql, 'request_key')) {
                                    file_put_contents($directory.'/second-snapshot', '1');
                                }
                            });
                            try {
                                app(TransactionService::class)->store($admins[1], $data);
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
            $this->assertSame('saved', file_get_contents($directory.'/first-result'));
            $this->assertSame('overpayment rejected', file_get_contents($directory.'/second-result'));
            $this->assertSame(70000, (int) Payment::sum('amount'));
            $this->assertDatabaseCount('payments', 1);
            $this->assertSame(30000, $bill->remaining());
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
                throw new \RuntimeException('Concurrent payment synchronization timed out.');
            }
            usleep(10000);
        }
    }
}
