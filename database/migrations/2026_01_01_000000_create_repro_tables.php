<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('widgets');
        Schema::create('widgets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamp('stale_since')->nullable();
            $table->timestamps();
        });
    }
};
