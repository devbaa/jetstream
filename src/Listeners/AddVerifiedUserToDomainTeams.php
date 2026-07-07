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
        if (method_exists($event->user, 'emailDomain')) {
            app(AddUserToDomainTeams::class)->add($event->user);
        }
    }
}
