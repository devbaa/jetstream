<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Tenant;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

class HoneypotTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTenantEnvironment($app);

        $app->singleton(CreatesNewUsers::class, CreateNewUser::class);

        Jetstream::useUserModel(User::class);
    }

    public function test_registration_succeeds_when_the_honeypot_is_empty(): void
    {
        $response = $this->post('/register', [
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'website' => '',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertNotNull(User::query()->where('email', 'taylor@laravel.com')->first());
    }

    public function test_registration_is_rejected_when_the_honeypot_is_filled(): void
    {
        $response = $this->post('/register', [
            'name' => 'Bot McBotface',
            'email' => 'bot@spam.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'website' => 'https://spam.example',
        ]);

        $response->assertSessionHasErrors('website');

        $this->assertNull(User::query()->where('email', 'bot@spam.com')->first());
    }

    public function test_customer_self_registration_is_rejected_when_the_honeypot_is_filled(): void
    {
        $owner = User::forceCreate([
            'name' => 'Owner',
            'email' => 'owner@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = Tenant::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Acme',
            'slug' => 'acme',
            'allow_customer_registration' => true,
        ]);

        $customer = User::forceCreate([
            'name' => 'Customer',
            'email' => 'customer@laravel.com',
            'password' => 'secret',
        ]);

        $response = $this->actingAs($customer)
            ->post('/portal/register/acme', ['website' => 'https://spam.example']);

        $response->assertSessionHasErrors('website');

        $this->assertFalse($customer->fresh()->isCustomerOf($tenant));
    }
}
