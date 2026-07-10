<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire\Concerns;

use Laravel\Jetstream\Jetstream;

/**
 * Restricts a Livewire component to system administrators.
 *
 * The check runs in Livewire's "boot" lifecycle hook, which fires on every
 * request — the initial page load and every subsequent action call over the
 * "/livewire/update" endpoint. Guarding only the route (or only mount) would
 * let a component whose privileges were revoked mid-session keep acting for
 * the life of its snapshot, so the authorization is re-evaluated each time.
 */
trait AuthorizesSystemAdmin
{
    /**
     * Livewire trait boot hook: authorize the current user on every request.
     *
     * @return void
     */
    public function bootAuthorizesSystemAdmin()
    {
        $user = Jetstream::currentUser();

        abort_unless(
            method_exists($user, 'isSystemAdmin') && $user->isSystemAdmin(),
            403
        );
    }
}
