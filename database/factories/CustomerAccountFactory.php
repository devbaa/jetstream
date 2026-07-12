<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerAccount>
 */
class CustomerAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<\App\Models\CustomerAccount>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
        ];
    }
}
