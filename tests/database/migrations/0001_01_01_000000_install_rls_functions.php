<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Radiergummi\LaravelRls\Support\RlsFunctions;

return new class extends Migration
{
    public function up(): void
    {
        RlsFunctions::install();
    }

    public function down(): void
    {
        DB::statement('drop schema if exists rls cascade');
    }
};
