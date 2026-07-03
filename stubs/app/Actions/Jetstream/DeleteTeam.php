<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use Laravel\Jetstream\Contracts\DeletesTeams;

class DeleteTeam implements DeletesTeams
{
    /**
     * Soft delete the given team.
     *
     * The team is permanently purged by the jetstream:purge command once
     * the configured retention period has elapsed.
     */
    public function delete(Team $team): void
    {
        $team->resetCurrentSelections();

        $team->delete();
    }
}
