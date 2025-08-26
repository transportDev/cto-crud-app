<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cto_table_meta', function (Blueprint $table) {
            $table->id();
            $table->string('table_name')->unique();
            $table->string('primary_key_column')->nullable();
            $table->string('label_column')->nullable();
            $table->string('search_column')->nullable();
            $table->json('display_template')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cto_table_meta');
    }
};
