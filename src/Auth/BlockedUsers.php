<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Fortify;

/**
 * The one place this package decides that a blocked user may not authenticate.
 *
 * Blocking used to be enforced by a single middleware, which could only do so
 * on the routes carrying it, only after authentication had already succeeded,
 * and only for a user that happened to be an instance of the published
 * App\Models\User. Everything else — an application's own authenticated routes,
 * a session on a driver that block-time revocation cannot reach, a remember
 * cookie, a passkey, a configured user model — went straight past it.
 *
 * The rule is stated here instead, at the authentication boundary itself:
 *
 *     A blocked user may neither establish nor resume stateful authentication.
 *
 * It is enforced from Laravel's own authentication lifecycle rather than from
 * Fortify's credential callback or pipeline, which are single, application-
 * owned slots — taking one would mean replacing an application's
 * authentication instead of composing with it. The lifecycle events are
 * additive, so an application's Fortify::authenticateUsing() still runs and
 * still decides whether the credentials are good; this only decides whether
 * the user they belong to may come in.
 */
class BlockedUsers
{
    /**
     * Whether the given user is blocked.
     *
     * blocked_at is the state BlockUser writes and the state this package
     * documents as authoritative, so it is what is read — not a method the
     * user model may happen to expose. A configured model is entitled to
     * define isBlocked() with semantics of its own; it is not entitled to
     * redefine what this package means by blocked.
     */
    public function isBlocked(mixed $user): bool
    {
        if (! $user instanceof Model) {
            return false;
        }

        return $user->getAttribute('blocked_at') !== null;
    }

    /**
     * Refuse authentication that is being established now.
     *
     * Login fires for every way a session is newly established: credentials,
     * the second factor completing, a passkey, and a remember cookie being
     * redeemed. By the time it fires the guard has already written its session
     * key and may already have queued the recaller, so refusing means undoing
     * that rather than merely declining to continue.
     */
    public function rejectLogin(Login $event): void
    {
        if (! $this->isBlocked($event->user)) {
            return;
        }

        $this->discard(Auth::guard($event->guard));

        throw ValidationException::withMessages([
            Fortify::username() => [$this->message()],
        ]);
    }

    /**
     * Refuse authentication that already existed when this request began.
     *
     * Authenticated fires when a guard resolves a user from a session that was
     * established earlier — including on session drivers that keep nothing
     * blocking could have deleted. This is what makes the block take effect on
     * the next request rather than the next visit to a route that happens to
     * carry the middleware.
     */
    public function rejectAuthenticated(Authenticated $event): void
    {
        if (! $this->isBlocked($event->user)) {
            return;
        }

        $this->discard(Auth::guard($event->guard));

        throw new AuthenticationException($this->message());
    }

    /**
     * Refuse a second factor challenge before it is presented.
     *
     * Fortify validates credentials, puts login.id into the session and only
     * then announces the challenge, so a blocked account would otherwise get
     * as far as being asked for a code. The Login refusal above is still what
     * decides the outcome — an account blocked between the challenge and the
     * code being entered has to be caught there — but there is no reason to
     * let it into the challenge in the first place.
     */
    public function rejectChallenge(TwoFactorAuthenticationChallenged $event): void
    {
        if (! $this->isBlocked($event->user)) {
            return;
        }

        $this->discard(Auth::guard());

        throw ValidationException::withMessages([
            Fortify::username() => [$this->message()],
        ]);
    }

    /**
     * Take back whatever authentication state has been established.
     *
     * Deliberately not $guard->logout(). That resolves the user on its way
     * through — which would re-enter this class — and it announces a Logout,
     * which this package records as auth.logout: an authoritative record of a
     * session ending that was never allowed to begin. What is wanted is the
     * state gone, not a departure announced.
     */
    public function discard(Guard $guard): void
    {
        if (method_exists($guard, 'forgetUser')) {
            $guard->forgetUser();
        }

        $this->forgetSession($guard);
        $this->forgetRecaller($guard);
    }

    /**
     * Remove the session state a login writes, and the half-finished second
     * factor state a challenge writes.
     */
    protected function forgetSession(Guard $guard): void
    {
        // The guard's own session, not the ambient request's. They are the
        // same store in an ordinary request, but a guard can be driven from
        // somewhere that has no current request bound — and leaving the
        // guard's session key behind is exactly how a refused login comes back
        // on the next resolve.
        $session = method_exists($guard, 'getSession') ? $guard->getSession() : null;

        if ($session === null && request()->hasSession()) {
            $session = request()->session();
        }

        if ($session === null) {
            return;
        }

        if (method_exists($guard, 'getName')) {
            $session->forget($guard->getName());
        }

        $session->forget(['login.id', 'login.remember', 'password_hash_'.Auth::getDefaultDriver()]);

        $session->invalidate();
        $session->regenerateToken();
    }

    /**
     * Drop the recaller, both the one already sent and the one this request
     * queued a moment ago.
     */
    protected function forgetRecaller(Guard $guard): void
    {
        if (! method_exists($guard, 'getRecallerName')) {
            return;
        }

        $name = $guard->getRecallerName();

        $cookies = app('cookie');

        $cookies->unqueue($name);
        $cookies->queue($cookies->forget($name));
    }

    /**
     * What a refused user is told.
     */
    protected function message(): string
    {
        return __('Your account has been blocked. Please contact support.');
    }
}
