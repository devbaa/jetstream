<?php

declare(strict_types=1);

use Laravel\Jetstream\Features;

return [
    'stack' => 'livewire',
    'middleware' => ['web'],
    'features' => [Features::accountDeletion()],
    'admin_email' => env('JETSTREAM_ADMIN_EMAIL'),
    'tenants' => [
        'self_service_creation' => true,
    ],
    'profile_photo_disk' => 'public',
];
