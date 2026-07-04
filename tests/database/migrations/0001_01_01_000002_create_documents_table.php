<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('documents', static function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('title')->nullable();
            $table->timestamps();

            // @phpstan-ignore method.notFound (scopedBy is a runtime Blueprint macro from RlsSchemaMacros)
            $table->scopedBy('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
