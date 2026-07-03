<?php

use Laravel\Jetstream\Features;

return [
    'stack' => 'inertia',
    'middleware' => ['web'],
    'features' => [Features::accountDeletion()],
    'tenants' => [
        'self_service_creation' => true,
    ],
    'profile_photo_disk' => 'public',
];
