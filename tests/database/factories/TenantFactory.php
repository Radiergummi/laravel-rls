<?php

namespace Radiergummi\LaravelRls\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Radiergummi\LaravelRls\Tests\Models\Tenant;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return ['name' => $this->faker->company()];
    }
}
