<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\LaravelRls\Tests\Fixtures\Database\Factories\TenantFactory;

/**
 * Tenant
 *
 * @property-read int $id
 */
#[UseFactory(TenantFactory::class)]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
