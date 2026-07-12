<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Listeners;

use Illuminate\Auth\Events\Verified;
use Laravel\Jetstream\Actions\AddUserToDomainTeams;

class AddVerifiedUserToDomainTeams
{
    /**
     * Enroll a freshly verified user into their domain master's team.
     *
     * Only verified accounts take part in domain administration, so email
     * verification is the moment a user becomes part of their domain.
     */
    public function handle(Verified $event): void
    {
        if ($event->user instanceof \App\Models\User) {
            app(AddUserToDomainTeams::class)->add($event->user);
        }
    }
}
