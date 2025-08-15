<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ForeignKeyConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_delete_is_blocked_when_on_delete_restrict(): void
    {
        Schema::create('zz_parents', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
        });

        Schema::create('zz_children', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_id');
            $table->foreign('parent_id')->references('id')->on('zz_parents')->onDelete('restrict');
        });

        $parentId = DB::table('zz_parents')->insertGetId(['name' => 'p1']);
        DB::table('zz_children')->insert(['parent_id' => $parentId]);

        $thrown = false;
        try {
            DB::table('zz_parents')->where('id', $parentId)->delete();
        } catch (QueryException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Expected FK restrict to throw on delete');
        $this->assertDatabaseHas('zz_parents', ['id' => $parentId]);
    }

    public function test_delete_cascades_when_on_delete_cascade(): void
    {
        Schema::create('zz_parents_c', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
        });

        Schema::create('zz_children_c', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_id');
            $table->foreign('parent_id')->references('id')->on('zz_parents_c')->onDelete('cascade');
        });

        $parentId = DB::table('zz_parents_c')->insertGetId(['name' => 'p1']);
        DB::table('zz_children_c')->insert(['parent_id' => $parentId]);

        DB::table('zz_parents_c')->where('id', $parentId)->delete();

        $this->assertDatabaseMissing('zz_parents_c', ['id' => $parentId]);
        $this->assertDatabaseMissing('zz_children_c', ['parent_id' => $parentId]);
    }
}


