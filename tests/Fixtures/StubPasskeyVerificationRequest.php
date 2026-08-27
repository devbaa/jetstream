<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use ReflectionClass;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * A passkey verification request with the WebAuthn parsing already done.
 *
 * Proving a signature is Fortify's business. What this package needs to
 * exercise is what the passkey login controller does once verification has
 * succeeded — which is where a blocking boundary can act — so the credential
 * and the options it hands to the verifier are stood in for rather than built.
 */
class StubPasskeyVerificationRequest extends PasskeyVerificationRequest
{
    /** {@inheritdoc} */
    #[\Override]
    public function credential(): PublicKeyCredential
    {
        return (new ReflectionClass(PublicKeyCredential::class))->newInstanceWithoutConstructor();
    }

    /** {@inheritdoc} */
    #[\Override]
    public function verificationOptions(): PublicKeyCredentialRequestOptions
    {
        return (new ReflectionClass(PublicKeyCredentialRequestOptions::class))->newInstanceWithoutConstructor();
    }

    /** {@inheritdoc} */
    #[\Override]
    public function remember(): bool
    {
        return false;
    }
}
