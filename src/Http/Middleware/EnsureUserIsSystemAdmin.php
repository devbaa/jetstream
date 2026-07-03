<?php

namespace Laravel\Jetstream\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsSystemAdmin
{
    /**
     * Only allow application administrators through.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_unless(
            $user && method_exists($user, 'isSystemAdmin') && $user->isSystemAdmin(),
            403
        );

        return $next($request);
    }
}
