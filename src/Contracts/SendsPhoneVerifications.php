<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Contracts;

/**
 * Delivers phone verification codes, typically over SMS.
 *
 * Register an implementation with Jetstream::verifyPhonesUsing(). When no
 * implementation is registered, users may still enter a phone number but
 * it cannot be verified.
 *
 * @method void send(\App\Models\User $user, string $code)
 */
interface SendsPhoneVerifications
{
    //
}
