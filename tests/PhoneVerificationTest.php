<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\UpdateRecoveryChannelsForm;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\PhoneCountry;
use Laravel\Jetstream\Tests\Fixtures\FakePhoneVerificationSender;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

class PhoneVerificationTest extends OrchestraTestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        FakePhoneVerificationSender::reset();
    }

    protected function createUser(): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);
    }

    public function test_phone_numbers_are_normalized_to_e164(): void
    {
        $this->assertSame('+905550000000', PhoneCountry::toE164('TR', '0555 000 00 00'));
        $this->assertSame('+14155552671', PhoneCountry::toE164('US', '(415) 555-2671'));
        $this->assertNull(PhoneCountry::toE164('XX', '555'));
        $this->assertNull(PhoneCountry::toE164('TR', '12'));
    }

    public function test_verification_is_unavailable_without_a_registered_service(): void
    {
        $this->assertFalse(Jetstream::phoneVerificationEnabled());

        $user = $this->createUser();

        $user->forceFill(['phone' => '+905550000000', 'phone_country' => 'TR'])->save();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->call('sendPhoneVerification')
            ->assertStatus(403);
    }

    public function test_a_phone_number_can_still_be_stored_without_a_verification_service(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.phone_country', 'TR')
            ->set('state.phone', '555 000 00 00')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors();

        $this->assertSame('+905550000000', $user->refresh()->phone);
        $this->assertNull($user->phone_verified_at);
    }

    public function test_a_phone_number_can_be_verified_with_the_sent_code(): void
    {
        Jetstream::verifyPhonesUsing(FakePhoneVerificationSender::class);

        $user = $this->createUser();

        $user->forceFill(['phone' => '+905550000000', 'phone_country' => 'TR'])->save();

        $this->actingAs($user);

        $component = Livewire::test(UpdateRecoveryChannelsForm::class)
            ->call('sendPhoneVerification')
            ->assertDispatched('phone-verification-sent');

        $this->assertSame($user->id, FakePhoneVerificationSender::$lastUserId);
        $this->assertNotNull(FakePhoneVerificationSender::$lastCode);

        $component->set('phoneVerificationCode', FakePhoneVerificationSender::$lastCode)
            ->call('confirmPhoneVerification')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertNotNull($user->phone_verified_at);
        $this->assertNull($user->phone_verification_code);
    }

    public function test_an_incorrect_code_does_not_verify_the_phone(): void
    {
        Jetstream::verifyPhonesUsing(FakePhoneVerificationSender::class);

        $user = $this->createUser();

        $user->forceFill(['phone' => '+905550000000', 'phone_country' => 'TR'])->save();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->call('sendPhoneVerification')
            ->set('phoneVerificationCode', '000000')
            ->call('confirmPhoneVerification')
            ->assertHasErrors('phone_verification_code');

        $this->assertNull($user->refresh()->phone_verified_at);
    }

    public function test_an_expired_code_does_not_verify_the_phone(): void
    {
        Jetstream::verifyPhonesUsing(FakePhoneVerificationSender::class);

        $user = $this->createUser();

        $user->forceFill(['phone' => '+905550000000', 'phone_country' => 'TR'])->save();

        $this->actingAs($user);

        $component = Livewire::test(UpdateRecoveryChannelsForm::class)
            ->call('sendPhoneVerification');

        $user->refresh()->forceFill(['phone_verification_expires_at' => now()->subMinute()])->save();

        $component->set('phoneVerificationCode', (string) FakePhoneVerificationSender::$lastCode)
            ->call('confirmPhoneVerification')
            ->assertHasErrors('phone_verification_code');
    }

    public function test_changing_the_phone_number_resets_its_verification(): void
    {
        $user = $this->createUser();

        $user->forceFill([
            'phone' => '+905550000000',
            'phone_country' => 'TR',
            'phone_verified_at' => now(),
        ])->save();

        $this->actingAs($user);

        Livewire::test(UpdateRecoveryChannelsForm::class)
            ->set('state.phone_country', 'US')
            ->set('state.phone', '415 555 2671')
            ->call('updateRecoveryChannels')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('+14155552671', $user->phone);
        $this->assertNull($user->phone_verified_at);
    }
}
