<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\LaravelRls\Tests\Fixtures\Database\Factories\DocumentFactory;

#[UseFactory(DocumentFactory::class)]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }
}
