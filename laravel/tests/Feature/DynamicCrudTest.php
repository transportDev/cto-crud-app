<?php

namespace Tests\Feature;

use App\Filament\Pages\DynamicCrud;
use App\Services\TableBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class DynamicCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        foreach (['zz_dynamic_posts', 'zz_audit_demo'] as $t) {
            try { Schema::dropIfExists($t); } catch (\Throwable $e) {}
        }
        parent::tearDown();
    }

    public function test_list_user_tables_includes_user_table_and_excludes_system(): void
    {
        Schema::create('zz_dynamic_posts', function ($table) {
            $table->bigIncrements('id');
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        $svc = app(TableBuilderService::class);
        $tables = $svc->listUserTables();

        $this->assertContains('zz_dynamic_posts', $tables);
        $this->assertNotContains('migrations', $tables);
        $this->assertNotContains('admin_audit_logs', $tables);
    }

    public function test_export_csv_streams_rows(): void
    {
        Schema::create('zz_dynamic_posts', function ($table) {
            $table->bigIncrements('id');
            $table->string('title');
            $table->timestamps();
        });

        DB::table('zz_dynamic_posts')->insert([
            ['title' => 'First', 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'Second', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $page = app(DynamicCrud::class);
        $page->selectedTable = 'zz_dynamic_posts';

        $response = $page->exportCsv();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('text/csv', $response->headers->get('Content-Type'));

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('id,title,created_at,updated_at', $content);
        $this->assertStringContainsString('First', $content);
        $this->assertStringContainsString('Second', $content);
    }

    public function test_service_audits_table_creation(): void
    {
        $svc = app(TableBuilderService::class);

        $svc->create([
            'table' => 'zz_audit_demo',
            'timestamps' => false,
            'soft_deletes' => false,
            'columns' => [
                ['name' => 'id', 'type' => 'bigInteger', 'auto_increment' => true, 'primary' => true, 'unsigned' => true],
                ['name' => 'name', 'type' => 'string', 'length' => 64],
            ],
        ]);

        $this->assertTrue(Schema::hasTable('zz_audit_demo'));
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'table.created',
        ]);
    }
}


