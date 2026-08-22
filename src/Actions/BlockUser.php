<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Events\UserBlocked;
use Laravel\Sanctum\Sanctum;

class BlockUser
{
    /**
     * Block a user out of the whole application and take away what they hold.
     *
     * Writing blocked_at is only half of a block. The `account.active`
     * middleware turns a blocked user away from the routes it is on, but a
     * credential issued before the block is not a request: a Sanctum personal
     * access token authenticates an application's own `auth:sanctum` routes
     * without ever passing through this package's middleware, and a database
     * session row outlives the block until its owner happens to hit a guarded
     * route. Both are revoked here, so the block does not depend on where the
     * blocked user goes next.
     *
     * Revocation is deletion rather than a flag, which is what makes unblocking
     * safe: there is nothing left to become valid again.
     *
     * @param  \App\Models\User  $user
     * @param  string|null  $reason
     * @return void
     */
    public function block($user, $reason = null)
    {
        DB::transaction(function () use ($user, $reason) {
            $user->forceFill([
                'blocked_at' => now(),
                'blocked_reason' => $reason !== null && $reason !== '' ? $reason : null,
            ])->save();

            $this->revokeApiTokens($user);
            $this->revokeSessions($user);
        });

        UserBlocked::dispatch($user);
    }

    /**
     * Delete every personal access token the user holds.
     *
     * Queried through Sanctum's own model rather than the user's relation:
     * the API feature is optional, so an application installed without it has
     * no personal_access_tokens table at all, and blocking a user must not
     * depend on whether they ever could have held a token.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    protected function revokeApiTokens($user)
    {
        $model = Sanctum::personalAccessTokenModel();

        $token = new $model;

        if (! Schema::connection($token->getConnectionName())->hasTable($token->getTable())) {
            return;
        }

        DB::connection($token->getConnectionName())->table($token->getTable())
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getAuthIdentifier())
            ->delete();
    }

    /**
     * Delete the user's session records.
     *
     * Only the database driver keeps sessions somewhere they can be revoked
     * from. On any other driver the session survives until the blocked user
     * next hits a route carrying the `account.active` middleware, which is
     * what that middleware is for.
     *
     * @param  \Illuminate\Foundation\Auth\User  $user
     * @return void
     */
    protected function revokeSessions($user)
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $connection = config('session.connection');
        $table = config('session.table', 'sessions');

        if (! is_string($table) || ! Schema::connection(is_string($connection) ? $connection : null)->hasTable($table)) {
            return;
        }

        DB::connection(is_string($connection) ? $connection : null)->table($table)
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }
}
