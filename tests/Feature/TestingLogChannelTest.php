<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** ENV-1: tests log to storage/logs/testing.log, never the shared dev laravel.log. */
class TestingLogChannelTest extends TestCase
{
    public function test_tests_use_the_separate_testing_channel(): void
    {
        $this->assertSame('testing', config('logging.default'));
        $this->assertSame(storage_path('logs/testing.log'), config('logging.channels.testing.path'));
        $this->assertStringNotContainsString('laravel.log', (string) config('logging.channels.testing.path'));
    }

    public function test_an_error_logged_in_a_test_does_not_reach_laravel_log(): void
    {
        $devLog = storage_path('logs/laravel.log');
        $before = is_file($devLog) ? filesize($devLog) : 0;
        $marker = 'env1-marker-'.uniqid();

        Log::error($marker);
        clearstatcache();

        $this->assertSame($before, is_file($devLog) ? filesize($devLog) : 0);
        $this->assertStringContainsString($marker, (string) file_get_contents(storage_path('logs/testing.log')));
    }
}
