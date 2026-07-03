<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Audit;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

/**
 * Records authentication activity — including IP address and user agent —
 * in the application's audit log.
 */
class AuthenticationEventSubscriber
{
    /**
     * Create a new subscriber instance.
     */
    public function __construct(protected Auditor $auditor)
    {
    }

    /**
     * Handle successful login events.
     */
    public function handleLogin(Login $event): void
    {
        $this->record('auth.login', $event->user);
    }

    /**
     * Handle logout events.
     */
    public function handleLogout(Logout $event): void
    {
        if ($event->user !== null) {
            $this->record('auth.logout', $event->user);
        }
    }

    /**
     * Handle failed authentication attempts.
     */
    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        $this->record('auth.failed', $event->user, [
            'email' => is_string($email) ? $email : null,
        ]);
    }

    /**
     * Handle password reset events.
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->record('auth.password_reset', $event->user);
    }

    /**
     * Handle new user registration events.
     */
    public function handleRegistered(Registered $event): void
    {
        $this->record('auth.registered', $event->user);
    }

    /**
     * Record the given authentication event in the audit log.
     *
     * @param  array<string, mixed>  $context
     */
    protected function record(string $event, ?Authenticatable $user, array $context = []): void
    {
        $id = $user?->getAuthIdentifier();

        $this->auditor->record(
            $event,
            $user instanceof Model ? $user : null,
            [],
            $context,
            is_int($id) ? $id : null,
        );
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            PasswordReset::class => 'handlePasswordReset',
            Registered::class => 'handleRegistered',
        ];
    }
}
