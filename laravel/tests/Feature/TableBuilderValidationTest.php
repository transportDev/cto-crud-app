<?php

namespace Tests\Feature;

use App\Services\TableBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TableBuilderValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_enum_requires_options(): void
    {
        $svc = app(TableBuilderService::class);

        $this->expectException(ValidationException::class);

        $svc->create([
            'table' => 'zz_enum_demo',
            'columns' => [
                ['name' => 'status', 'type' => 'enum'],
            ],
        ]);
    }

    public function test_foreign_id_requires_references_table(): void
    {
        $svc = app(TableBuilderService::class);

        $this->expectException(ValidationException::class);

        $svc->create([
            'table' => 'zz_fk_demo',
            'columns' => [
                ['name' => 'user_id', 'type' => 'foreignId'],
            ],
        ]);
    }
}


