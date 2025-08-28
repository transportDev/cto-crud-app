<?php

namespace Tests\Feature;

use App\Services\SchemaWizardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaWizardServiceAnalyzeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    Schema::dropIfExists('posts');
    Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
        });
    Schema::dropIfExists('vendors');
    Schema::create('vendors', function (Blueprint $table) {
            $table->increments('id');
            $table->string('vendor_code');
            $table->string('vendor_name');
        });
    }

    public function test_analyze_combines_field_and_relation_with_php_and_sql(): void
    {
        $svc = app(SchemaWizardService::class);
        $items = [
            [
                'kind' => 'field',
                'name' => 'slug',
                'type' => 'string',
                'length' => 150,
                'nullable' => false,
                'unique' => true,
            ],
            [
                'kind' => 'relation',
                'name' => 'vendor_id',
                'references_table' => 'vendors',
                'references_column' => 'id',
                'relation_type' => 'bigInteger',
                'nullable' => true,
                'on_update' => 'cascade',
                'on_delete' => 'restrict',
            ],
        ];

        $res = $svc->analyze('posts', $items);

        // Field PHP + index
        $this->assertStringContainsString("\$table->string('slug', 150)", $res['migration_php']);
        $this->assertStringContainsString("\$table->unique('slug')", $res['migration_php']);

        // Relation PHP
        $this->assertStringContainsString("unsignedBigInteger('vendor_id')", $res['migration_php']);
        $this->assertStringContainsString("foreign('vendor_id')->references('id')->on('vendors')->onUpdate('cascade')->onDelete('restrict')", $res['migration_php']);

        // SQL approximation
        $this->assertStringContainsString('ADD COLUMN `slug` VARCHAR(150) NOT NULL', $res['estimated_sql']);
        $this->assertStringContainsString('CREATE UNIQUE INDEX `posts_slug_unique`', $res['estimated_sql']);
        $this->assertStringContainsString('ADD COLUMN `vendor_id` BIGINT UNSIGNED NULL', $res['estimated_sql']);
        $this->assertStringContainsString('FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON UPDATE cascade ON DELETE restrict', $res['estimated_sql']);

        $this->assertIsArray($res['warnings']);
        $this->assertNotEmpty($res['migration_php']);
    }
}
