<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('title')->nullable();
            $table->timestamps();

            $table->scopedBy('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
