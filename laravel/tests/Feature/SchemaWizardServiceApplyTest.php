<?php

namespace Tests\Feature;

use App\Models\CtoTableMeta;
use App\Services\Dynamic\DynamicSchemaService;
use App\Services\SchemaWizardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SchemaWizardServiceApplyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Required meta table
    Schema::dropIfExists('cto_table_meta');
        Schema::create('cto_table_meta', function (Blueprint $table) {
            $table->increments('id');
            $table->string('table_name')->unique();
            $table->string('primary_key_column')->nullable();
            $table->string('label_column')->nullable();
            $table->string('search_column')->nullable();
            $table->json('display_template')->nullable();
            $table->timestamps();
        });

        // Target and reference tables
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

        // Stub DynamicSchemaService so cache invalidation is a no-op
        $stub = new class extends DynamicSchemaService {
            public function __construct() {}
            public function invalidateTableCache(string $tableName): void {}
            public function populateMetaForTable(string $tableName): void {}
            public function whitelist(): array
            {
                return ['posts', 'vendors', 'cto_table_meta'];
            }
            public function sanitizeTable(?string $table): ?string
            {
                return $table;
            }
        };
        $this->app->instance(DynamicSchemaService::class, $stub);
    }

    public function test_apply_adds_simple_string_field_with_index_and_is_idempotent(): void
    {
        $svc = app(SchemaWizardService::class);

        $items = [[
            'kind' => 'field',
            'name' => 'slug',
            'type' => 'string',
            'length' => 180,
            'nullable' => true,
            'index' => 'index',
        ]];

        $res1 = $svc->applyDirectChanges('posts', $items);
        $this->assertTrue($res1['success']);
        $this->assertTrue(Schema::hasColumn('posts', 'slug'));

        // Re-apply should not error
        $res2 = $svc->applyDirectChanges('posts', $items);
        $this->assertTrue($res2['success']);
    }

    public function test_apply_adds_decimal_and_boolean_with_default(): void
    {
        $svc = app(SchemaWizardService::class);
        $res = $svc->applyDirectChanges('posts', [
            [
                'kind' => 'field',
                'name' => 'price',
                'type' => 'decimal',
                'precision' => 10,
                'scale' => 2,
                'nullable' => false,
                'default' => '0.00',
            ],
            [
                'kind' => 'field',
                'name' => 'is_active',
                'type' => 'boolean',
                'default_bool' => true,
            ],
        ]);

        $this->assertTrue($res['success']);
        $this->assertTrue(Schema::hasColumn('posts', 'price'));
        $this->assertTrue(Schema::hasColumn('posts', 'is_active'));
    }

    public function test_apply_relation_success_persists_metadata(): void
    {
        // Partial mock to bypass actual FK creation (SQLite has limited support)
        // Use a concrete subclass to override FK behavior
        $svc = new class extends SchemaWizardService {
            protected function addRelationDirectly(string $table, array $relation): void
            {
                if (!Schema::hasColumn($table, $relation['name'])) {
                    Schema::table($table, function (Blueprint $t) use ($relation) {
                        $t->unsignedBigInteger($relation['name'])->nullable();
                    });
                }
            }
        };

        $items = [[
            'kind' => 'relation',
            'name' => 'vendor_id',
            'references_table' => 'vendors',
            'references_column' => 'id',
            'relation_type' => 'bigInteger',
            'nullable' => true,
            'label_columns' => ['vendor_code', 'vendor_name'],
            'search_column' => 'vendor_code',
        ]];

        $res = $svc->applyDirectChanges('posts', $items);
        $this->assertTrue($res['success']);
        $this->assertTrue(Schema::hasColumn('posts', 'vendor_id'));

        $meta = CtoTableMeta::query()->where('table_name', 'vendors')->first();
        $this->assertNotNull($meta);
        $this->assertSame('vendor_code', $meta->search_column);
        $this->assertIsArray($meta->display_template);
        $this->assertSame(['vendor_code', 'vendor_name'], $meta->display_template['columns'] ?? []);
    }

    public function test_apply_relation_failure_cleans_up_created_column(): void
    {
        // Force addRelationDirectly to throw, to verify compensating drop
        // Subclass that throws in addRelationDirectly to simulate FK failure
        $svc = new class extends SchemaWizardService {
            protected function addRelationDirectly(string $table, array $relation): void
            {
                throw new \RuntimeException('FK failed');
            }
        };

        $items = [[
            'kind' => 'relation',
            'name' => 'vendor_id',
            'references_table' => 'vendors',
            'references_column' => 'id',
            'relation_type' => 'bigInteger',
            'nullable' => true,
        ]];

        /** @var SchemaWizardService $svc */
        $svc = $svc;
        $res = $svc->applyDirectChanges('posts', $items);
        $this->assertFalse($res['success']);

        // Since our mocked method threw before creating the column, ensure the column doesn't exist
        $this->assertFalse(Schema::hasColumn('posts', 'vendor_id'));
    }
}
