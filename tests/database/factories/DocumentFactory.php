<?php

namespace Radiergummi\Rls\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Radiergummi\Rls\Tests\Models\Document;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return ['title' => $this->faker->sentence()];
    }
}
