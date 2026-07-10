<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Adds per-user (or per-IP for guests) rate limiting to Livewire actions.
 *
 * Livewire actions are invoked over the "/livewire/update" endpoint, which
 * is not covered by the route-level "throttle" middleware, so sensitive
 * operations (sending verification codes, invitations, brute-forceable
 * confirmations) must guard themselves.
 */
trait WithRateLimiting
{
    /**
     * Record a hit against the given rate limiter, aborting when exhausted.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function rateLimit(string $key, int $maxAttempts, int $decaySeconds = 60, string $errorBag = 'default'): void
    {
        $key = $this->rateLimiterKey($key);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                $errorBag => __('Too many attempts. Please try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ])->errorBag($errorBag);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    /**
     * Clear the given rate limiter, e.g. after a successful confirmation.
     */
    protected function clearRateLimit(string $key): void
    {
        RateLimiter::clear($this->rateLimiterKey($key));
    }

    /**
     * Build the fully-qualified rate limiter key for the current actor.
     */
    protected function rateLimiterKey(string $key): string
    {
        $actor = Auth::id() ?? request()->ip();

        return 'jetstream:'.$key.'|'.$actor;
    }
}
