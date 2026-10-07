<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Console\Command;
use Tests\TestCase;

final class StageErpReadOnlyTest extends TestCase
{
    public function test_rejects_a_non_numeric_limit_before_connecting_to_erp(): void
    {
        $this->artisan('accounting:stage-erp', [
            '--limit' => 'not-a-number',
        ])->assertExitCode(Command::INVALID);
    }
}
