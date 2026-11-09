<?php

namespace Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use LazilyRefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! str_ends_with((string) config('database.connections.'.config('database.default').'.database'), '_test')) {
            throw new \RuntimeException('Tes wajib memakai database terpisah dengan akhiran _test.');
        }
    }

    //
}
