<?php

namespace Tests\Feature;

use App\Services\TableBuilderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TableBuilderServiceTest extends TestCase
{
    public function test_preview_includes_expected_blueprint_lines(): void
    {
        $svc = app(TableBuilderService::class);
        $def = [
            'table' => 'alpha',
            'timestamps' => true,
            'soft_deletes' => true,
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true],
                ['name' => 'code', 'type' => 'string', 'length' => 120, 'unique' => true],
                ['name' => 'price', 'type' => 'decimal', 'precision' => 10, 'scale' => 2],
            ],
        ];

        $res = $svc->preview($def);
        $this->assertStringContainsString("Schema::create('alpha'", $res['preview']);
        $this->assertStringContainsString("bigIncrements('id')", $res['preview']);
        $this->assertStringContainsString("string('code', 120)", $res['preview']);
        $this->assertStringContainsString("unique()", $res['preview']);
        $this->assertStringContainsString("decimal('price', 10, 2)", $res['preview']);
        $this->assertStringContainsString("timestamps()", $res['preview']);
        $this->assertStringContainsString("softDeletes()", $res['preview']);
    }

    public function test_list_user_tables_includes_created_table_and_excludes_system(): void
    {
        Schema::create('beta', function (Blueprint $table) {
            $table->increments('id');
        });

        $svc = app(TableBuilderService::class);
        $list = $svc->listUserTables();

        $this->assertContains('beta', $list);
        $this->assertNotContains('migrations', $list);
    }
}
