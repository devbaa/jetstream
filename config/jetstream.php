<?php

declare(strict_types=1);

use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Middleware\AuthenticateSession;

return [
    'stack' => 'livewire',
    'middleware' => ['web'],
    'auth_session' => AuthenticateSession::class,
    'features' => [
        Features::accountDeletion(),
        Features::dataPrivacy(),
        Features::accountRecovery(),
    ],
    'admin_email' => env('JETSTREAM_ADMIN_EMAIL'),
    'tenants' => [
        'self_service_creation' => true,
    ],
    'audit' => [
        'enabled' => true,
        'retention_days' => null,
    ],
    'purge' => [
        'retention_days' => 30,
    ],
    'privacy' => [
        'grace_period_days' => 30,
    ],
    'throttle' => [
        'attempts' => 60,
        'guest_attempts' => 6,
        'bypass_ips' => [],
    ],
    'profile_photo_disk' => 'public',
];
