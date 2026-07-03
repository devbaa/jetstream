<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Laravel\Jetstream\Http\Livewire\Admin\UserManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

class AdminUserManagerTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTenantEnvironment($app);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Jetstream::useUserModel(User::class);
    }

    protected function createAdmin(): User
    {
        $admin = User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@laravel.com',
            'password' => 'secret',
        ]);

        $admin->forceFill(['is_system_admin' => true])->save();

        return $admin;
    }

    protected function createUser(string $email = 'taylor@laravel.com'): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    public function test_a_user_can_be_blocked_with_a_reason(): void
    {
        $admin = $this->createAdmin();
        $subject = $this->createUser();

        $this->actingAs($admin);

        Livewire::test(UserManager::class)
            ->call('confirmUserBlock', $subject->id)
            ->set('blockReason', 'Fraudulent activity')
            ->call('blockUser')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        $subject->refresh();

        $this->assertTrue($subject->isBlocked());
        $this->assertSame('Fraudulent activity', $subject->blocked_reason);
    }

    public function test_a_user_can_be_unblocked(): void
    {
        $admin = $this->createAdmin();
        $subject = $this->createUser();

        $subject->forceFill(['blocked_at' => now(), 'blocked_reason' => 'Oops'])->save();

        $this->actingAs($admin);

        Livewire::test(UserManager::class)->call('unblockUser', $subject->id);

        $subject->refresh();

        $this->assertFalse($subject->isBlocked());
        $this->assertNull($subject->blocked_reason);
    }

    public function test_admins_cannot_block_themselves(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Livewire::test(UserManager::class)
            ->call('confirmUserBlock', $admin->id)
            ->call('blockUser')
            ->assertHasErrors('reason');

        $this->assertFalse($admin->refresh()->isBlocked());
    }

    public function test_two_factor_authentication_can_be_reset(): void
    {
        $admin = $this->createAdmin();
        $subject = $this->createUser();

        $subject->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin);

        Livewire::test(UserManager::class)->call('resetTwoFactorAuthentication', $subject->id);

        $subject->refresh();

        $this->assertNull($subject->two_factor_secret);
        $this->assertNull($subject->two_factor_recovery_codes);
        $this->assertNull($subject->two_factor_confirmed_at);
    }

    public function test_passkeys_can_be_reset(): void
    {
        $admin = $this->createAdmin();
        $subject = $this->createUser();

        $subject->passkeys()->create([
            'name' => 'Lost Laptop',
            'credential_id' => 'credential-1',
            'credential' => ['publicKey' => 'stub'],
        ]);

        $this->actingAs($admin);

        Livewire::test(UserManager::class)->call('resetPasskeys', $subject->id);

        $this->assertSame(0, $subject->passkeys()->count());
    }

    public function test_the_admin_users_route_requires_a_system_admin(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        $this->get('/admin/users')->assertForbidden();
    }
}
