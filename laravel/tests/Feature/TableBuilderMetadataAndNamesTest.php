<?php

namespace Tests\Feature;

use App\Services\TableBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TableBuilderMetadataAndNamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_system_table_name_is_blocked(): void
    {
        $svc = app(TableBuilderService::class);

        $this->expectException(ValidationException::class);

        $svc->create([
            'table' => 'migrations',
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
            ],
        ]);
    }

    public function test_metadata_persisted_if_dynamic_tables_exists(): void
    {
        $svc = app(TableBuilderService::class);

        $svc->create([
            'table' => 'zz_meta_demo',
            'timestamps' => false,
            'soft_deletes' => false,
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
                ['name' => 'title', 'type' => 'string', 'length' => 50],
            ],
        ]);

        $this->assertTrue(Schema::hasTable('zz_meta_demo'));
        $this->assertDatabaseHas('dynamic_tables', [
            'table' => 'zz_meta_demo',
        ]);
    }

    public function test_boolean_default_is_applied_on_insert(): void
    {
        $svc = app(TableBuilderService::class);

        $svc->create([
            'table' => 'zz_flags',
            'timestamps' => false,
            'soft_deletes' => false,
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
                ['name' => 'name', 'type' => 'string', 'length' => 50],
                ['name' => 'flag', 'type' => 'boolean', 'default_bool' => true],
            ],
        ]);

        DB::table('zz_flags')->insert([
            'name' => 'row1',
        ]);

        $row = DB::table('zz_flags')->first();
        $this->assertNotNull($row);
        $this->assertEquals(1, (int) $row->flag);
    }
}


