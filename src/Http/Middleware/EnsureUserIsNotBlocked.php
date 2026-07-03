<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

class EnsureUserIsNotBlocked
{
    /**
     * Log out and turn away users that have been blocked system-wide.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user instanceof \App\Models\User && $user->isBlocked()) {
            app(StatefulGuard::class)->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

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
