<?php

namespace Radiergummi\Rls\Tests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\Rls\Tests\Database\Factories\TenantFactory;

class Tenant extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
