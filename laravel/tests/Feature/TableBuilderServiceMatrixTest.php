<?php

namespace Tests\Feature;

use App\Services\TableBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TableBuilderServiceMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_invalid_and_reserved_names_are_rejected(): void
    {
        $svc = app(TableBuilderService::class);

        $this->expectException(ValidationException::class);
        $svc->create([
            'table' => '1bad',
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
            ],
        ]);
    }

    public function test_various_column_types_created(): void
    {
        $svc = app(TableBuilderService::class);

        $svc->create([
            'table' => 'zz_types',
            'timestamps' => true,
            'soft_deletes' => true,
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
                ['name' => 'name', 'type' => 'string', 'length' => 120, 'comment' => 'Person name'],
                ['name' => 'age', 'type' => 'integer', 'unsigned' => true],
                ['name' => 'score', 'type' => 'decimal', 'precision' => 8, 'scale' => 2, 'default' => '0.00'],
                ['name' => 'active', 'type' => 'boolean', 'default_bool' => false],
                ['name' => 'bio', 'type' => 'text'],
                ['name' => 'meta', 'type' => 'json'],
                ['name' => 'uuid_col', 'type' => 'uuid', 'unique' => true],
            ],
        ]);

        $this->assertTrue(Schema::hasTable('zz_types'));
        $this->assertTrue(Schema::hasColumns('zz_types', [
            'id','name','age','score','active','bio','meta','uuid_col','created_at','updated_at','deleted_at'
        ]));
    }

    public function test_index_and_fulltext_preview_strings(): void
    {
        $svc = app(TableBuilderService::class);

        $preview = $svc->preview([
            'table' => 'zz_preview',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'index' => 'index'],
                ['name' => 'content', 'type' => 'text', 'index' => 'fulltext'],
            ],
        ]);

        $this->assertStringContainsString('// also: $table->index(\'title\')', $preview['preview']);
        $this->assertStringContainsString('// also: $table->fullText(\'content\')', $preview['preview']);
    }
}
