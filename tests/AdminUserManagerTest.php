<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Http\Livewire\Admin\UserManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\PasswordSetup;
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

    public function test_admins_can_create_a_user_with_a_password(): void
    {
        Mail::fake();

        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Livewire::test(UserManager::class)
            ->call('createUser')
            ->assertSet('creatingUser', true)
            ->set('createUserForm.name', 'New User')
            ->set('createUserForm.email', 'new@laravel.com')
            ->set('createUserForm.password', 'super-secret-password')
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertSet('creatingUser', false)
            ->assertDispatched('saved');

        $user = User::query()->where('email', 'new@laravel.com')->firstOrFail();

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('super-secret-password', $user->password));
        $this->assertNotNull($user->personalTeam());

        Mail::assertNothingSent();
    }

    public function test_admins_can_create_a_user_without_a_password_and_a_setup_link_is_emailed(): void
    {
        Mail::fake();

        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Livewire::test(UserManager::class)
            ->call('createUser')
            ->set('createUserForm.name', 'New User')
            ->set('createUserForm.email', 'new@laravel.com')
            ->call('saveUser')
            ->assertHasNoErrors();

        Mail::assertSent(PasswordSetup::class);
    }

    public function test_the_setup_link_can_be_skipped_when_creating_a_user(): void
    {
        Mail::fake();

        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Livewire::test(UserManager::class)
            ->call('createUser')
            ->set('createUserForm.name', 'New User')
            ->set('createUserForm.email', 'new@laravel.com')
            ->set('createUserForm.send_reset_mail', false)
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertTrue(User::query()->where('email', 'new@laravel.com')->exists());

        Mail::assertNothingSent();
    }

    public function test_duplicate_emails_are_rejected_when_creating_a_user(): void
    {
        $admin = $this->createAdmin();
        $this->createUser('existing@laravel.com');

        $this->actingAs($admin);

        Livewire::test(UserManager::class)
            ->call('createUser')
            ->set('createUserForm.name', 'New User')
            ->set('createUserForm.email', 'existing@laravel.com')
            ->call('saveUser')
            ->assertHasErrors(['email']);
    }
}
