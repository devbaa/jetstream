<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Laravel\Fortify\Features as FortifyFeatures;
use Laravel\Jetstream\Http\Livewire\PasskeyManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

class PasskeyManagerTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $features = $app->config->get('fortify.features', []);

        $features[] = FortifyFeatures::passkeys(['confirmPassword' => true]);

        $app->config->set('fortify.features', $features);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Jetstream::useUserModel(User::class);
    }

    protected function createUser(string $email = 'taylor@laravel.com'): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    protected function createPasskeyFor(User $user, string $name): mixed
    {
        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => 'credential-'.$name.'-'.$user->id,
            'credential' => ['publicKey' => 'stub'],
        ]);
    }

    public function test_registered_passkeys_are_listed(): void
    {
        $user = $this->createUser();

        $this->createPasskeyFor($user, 'MacBook Pro');

        $this->actingAs($user);

        Livewire::withoutLazyLoading()
            ->test(PasskeyManager::class)
            ->assertSee('MacBook Pro');
    }

    public function test_registration_hands_off_to_the_browser_with_the_given_name(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->session(['auth.password_confirmed_at' => time()]);

        Livewire::test(PasskeyManager::class)
            ->set('passkeyName', 'Work Laptop')
            ->call('registerPasskey')
            ->assertHasNoErrors()
            ->assertDispatched('passkey-registration-requested', name: 'Work Laptop');
    }

    public function test_registration_requires_a_name(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->session(['auth.password_confirmed_at' => time()]);

        Livewire::test(PasskeyManager::class)
            ->set('passkeyName', '  ')
            ->call('registerPasskey')
            ->assertHasErrors('passkey_name')
            ->assertNotDispatched('passkey-registration-requested');
    }

    public function test_registration_requires_a_recent_password_confirmation(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(PasskeyManager::class)
            ->set('passkeyName', 'Work Laptop')
            ->call('registerPasskey')
            ->assertStatus(403);
    }

    public function test_passkeys_can_be_deleted(): void
    {
        $user = $this->createUser();

        $passkey = $this->createPasskeyFor($user, 'MacBook Pro');

        $this->actingAs($user)
            ->session(['auth.password_confirmed_at' => time()]);

        Livewire::test(PasskeyManager::class)
            ->call('deletePasskey', $passkey->id);

        $this->assertNull($passkey->fresh());
    }

    public function test_users_cannot_delete_passkeys_of_other_users(): void
    {
        $user = $this->createUser();

        $otherUser = $this->createUser('adam@laravel.com');

        $passkey = $this->createPasskeyFor($otherUser, 'Not Yours');

        $this->actingAs($user)
            ->session(['auth.password_confirmed_at' => time()]);

        Livewire::test(PasskeyManager::class)
            ->call('deletePasskey', $passkey->id);

        $this->assertNotNull($passkey->fresh());
    }
}
