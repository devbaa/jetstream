<?php

declare(strict_types=1);

use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Jetstream Stack
    |--------------------------------------------------------------------------
    |
    | This configuration value informs Jetstream which "stack" you will be
    | using for your application. In general, this value is set for you
    | during installation and will not need to be changed after that.
    |
    */

    'stack' => 'livewire',

    /*
    |--------------------------------------------------------------------------
    | Jetstream Route Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Jetstream will assign to the routes
    | that it registers with the application. When necessary, you may modify
    | these middleware; however, this default value is usually sufficient.
    |
    */

    'middleware' => ['web'],

    'auth_session' => AuthenticateSession::class,

    /*
    |--------------------------------------------------------------------------
    | Jetstream Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify the authentication guard Jetstream will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'sanctum',

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of Jetstream's features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features or you can even remove all of these if you need to.
    |
    */

    'features' => [
        // Features::termsAndPrivacyPolicy(),
        // Features::profilePhotos(),
        // Features::api(),
        // Features::teams(['invitations' => true]),
        // Features::tenants(['portal' => true, 'customer-registration' => true]),
        // Features::domainAdmin(['multi-domain' => true]),
        Features::accountDeletion(),
        Features::dataPrivacy(),
        Features::accountRecovery(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenants
    |--------------------------------------------------------------------------
    |
    | When the tenants feature is enabled, this option controls whether any
    | registered user may create their own tenant. When disabled, tenants
    | may only be created by system administrators via the admin screen.
    |
    */

    'tenants' => [
        'self_service_creation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, every model that uses the Auditable trait records a full
    | change log — including the acting user, tenant, IP address, and user
    | agent — and authentication activity is recorded as well. Entries older
    | than the retention period are pruned by the jetstream:purge command;
    | a null retention keeps them forever.
    |
    */

    'audit' => [
        'enabled' => true,
        'retention_days' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Purge Retention
    |--------------------------------------------------------------------------
    |
    | Deleting a user, tenant, team, or customer account only soft deletes
    | it. The jetstream:purge command permanently erases soft-deleted
    | records once they have been trashed for this many days. Schedule the
    | command to run daily, passing --force so it does not prompt for
    | confirmation: Schedule::command('jetstream:purge --force')->daily().
    |
    */

    'purge' => [
        'retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Privacy
    |--------------------------------------------------------------------------
    |
    | When a user files a data deletion request (GDPR / CCPA / KVKK), the
    | request is held for this grace period before the jetstream:purge
    | command soft deletes the account. The user may cancel the request at
    | any time during the grace period.
    |
    */

    'privacy' => [
        'grace_period_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Jetstream's routes are throttled per user (or per IP for guests).
    | System administrators, the IP addresses listed below, and requests
    | approved by a Jetstream::bypassThrottlingUsing callback bypass the
    | limits entirely.
    |
    */

    'throttle' => [
        'attempts' => 60,
        'guest_attempts' => 6,
        'bypass_ips' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Administrator
    |--------------------------------------------------------------------------
    |
    | The SystemAdminSeeder flags the user with this email address as the
    | application's system administrator, granting access to the tenant
    | administration screens.
    |
    */

    'admin_email' => env('JETSTREAM_ADMIN_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Profile Photo Disk
    |--------------------------------------------------------------------------
    |
    | This configuration value determines the default disk that will be used
    | when storing profile photos for your application's users. Typically
    | this will be the "public" disk but you may adjust this if needed.
    |
    */

    'profile_photo_disk' => 'public',

];
