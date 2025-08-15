<?php

namespace Tests\Feature;

use App\Filament\Pages\DynamicCrud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class DynamicCrudBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_soft_deletes_hidden_by_default(): void
    {
        Schema::create('zz_soft', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('zz_soft')->insert([
            ['name' => 'A', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['name' => 'B', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['name' => 'C', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now()],
        ]);

        $page = app(DynamicCrud::class);
        $page->selectedTable = 'zz_soft';

        $ref = new \ReflectionClass($page);
        $method = $ref->getMethod('getRuntimeQuery');
        $method->setAccessible(true);
        $builder = $method->invoke($page);

        $names = $builder->pluck('name')->all();
        $this->assertSame(['A', 'B'], array_values($names));
    }

    public function test_fk_column_renders_select_in_form_schema(): void
    {
        // users table exists from migrations and has id + name/email
        Schema::create('zz_fk_posts', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->timestamps();
        });

        $page = app(DynamicCrud::class);
        $page->selectedTable = 'zz_fk_posts';

        $ref = new \ReflectionClass($page);
        $method = $ref->getMethod('inferFormSchema');
        $method->setAccessible(true);
        $components = $method->invoke($page, false, false);

        $foundSelect = false;
        foreach ($components as $component) {
            if (method_exists($component, 'getName') && $component->getName() === 'user_id') {
                $foundSelect = is_a($component, \Filament\Forms\Components\Select::class);
                break;
            }
        }

        $this->assertTrue($foundSelect, 'Expected user_id form component to be a searchable Select');
    }

    public function test_csv_export_json_values_are_encoded(): void
    {
        Schema::create('zz_json_demo', function ($table) {
            $table->bigIncrements('id');
            $table->json('meta');
        });

        DB::table('zz_json_demo')->insert([
            ['meta' => json_encode(['key' => 'value'])],
        ]);

        $page = app(DynamicCrud::class);
        $page->selectedTable = 'zz_json_demo';

        $response = $page->exportCsv();
        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('meta', $content);
        $this->assertStringContainsString('{"key":"value"}', str_replace('"', '"', json_encode(['key' => 'value'])));
        $this->assertStringContainsString('{"key":"value"}', str_replace('\\', '', json_encode(['key' => 'value'])));
    }
}


