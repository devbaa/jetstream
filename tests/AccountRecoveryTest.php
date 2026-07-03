<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\UpdateRecoveryChannelsForm;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\AccountRecovery;
use Laravel\Jetstream\Mail\RecoveryEmailVerification;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

class AccountRecoveryTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $features = $app->config->get('jetstream.features', []);

        $features[] = Features::accountRecovery();

        $app->config->set('jetstream.features', $features);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Jetstream::useUserModel(User::class);
    }

    protected function createUser(): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);
    }

    public function test_phone_and_recovery_email_can_be_saved(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.phone', '+90 555 000 00 00')
            ->set('state.recovery_email', 'backup@laravel.com')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        $user->refresh();

        $this->assertSame('+90 555 000 00 00', $user->phone);
        $this->assertSame('backup@laravel.com', $user->recovery_email);
        $this->assertNull($user->recovery_email_verified_at);

        Mail::assertSent(RecoveryEmailVerification::class, function (RecoveryEmailVerification $mail): bool {
            return $mail->hasTo('backup@laravel.com');
        });
    }

    public function test_invalid_phone_numbers_are_rejected(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.phone', 'not-a-phone')
            ->call('updateRecoveryChannels')
            ->assertHasErrors('phone');
    }

    public function test_the_recovery_email_must_differ_from_the_primary_email(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.recovery_email', 'taylor@laravel.com')
            ->call('updateRecoveryChannels')
            ->assertHasErrors('recovery_email');
    }

    public function test_changing_the_recovery_email_resets_its_verification(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $user->forceFill([
            'recovery_email' => 'backup@laravel.com',
            'recovery_email_verified_at' => now(),
        ])->save();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.recovery_email', 'other@laravel.com')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors();

        $this->assertNull($user->refresh()->recovery_email_verified_at);
    }

    public function test_the_recovery_email_can_be_verified_via_a_signed_link(): void
    {
        $user = $this->createUser();

        $user->forceFill(['recovery_email' => 'backup@laravel.com'])->save();

        $url = URL::temporarySignedRoute('recovery-email.verify', now()->addMinutes(60), [
            'user' => $user->id,
            'hash' => sha1('backup@laravel.com'),
        ]);

        $this->get($url)->assertRedirect(Jetstream::homePath());

        $this->assertNotNull($user->refresh()->recovery_email_verified_at);
    }

    public function test_a_tampered_hash_cannot_verify_the_recovery_email(): void
    {
        $user = $this->createUser();

        $user->forceFill(['recovery_email' => 'backup@laravel.com'])->save();

        $url = URL::temporarySignedRoute('recovery-email.verify', now()->addMinutes(60), [
            'user' => $user->id,
            'hash' => sha1('attacker@evil.com'),
        ]);

        $this->get($url)->assertForbidden();

        $this->assertNull($user->refresh()->recovery_email_verified_at);
    }

    public function test_an_unsigned_verification_link_is_rejected(): void
    {
        $user = $this->createUser();

        $user->forceFill(['recovery_email' => 'backup@laravel.com'])->save();

        $this->get('/user/recovery-email/verify/'.$user->id.'?hash='.sha1('backup@laravel.com'))
            ->assertForbidden();
    }

    public function test_a_verified_recovery_email_receives_a_password_reset_link(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $user->forceFill([
            'recovery_email' => 'backup@laravel.com',
            'recovery_email_verified_at' => now(),
        ])->save();

        $response = $this->post('/account-recovery', ['email' => 'backup@laravel.com']);

        $response->assertSessionHas('status');

        Mail::assertSent(AccountRecovery::class, function (AccountRecovery $mail) use ($user): bool {
            return $mail->hasTo('backup@laravel.com') && $mail->user->is($user);
        });
    }

    public function test_an_unverified_recovery_email_never_receives_a_reset_link(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $user->forceFill(['recovery_email' => 'backup@laravel.com'])->save();

        $response = $this->post('/account-recovery', ['email' => 'backup@laravel.com']);

        $response->assertSessionHas('status');

        Mail::assertNothingSent();
    }

    public function test_unknown_addresses_receive_the_same_response(): void
    {
        Mail::fake();

        $response = $this->post('/account-recovery', ['email' => 'nobody@laravel.com']);

        $response->assertSessionHas('status');

        Mail::assertNothingSent();
    }

    public function test_the_recovery_routes_are_registered_for_guests(): void
    {
        // The form view itself renders through the application's layout
        // components, which only exist in a scaffolded application.
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('account-recovery.show'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('account-recovery.store'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('recovery-email.verify'));
    }
}
