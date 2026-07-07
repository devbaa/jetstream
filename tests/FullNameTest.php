<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Fortify\UpdateUserProfileInformation;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

class FullNameTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        Jetstream::useUserModel(User::class);
    }

    protected function createUser(): User
    {
        return User::forceCreate([
            'name' => 'Taylor',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);
    }

    public function test_full_name_composes_the_available_name_parts(): void
    {
        $user = $this->createUser();

        $this->assertSame('Taylor', $user->fullName());

        $user->forceFill(['last_name' => 'Otwell'])->save();

        $this->assertSame('Taylor Otwell', $user->fullName());

        $user->forceFill(['middle_name' => 'James'])->save();

        $this->assertSame('Taylor James Otwell', $user->fullName());
    }

    public function test_profile_information_accepts_the_new_name_parts(): void
    {
        $user = $this->createUser();

        (new UpdateUserProfileInformation)->update($user, [
            'name' => 'Taylor',
            'middle_name' => ' James ',
            'last_name' => 'Otwell',
            'email' => 'taylor@laravel.com',
        ]);

        $user->refresh();

        $this->assertSame('James', $user->middle_name);
        $this->assertSame('Otwell', $user->last_name);
        $this->assertSame('Taylor James Otwell', $user->fullName());
    }

    public function test_blank_name_parts_are_normalized_to_null(): void
    {
        $user = $this->createUser();

        $user->forceFill(['middle_name' => 'James', 'last_name' => 'Otwell'])->save();

        (new UpdateUserProfileInformation)->update($user, [
            'name' => 'Taylor',
            'middle_name' => '',
            'last_name' => '',
            'email' => 'taylor@laravel.com',
        ]);

        $user->refresh();

        $this->assertNull($user->middle_name);
        $this->assertNull($user->last_name);
        $this->assertSame('Taylor', $user->fullName());
    }
}
