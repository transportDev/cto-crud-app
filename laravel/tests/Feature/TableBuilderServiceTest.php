<?php

namespace Tests\Feature;

use App\Services\TableBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TableBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure migrations run so our audit/meta tables exist (if needed by service)
        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup for tables we create
        foreach (['zz_demo_orders', 'zz_duplicate_cols', 'zz_reserved'] as $t) {
            try { Schema::dropIfExists($t); } catch (\Throwable $e) {}
        }
        parent::tearDown();
    }

    public function test_can_preview_definition(): void
    {
        $svc = app(TableBuilderService::class);

        $result = $svc->preview([
            'table' => 'zz_demo_orders',
            'timestamps' => true,
            'soft_deletes' => true,
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
                ['name' => 'order_no', 'type' => 'string', 'length' => 64, 'unique' => true],
                ['name' => 'total', 'type' => 'decimal', 'precision' => 12, 'scale' => 2, 'default' => '0.00'],
                ['name' => 'is_active', 'type' => 'boolean', 'default_bool' => true],
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('preview', $result);
        $this->assertStringContainsString("Schema::create('zz_demo_orders'", $result['preview']);
        $this->assertStringContainsString("$"."table->timestamps()", $result['preview']);
    }

    public function test_create_table_with_columns_and_options(): void
    {
        $svc = app(TableBuilderService::class);

        $svc->create([
            'table' => 'zz_demo_orders',
            'timestamps' => true,
            'soft_deletes' => true,
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
                ['name' => 'order_no', 'type' => 'string', 'length' => 64, 'unique' => true],
                ['name' => 'total', 'type' => 'decimal', 'precision' => 12, 'scale' => 2, 'default' => '0.00'],
                ['name' => 'is_active', 'type' => 'boolean', 'default_bool' => true],
            ],
        ]);

        $this->assertTrue(Schema::hasTable('zz_demo_orders'));
        $this->assertTrue(Schema::hasColumns('zz_demo_orders', [
            'id', 'order_no', 'total', 'is_active', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_duplicate_column_names_are_blocked(): void
    {
        $svc = app(TableBuilderService::class);

        $this->expectException(ValidationException::class);

        $svc->create([
            'table' => 'zz_duplicate_cols',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string'],
            ],
        ]);
    }

    public function test_reserved_table_name_is_blocked(): void
    {
        $svc = app(TableBuilderService::class);

        $this->expectException(ValidationException::class);

        $svc->create([
            'table' => 'select',
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true],
            ],
        ]);
    }
}