<?php

namespace Radiergummi\LaravelRls\Tests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Radiergummi\LaravelRls\Tests\Database\Factories\DocumentFactory;

class Document extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }
}
