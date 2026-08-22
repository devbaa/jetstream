<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Actions\BlockUser;
use Laravel\Jetstream\Http\Livewire\Admin\UserManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;
use Laravel\Sanctum\SanctumServiceProvider;
use Livewire\Livewire;

/**
 * Blocking a user takes away the credentials they already hold.
 *
 * The documented scope of a block is "the whole application, every
 * organization", and its documented effect is that the user "is signed out
 * everywhere and cannot sign in". The `account.active` middleware only
 * delivers that for requests that pass through it — Jetstream's own route
 * group. An application's own API routes are guarded by `auth:sanctum` and
 * nothing else, and a personal access token issued before the block is a
 * credential that never goes near the middleware.
 */
class BlockedUserCredentialsTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [SanctumServiceProvider::class]);
    }

    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        Jetstream::useUserModel(User::class);

        // What "php artisan install:api" leaves in an application's auth config.
        $app->config->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => null,
        ]);
    }

    /**
     * An API route of the application's own, guarded the way they are guarded.
     */
    protected function defineRoutes($router)
    {
        $router->get('/api/probe', fn () => response('ok'))->middleware('auth:sanctum');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Touching the database first runs the lazy refresh, which migrates
        // from scratch; creating the table before that would just be wiped.
        // Created after it, the table lives inside the test's transaction and
        // is rolled back with everything else.
        Schema::hasTable('migrations');

        // Sanctum's own migration types tokenable_id as a bigint, which does
        // not hold this package's UUID keys on a strict driver. That is its
        // own defect and its own change; this table is created here in the
        // shape the fix will give it so this test says nothing about it
        // either way and runs on every driver.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    protected function createUser(string $email = 'taylor@laravel.com'): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    /**
     * Issue a personal access token and return the plain text of it.
     */
    protected function tokenFor(User $user): string
    {
        return $user->createToken('probe')->plainTextToken;
    }

    /**
     * Call the application's API route with the given token.
     */
    protected function callProbe(string $token): \Illuminate\Testing\TestResponse
    {
        // Guards resolve their user once and hold it. A real request gets a
        // fresh container; inside one test the application is shared, so
        // without this a second call would be answered by the first call's
        // already-authenticated user and prove nothing.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/probe');
    }

    /**
     * Block a user the way the application blocks one.
     */
    protected function block(User $user): void
    {
        app(BlockUser::class)->block($user, 'Abuse');
    }

    protected function unblock(User $user): void
    {
        $user->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();
    }

    public function test_a_token_authenticates_before_the_block(): void
    {
        $user = $this->createUser();

        $this->callProbe($this->tokenFor($user))->assertOk();
    }

    public function test_a_token_issued_before_the_block_stops_authenticating(): void
    {
        $user = $this->createUser();

        $token = $this->tokenFor($user);

        $this->callProbe($token)->assertOk();

        $this->block($user);

        $this->callProbe($token)->assertUnauthorized();
    }

    public function test_unblocking_does_not_resurrect_the_old_token(): void
    {
        $user = $this->createUser();

        $token = $this->tokenFor($user);

        $this->block($user);

        $this->unblock($user);

        // The credential existed before the block. Nothing about lifting the
        // block makes it valid again.
        $this->callProbe($token)->assertUnauthorized();
    }

    public function test_a_token_issued_after_an_unblock_works_normally(): void
    {
        $user = $this->createUser();

        $this->block($user);

        $this->unblock($user);

        $this->callProbe($this->tokenFor($user->refresh()))->assertOk();
    }

    public function test_the_database_sessions_of_a_blocked_user_are_deleted(): void
    {
        // "Signed out everywhere" is not the same as "turned away at the door
        // next time". With the database session driver the installer
        // configures, the session row is a credential of its own.
        $this->app['config']->set('session.driver', 'database');

        $user = $this->createUser();

        DB::table('sessions')->insert([
            'id' => 'session-of-the-blocked-user',
            'user_id' => $user->id,
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $this->block($user);

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_a_system_administrator_blocking_from_the_admin_screen_revokes_the_token(): void
    {
        // The documented path, driven the way an administrator drives it,
        // rather than by calling the action directly.
        $this->app['config']->set('view.paths', array_merge(
            $this->app['config']->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        $admin = $this->createUser('admin@laravel.com');
        $admin->forceFill(['is_system_admin' => true])->save();

        $subject = $this->createUser();

        $token = $this->tokenFor($subject);

        $this->callProbe($token)->assertOk();

        $this->actingAs($admin);

        Livewire::test(UserManager::class)
            ->call('confirmUserBlock', $subject->id)
            ->set('blockReason', 'Compromised account')
            ->call('blockUser')
            ->assertHasNoErrors();

        $this->assertTrue($subject->fresh()->isBlocked());

        $this->callProbe($token)->assertUnauthorized();
    }

    public function test_blocking_works_in_an_application_installed_without_the_api_feature(): void
    {
        // The published user model carries HasApiTokens whether or not the
        // API feature was installed, so the trait is no evidence that the
        // table exists. Blocking must not depend on it.
        Schema::drop('personal_access_tokens');

        $user = $this->createUser();

        $this->block($user);

        $this->assertTrue($user->fresh()->isBlocked());
    }

    public function test_another_users_credentials_are_untouched(): void
    {
        $blocked = $this->createUser();
        $other = $this->createUser('adam@laravel.com');

        $token = $this->tokenFor($other);

        $this->block($blocked);

        $this->callProbe($token)->assertOk();
    }
}
