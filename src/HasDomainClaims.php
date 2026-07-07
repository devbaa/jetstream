<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

trait HasDomainClaims
{
    /**
     * Get all of the domain claims started by the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\DomainClaim, $this>
     */
    public function domainClaims()
    {
        return $this->hasMany(Jetstream::domainClaimModel(), 'user_id');
    }

    /**
     * Get the domain part of the user's email address.
     */
    public function emailDomain(): ?string
    {
        $email = $this->email;

        if (! is_string($email)) {
            return null;
        }

        $position = strrpos($email, '@');

        if ($position === false || $position === strlen($email) - 1) {
            return null;
        }

        return strtolower(substr($email, $position + 1));
    }

    /**
     * Get the user's claims that currently hold the domain admin flag.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Laravel\Jetstream\DomainClaim, $this>
     */
    public function activeDomainClaims()
    {
        return $this->domainClaims()->whereNotNull('verified_at')->whereNull('superseded_at');
    }

    /**
     * Determine if the user is the current domain admin of the given domain.
     */
    public function isDomainAdminOf(string $domain): bool
    {
        if (! Features::hasDomainAdminFeatures() || ! $this->hasVerifiedEmail()) {
            return false;
        }

        return $this->activeDomainClaims()->where('domain', strtolower($domain))->exists();
    }

    /**
     * Determine if the user, as a domain admin, may manage the given user.
     *
     * Only verified accounts participate in domain administration — on both
     * sides — and system administrators are never subject to it.
     *
     * @param  \App\Models\User  $subject
     */
    public function managesDomainUser($subject): bool
    {
        $domain = $subject->emailDomain();

        if ($domain === null ||
            ! $subject->hasVerifiedEmail() ||
            $subject->isSystemAdmin() ||
            $subject->getKey() === $this->getKey()) {
            return false;
        }

        return $this->isDomainAdminOf($domain);
    }
}
