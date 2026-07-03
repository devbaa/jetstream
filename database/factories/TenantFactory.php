<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'user_id' => User::factory(),
            'allow_customer_registration' => false,
        ];
    }

    /**
     * Indicate that the tenant allows customer self-registration.
     */
    public function allowsCustomerRegistration(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_customer_registration' => true,
        ]);
    }
}
