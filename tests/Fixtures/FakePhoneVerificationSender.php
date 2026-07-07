<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use Laravel\Jetstream\Contracts\SendsPhoneVerifications;

class FakePhoneVerificationSender implements SendsPhoneVerifications
{
    /**
     * The last verification code that was "sent".
     */
    public static ?string $lastCode = null;

    /**
     * The ID of the user the last code was sent to.
     */
    public static ?string $lastUserId = null;

    /**
     * Capture the verification code instead of sending it.
     */
    public function send(User $user, string $code): void
    {
        static::$lastCode = $code;
        static::$lastUserId = $user->id;
    }

    /**
     * Reset the captured state.
     */
    public static function reset(): void
    {
        static::$lastCode = null;
        static::$lastUserId = null;
    }
}
