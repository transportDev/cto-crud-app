<?php

namespace Tests\Feature;

use App\Services\SchemaFieldService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class SchemaFieldServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Minimal table for analysis context
    Schema::dropIfExists('posts');
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
        });
    }

    public function test_analyze_string_with_length_nullable_and_index(): void
    {
        $svc = app(SchemaFieldService::class);

        $res = $svc->analyze('posts', [
            'name' => 'slug',
            'type' => 'string',
            'length' => 190,
            'nullable' => true,
            'index' => 'index',
        ]);

        $this->assertStringContainsString("\$table->string('slug', 190)->nullable()", $res['migration_php']);
        $this->assertStringContainsString('ALTER TABLE `posts` ADD COLUMN `slug` VARCHAR(190) NULL', $res['estimated_sql']);
        $this->assertStringContainsString('CREATE INDEX `posts_slug_index`', $res['estimated_sql']);
        $this->assertSame('safe', $res['impact']);
    }

    public function test_analyze_decimal_with_precision_scale_and_default(): void
    {
        $svc = app(SchemaFieldService::class);
        $res = $svc->analyze('posts', [
            'name' => 'price',
            'type' => 'decimal',
            'precision' => 12,
            'scale' => 2,
            'nullable' => false,
            'default' => '0.00',
        ]);

        $this->assertStringContainsString("\$table->decimal('price', 12, 2)", $res['migration_php']);
        $this->assertStringContainsString('DECIMAL(12,2) NOT NULL DEFAULT 0.00', $res['estimated_sql']);
    }

    public function test_analyze_boolean_with_default_bool(): void
    {
        $svc = app(SchemaFieldService::class);
        $res = $svc->analyze('posts', [
            'name' => 'is_published',
            'type' => 'boolean',
            'default_bool' => true,
        ]);

        $this->assertStringContainsString("boolean('is_published')", $res['migration_php']);
        $this->assertStringContainsString("default(true)", $res['migration_php']);
        $this->assertStringContainsString('TINYINT(1) NULL DEFAULT 1', $res['estimated_sql']);
    }

    public function test_warnings_not_null_without_default_on_non_empty_table(): void
    {
        DB::table('posts')->insert(['title' => 'A']);

        $svc = app(SchemaFieldService::class);
        $res = $svc->analyze('posts', [
            'name' => 'category',
            'type' => 'string',
            'nullable' => false,
        ]);

        $this->assertNotEmpty($res['warnings']);
        $this->assertSame('risky', $res['impact']);
    }

    public function test_warnings_unique_with_default_on_multi_rows(): void
    {
        DB::table('posts')->insert(['title' => 'A']);
        DB::table('posts')->insert(['title' => 'B']);

        $svc = app(SchemaFieldService::class);
        $res = $svc->analyze('posts', [
            'name' => 'code',
            'type' => 'string',
            'nullable' => false,
            'default' => 'X',
            'unique' => true,
        ]);

        $this->assertNotEmpty($res['warnings']);
        $this->assertSame('risky', $res['impact']);
    }

    public function test_foreign_id_triggers_advisory_warning(): void
    {
        $svc = app(SchemaFieldService::class);
        $res = $svc->analyze('posts', [
            'name' => 'user_id',
            'type' => 'foreignId',
        ]);

        $this->assertNotEmpty($res['warnings']);
    }
}
