<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_tables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('table', 191)->unique();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['table', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_tables');
    }
};