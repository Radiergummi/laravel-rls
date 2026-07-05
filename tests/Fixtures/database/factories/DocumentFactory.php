<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Fixtures\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Radiergummi\LaravelRls\Tests\Fixtures\Models\Document;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return ['title' => $this->faker->sentence()];
    }
}
