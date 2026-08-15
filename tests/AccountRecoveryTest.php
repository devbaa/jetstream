<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Support\Facades\DB;
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

    protected function createUser(string $email = 'taylor@laravel.com'): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    public function test_phone_and_recovery_email_can_be_saved(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.phone_country', 'TR')
            ->set('state.phone', '555 000 00 00')
            ->set('state.recovery_email', 'backup@laravel.com')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        $user->refresh();

        $this->assertSame('+905550000000', $user->phone);
        $this->assertSame('TR', $user->phone_country);
        $this->assertNull($user->phone_verified_at);
        $this->assertSame('backup@laravel.com', $user->recovery_email);
        $this->assertNull($user->recovery_email_verified_at);

        Mail::assertQueued(RecoveryEmailVerification::class, function (RecoveryEmailVerification $mail): bool {
            return $mail->hasTo('backup@laravel.com');
        });
    }

    public function test_invalid_phone_numbers_are_rejected(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.phone_country', 'TR')
            ->set('state.phone', 'not-a-phone')
            ->call('updateRecoveryChannels')
            ->assertHasErrors('phone');
    }

    public function test_a_phone_number_requires_a_country(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.phone', '555 000 00 00')
            ->call('updateRecoveryChannels')
            ->assertHasErrors('phone_country');
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

        Mail::assertQueued(AccountRecovery::class, function (AccountRecovery $mail) use ($user): bool {
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
        Mail::assertNothingQueued();
    }

    public function test_unknown_addresses_receive_the_same_response(): void
    {
        Mail::fake();

        $response = $this->post('/account-recovery', ['email' => 'nobody@laravel.com']);

        $response->assertSessionHas('status');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_recovery_emails_are_stored_in_a_canonical_form(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.recovery_email', '  Recovery.User@Example.COM  ')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors();

        $this->assertSame('recovery.user@example.com', $user->refresh()->recovery_email);
    }

    public function test_a_recovery_email_can_be_cleared(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $user->forceFill(['recovery_email' => 'backup@laravel.com'])->save();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.recovery_email', '')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors();

        $this->assertNull($user->refresh()->recovery_email);
    }

    public function test_a_differently_cased_primary_email_cannot_be_used_as_the_recovery_email(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.recovery_email', 'Taylor@Laravel.COM')
            ->call('updateRecoveryChannels')
            ->assertHasErrors('recovery_email');
    }

    public function test_re_saving_an_equivalent_recovery_email_keeps_its_verification(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $user->forceFill([
            'recovery_email' => 'backup@laravel.com',
            'recovery_email_verified_at' => now(),
        ])->save();

        $this->actingAs($user);

        // Only the casing differs, so this is the same address and must not
        // be treated as a change...
        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.recovery_email', 'Backup@Laravel.com')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->recovery_email_verified_at);

        Mail::assertNothingQueued();
    }

    public function test_a_recovery_request_matches_a_differently_cased_address(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $user->forceFill([
            'recovery_email' => 'recovery.user@example.com',
            'recovery_email_verified_at' => now(),
        ])->save();

        $response = $this->post('/account-recovery', ['email' => '  Recovery.User@EXAMPLE.com  ']);

        $response->assertSessionHas('status');

        Mail::assertQueued(AccountRecovery::class, function (AccountRecovery $mail) use ($user): bool {
            return $mail->user->is($user);
        });
    }

    public function test_a_recovery_request_matches_an_address_stored_before_normalization(): void
    {
        Mail::fake();

        $user = $this->createUser();

        // Simulate a row written before recovery emails were canonicalized.
        // A case-sensitive database (PostgreSQL) could never match this row
        // against a lower-cased submission...
        DB::table('users')->where('id', $user->id)->update([
            'recovery_email' => 'Recovery.User@Example.COM',
            'recovery_email_verified_at' => now(),
        ]);

        $this->normalizeRecoveryEmails();

        $response = $this->post('/account-recovery', ['email' => 'recovery.user@example.com']);

        $response->assertSessionHas('status');

        Mail::assertQueued(AccountRecovery::class, function (AccountRecovery $mail) use ($user): bool {
            return $mail->user->is($user) && $mail->hasTo('recovery.user@example.com');
        });
    }

    public function test_the_normalization_migration_only_canonicalizes_recovery_addresses(): void
    {
        $mixedCase = $this->createUser();
        $alreadyCanonical = $this->createUser('adam@laravel.com');
        $withoutRecovery = $this->createUser('jess@laravel.com');

        $verifiedAt = now()->subDay();

        DB::table('users')->where('id', $mixedCase->id)->update([
            'recovery_email' => '  Recovery.User@Example.COM ',
            'recovery_email_verified_at' => $verifiedAt,
        ]);

        DB::table('users')->where('id', $alreadyCanonical->id)->update([
            'recovery_email' => 'backup@laravel.com',
        ]);

        $this->normalizeRecoveryEmails();

        $this->assertSame('recovery.user@example.com', $mixedCase->refresh()->recovery_email);
        $this->assertSame('backup@laravel.com', $alreadyCanonical->refresh()->recovery_email);
        $this->assertNull($withoutRecovery->refresh()->recovery_email);

        // Canonicalizing an address is not a change of address, so the
        // verification it already earned survives...
        $this->assertNotNull($mixedCase->refresh()->recovery_email_verified_at);
        $this->assertSame('taylor@laravel.com', $mixedCase->refresh()->email);
    }

    public function test_recovery_mail_is_queued_rather_than_delivered_in_the_request(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $user->forceFill([
            'recovery_email' => 'backup@laravel.com',
            'recovery_email_verified_at' => now(),
        ])->save();

        $this->post('/account-recovery', ['email' => 'backup@laravel.com'])
            ->assertSessionHas('status');

        Mail::assertQueued(AccountRecovery::class);
        Mail::assertNotSent(AccountRecovery::class);
    }

    public function test_known_and_unknown_addresses_are_indistinguishable_from_outside(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $user->forceFill([
            'recovery_email' => 'backup@laravel.com',
            'recovery_email_verified_at' => now(),
        ])->save();

        // Each response is inspected before the next request runs, because
        // both share one session in the test harness...
        $known = $this->post('/account-recovery', ['email' => 'backup@laravel.com']);
        $known->assertSessionHasNoErrors();

        $knownOutcome = [
            'status' => $known->getStatusCode(),
            'location' => $known->headers->get('Location'),
            'message' => $known->getSession()->get('status'),
        ];

        $unknown = $this->post('/account-recovery', ['email' => 'nobody@laravel.com']);
        $unknown->assertSessionHasNoErrors();

        $unknownOutcome = [
            'status' => $unknown->getStatusCode(),
            'location' => $unknown->headers->get('Location'),
            'message' => $unknown->getSession()->get('status'),
        ];

        $this->assertSame($knownOutcome, $unknownOutcome);
        $this->assertNotNull($knownOutcome['message']);
    }

    public function test_a_queued_verification_link_is_signed_for_the_address_it_is_sent_to(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.recovery_email', 'first@laravel.com')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors();

        // Changing the address again must not retarget the message already
        // queued for the previous one: its link is signed at creation...
        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.recovery_email', 'second@laravel.com')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors();

        Mail::assertQueued(RecoveryEmailVerification::class, function (RecoveryEmailVerification $mail): bool {
            return $mail->hasTo('first@laravel.com')
                && str_contains($mail->verifyUrl, sha1('first@laravel.com'));
        });

        Mail::assertQueued(RecoveryEmailVerification::class, function (RecoveryEmailVerification $mail): bool {
            return $mail->hasTo('second@laravel.com')
                && str_contains($mail->verifyUrl, sha1('second@laravel.com'));
        });
    }

    /**
     * Run the recovery email normalization migration against the test database.
     */
    protected function normalizeRecoveryEmails(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_07_08_100000_normalize_recovery_emails.php';

        $migration->up();
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
