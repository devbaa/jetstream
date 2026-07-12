<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Jetstream\Jetstream;

class SystemAdminSeeder extends Seeder
{
    /**
     * Flag the configured user as a system administrator.
     *
     * Set JETSTREAM_ADMIN_EMAIL in your environment to the email address of
     * the user that operates the application itself.
     */
    public function run(): void
    {
        $email = config('jetstream.admin_email');

        if (! is_string($email) || $email === '') {
            return;
        }

        Jetstream::newUserModel()
            ->newQuery()
            ->where('email', $email)
            ->update(['is_system_admin' => true]);
    }
}
