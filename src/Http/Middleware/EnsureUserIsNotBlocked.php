<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Auth\BlockedUsers;

class EnsureUserIsNotBlocked
{
    /**
     * Turn away users that have been blocked system-wide.
     *
     * This is no longer where blocking is decided. A blocked user is refused
     * at the authentication boundary — establishing a session, resuming one,
     * or entering a second factor challenge — so by the time a request reaches
     * any route, blocked or not has already been settled.
     *
     * The middleware stays as a backstop, and answers to the same component so
     * that there is one definition of blocked rather than two. It used to keep
     * its own: an instanceof check against the published App\Models\User,
     * which quietly did nothing for an application that had configured a user
     * model of its own — which Jetstream::useUserModel() exists to let it do.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $blocked = app(BlockedUsers::class);

        $user = $request->user();

        if ($blocked->isBlocked($user)) {
            $blocked->discard(Auth::guard());

            if ($request->expectsJson()) {
                abort(403, __('Your account has been blocked.'));
            }

            return redirect()->route('login')->withErrors([
                'email' => __('Your account has been blocked. Please contact support.'),
            ]);
        }

        return $next($request);
    }
}
