<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Soft delete the given user.
     *
     * API tokens are revoked immediately. The user's remaining data — teams,
     * tenants, and customer accounts they own — is permanently erased by the
     * jetstream:purge command once the configured retention period has elapsed.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->tokens->each->delete();
            $user->delete();
        });
    }
}
