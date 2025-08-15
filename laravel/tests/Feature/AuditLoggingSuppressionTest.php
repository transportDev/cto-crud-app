<?php

namespace Tests\Feature;

use App\Filament\Pages\DynamicCrud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditLoggingSuppressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_dynamic_crud_audit_failures_do_not_throw(): void
    {
        // Force the audit path to run
        \Illuminate\Support\Facades\Schema::shouldReceive('hasTable')->andReturn(true);

        // Mock DB::table()->insert() to throw, while ensuring DB::table is called
        DB::shouldReceive('table')->once()->with('admin_audit_logs')->andReturn(new class {
            public function insert($data) { throw new \RuntimeException('simulated-failure'); }
        });

        $page = app(DynamicCrud::class);

        // Call protected audit method via reflection; should not throw
        $ref = new \ReflectionClass($page);
        $method = $ref->getMethod('audit');
        $method->setAccessible(true);

        $method->invoke($page, 'test.action', ['foo' => 'bar']);

        // If we reach here, suppression worked
        $this->assertTrue(true);
    }
}


