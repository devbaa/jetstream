<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\User as AppUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features as FortifyFeatures;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
use Laravel\Passkeys\Passkey;
use Laravel\Jetstream\Auth\BlockedUsers;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\Admin;
use Laravel\Jetstream\Tests\Fixtures\StandaloneUser;
use Laravel\Jetstream\Tests\Fixtures\StubPasskeyVerificationRequest;
use Laravel\Jetstream\Tests\Fixtures\User;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * A blocked user may neither establish nor resume authentication.
 *
 * Blocking writes blocked_at and revokes what the user already held — tokens
 * and database session rows. What it did not do was stop them authenticating
 * again. The only thing standing in the way was the `account.active`
 * middleware, which runs on the routes that carry it, after authentication has
 * already succeeded, and only for a user that is an instance of the published
 * App\Models\User.
 *
 * Each of these is a way in, and they are reproduced separately rather than
 * behind one "blocked login fails" assertion: password, an existing session on
 * a driver that keeps nothing in the database, a remember cookie, the two
 * factor challenge, a passkey, and a configured user model of the
 * application's own.
 */
class BlockedAuthenticationTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        // A driver that keeps nothing where blocking could reach it. This is
        // the arrangement the block-time revocation deliberately does not
        // cover, so it is the one these tests run under.
        $app->config->set('session.driver', 'array');

        $app->config->set('fortify.features', [
            FortifyFeatures::registration(),
            FortifyFeatures::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
            FortifyFeatures::passkeys(),
        ]);

        // A second guard authenticating something that is not Jetstream's
        // user at all — the shape of an application that runs its own admin,
        // customer or partner authentication alongside this package.
        $app->config->set('auth.guards.admin', [
            'driver' => 'session',
            'provider' => 'admins',
        ]);

        $app->config->set('auth.providers.admins', [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ]);

        // Both halves, together: the model Jetstream is told to manage is the
        // model the guard actually retrieves. An application that points one
        // at a class and leaves the other behind is already inconsistent
        // everywhere else in this package, and the boundary is no exception.
        $app->config->set('auth.providers.users.model', User::class);

        Jetstream::useUserModel(User::class);
    }

    /**
     * A route behind authentication and nothing else.
     *
     * account.active is what turned blocked users away, and it is only on the
     * routes this package registers. An application's own authenticated routes
     * — this one stands for all of them — never saw it.
     *
     * @param  \Illuminate\Routing\Router  $router
     */
    #[\Override]
    protected function defineRoutes($router)
    {
        $router->middleware(['web', 'auth'])->get('/probe', function () {
            return response()->json(['id' => auth()->id()]);
        });
    }

    protected function createUser(string $email = 'taylor@laravel.com', bool $blocked = false): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => now(),
            'blocked_at' => $blocked ? now() : null,
        ]);
    }

    /**
     * Whether an audit entry of the given event exists.
     */
    protected function recorded(string $event): bool
    {
        return DB::table('audit_logs')->where('event', $event)->exists();
    }

    public function test_a_blocked_user_can_sign_in_with_the_right_password(): void
    {
        $user = $this->createUser(blocked: true);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret',
        ]);

        $this->assertFalse(
            Auth::check(),
            'A blocked user authenticated with their password.'
        );

        $response->assertSessionHasErrors('email');
    }

    public function test_a_blocked_user_is_not_resumed_from_an_existing_session(): void
    {
        // The hole blocking's revocation deliberately leaves: on a session
        // driver that keeps nothing in the database there is no row to delete,
        // so an already-authenticated session outlives the block. It has to be
        // refused when it next tries to resolve its user — on any route, not
        // only the ones carrying account.active.
        $user = $this->createUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret']);

        $this->get('/probe')->assertOk()->assertJson(['id' => $user->getKey()]);

        DB::table('users')->where('id', $user->getKey())->update(['blocked_at' => now()]);

        $this->app['auth']->forgetGuards();

        // The same session, one request later. Nothing was deleted at block
        // time because this driver keeps nothing that could be.
        $this->get('/probe')->assertStatus(302);

        $this->assertFalse(
            Auth::check(),
            'A session established before the block still resolves its user.'
        );
    }

    public function test_a_blocked_user_cannot_return_on_a_remember_cookie(): void
    {
        $user = $this->createUser();

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret',
            'remember' => 'on',
        ]);

        $this->assertTrue(Auth::check());

        $recaller = Auth::guard()->getRecallerName();

        $value = null;

        foreach ($login->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $recaller) {
                $value = $cookie->getValue();
            }
        }

        $this->assertNotNull($value, 'Logging in with remember did not set a recaller.');

        DB::table('users')->where('id', $user->getKey())->update(['blocked_at' => now()]);

        $this->app['auth']->forgetGuards();

        // A new visitor with no session, carrying only the recaller — which is
        // the whole point of remember me.
        $this->flushSession();

        $this->withUnencryptedCookie($recaller, (string) $value)->get('/probe')->assertStatus(302);

        $this->assertFalse(
            Auth::check(),
            'A blocked user was restored from a remember cookie.'
        );
    }

    public function test_a_blocked_user_does_not_reach_the_two_factor_challenge(): void
    {
        $user = $this->createUser(blocked: true);

        DB::table('users')->where('id', $user->getKey())->update([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-one'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret',
        ]);

        $response->assertSessionMissing('login.id');

        $this->assertFalse(Auth::check());
    }

    public function test_a_refused_login_records_no_successful_authentication(): void
    {
        // The audit trail must not say a blocked user signed in. The
        // subscriber that writes auth.login listens to the same event the
        // block is enforced on, so the order the two are registered in decides
        // whether the record happens.
        $user = $this->createUser(blocked: true);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret',
        ]);

        $this->assertFalse(
            $this->recorded('auth.login'),
            'A blocked login attempt was recorded as a successful sign-in.'
        );
    }

    public function test_a_blocked_user_cannot_sign_in_with_a_passkey(): void
    {
        // Passkeys are a second front door. Fortify registers real login
        // endpoints for them, and this package must not assume its boundary
        // reaches something it never looked at.
        //
        // The controller is driven directly, with the WebAuthn verification
        // itself stood in for: proving a signature is Fortify's business, not
        // this package's. What is exercised is the rest of the controller —
        // in particular how it establishes the session once verification has
        // succeeded, which is the only part a blocking boundary can act on.
        $user = $this->createUser(blocked: true);

        $passkey = new Passkey;

        $passkey->setRelation('user', $user);

        $verify = $this->createStub(VerifyPasskey::class);

        $verify->method('__invoke')->willReturn($passkey);

        $request = StubPasskeyVerificationRequest::create('/passkeys/login', 'POST');

        $request->setLaravelSession($this->app['session']->driver());

        $refused = false;

        try {
            (new PasskeyLoginController)->store($request, $verify);
        } catch (\Throwable $e) {
            $refused = true;
        }

        $this->assertFalse(
            Auth::check(),
            'A blocked user authenticated with a passkey.'
        );

        $this->assertTrue($refused, 'The passkey login was not refused.');
    }

    public function test_a_refused_attempt_records_neither_a_login_nor_a_logout(): void
    {
        // Taking the state back is not the same as signing out, and must not
        // be recorded as one: an auth.logout for a session that was never
        // allowed to begin is as false as the auth.login would have been.
        $user = $this->createUser(blocked: true);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret']);

        $this->assertFalse($this->recorded('auth.login'), 'A refused attempt was recorded as a sign-in.');
        $this->assertFalse($this->recorded('auth.logout'), 'A refused attempt was recorded as a sign-out.');
    }

    public function test_the_blocking_listener_runs_before_the_audit_subscriber(): void
    {
        // Both listen to Login, Laravel calls listeners in registration order,
        // and the refusal throws — so the order is what decides whether the
        // audit trail gains a record of a sign-in this package then rejects.
        // Pinned as an ordering fact rather than left to the outcome above,
        // which would still pass if the two were merely swapped and the audit
        // happened to fail for some other reason.
        $listeners = Event::getListeners(\Illuminate\Auth\Events\Login::class);

        $this->assertGreaterThan(1, count($listeners), 'Nothing but the blocker is listening to Login.');

        $blocker = null;
        $auditor = null;

        foreach (array_values($listeners) as $position => $listener) {
            $description = $this->describe($listener);

            if ($blocker === null && str_contains($description, 'BlockedUsers')) {
                $blocker = $position;
            }

            if ($auditor === null && str_contains($description, 'AuthenticationEventSubscriber')) {
                $auditor = $position;
            }
        }

        $this->assertNotNull($blocker, 'The blocking listener is not registered on Login.');
        $this->assertNotNull($auditor, 'The audit subscriber is not registered on Login.');

        $this->assertLessThan($auditor, $blocker, 'The audit subscriber would record a blocked login before it is refused.');
    }

    /**
     * A printable description of a registered listener.
     */
    protected function describe(mixed $listener): string
    {
        if (is_string($listener)) {
            return $listener;
        }

        if (! $listener instanceof \Closure) {
            return get_debug_type($listener);
        }

        $bound = (new \ReflectionFunction($listener))->getStaticVariables();

        return json_encode(array_map(
            fn (mixed $value): string => is_array($value)
                ? implode('@', array_map(fn (mixed $part): string => is_string($part) ? $part : get_debug_type($part), $value))
                : (is_string($value) ? $value : get_debug_type($value)),
            $bound
        )) ?: '';
    }

    public function test_an_application_authenticate_using_callback_still_runs(): void
    {
        // The reason this is built on Laravel's authentication lifecycle and
        // not on Fortify::authenticateUsing(): that callback is a single slot
        // the application owns. Taking it would mean replacing an
        // application's authentication rather than composing with it.
        $user = $this->createUser();

        $called = false;

        Fortify::authenticateUsing(function ($request) use ($user, &$called) {
            $called = true;

            return $request->input('email') === $user->email ? $user : null;
        });

        try {
            $this->post('/login', ['email' => $user->email, 'password' => 'anything-at-all']);

            $this->assertTrue($called, 'The application callback was not consulted.');
            $this->assertTrue(Auth::check(), 'The application callback authenticated nobody.');
        } finally {
            Fortify::$authenticateUsingCallback = null;
        }
    }

    public function test_an_application_callback_does_not_let_a_blocked_user_through(): void
    {
        // And the other half: composing means the application still decides
        // whether the credentials are good, while this package still decides
        // whether that user may come in.
        $user = $this->createUser(blocked: true);

        Fortify::authenticateUsing(fn () => $user);

        try {
            $this->post('/login', ['email' => $user->email, 'password' => 'anything-at-all']);

            $this->assertFalse(Auth::check(), 'A blocked user was admitted by an application callback.');
        } finally {
            Fortify::$authenticateUsingCallback = null;
        }
    }

    public function test_an_unblocked_user_still_signs_in_normally(): void
    {
        $user = $this->createUser();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret',
        ]);

        $this->assertTrue(Auth::check(), 'An ordinary sign-in was refused.');

        $response->assertSessionHasNoErrors();

        unset($user);
    }

    public function test_a_configured_user_model_is_blocked_the_same_way(): void
    {
        // The middleware asks whether the user is an instance of the published
        // App\Models\User. useUserModel() exists so an application need not
        // use that class at all, and a model of its own is exactly the case
        // that gate silently does not cover.
        $this->assertFalse(
            is_a(StandaloneUser::class, AppUser::class, true),
            'The fixture extends the published model, so this proves nothing.'
        );

        config()->set('auth.providers.users.model', StandaloneUser::class);

        Jetstream::useUserModel(StandaloneUser::class);

        $user = StandaloneUser::forceCreate([
            'name' => 'Configured',
            'email' => 'configured@example.test',
            'password' => 'secret',
            'email_verified_at' => now(),
            'blocked_at' => now(),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret',
        ]);

        $this->assertFalse(
            Auth::check(),
            'A blocked user of a configured model authenticated with their password.'
        );
    }

    public function test_an_unrelated_authenticatable_on_another_guard_is_left_alone(): void
    {
        // The other edge of the same boundary. These are Laravel's
        // authentication events, not Fortify's and not this package's routes,
        // so they fire for every guard an application has. An application that
        // authenticates an admin, a customer or a partner on a guard of its own
        // owns that model's semantics — including whatever it means by a column
        // it happens to have called blocked_at.
        //
        // Jetstream enforces blocking on the model it manages. It does not get
        // to enforce it on a model it has never heard of.
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();
        });

        $this->assertSame(User::class, Jetstream::userModel());

        $this->assertFalse(
            is_a(Admin::class, Jetstream::userModel(), true),
            'The fixture is a Jetstream user after all, so this proves nothing.'
        );

        $admin = Admin::forceCreate([
            'name' => 'Unrelated',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
            // Non-null, and none of this package's business.
            'blocked_at' => now(),
        ]);

        Auth::guard('admin')->login($admin);

        $this->assertTrue(
            Auth::guard('admin')->check(),
            'Jetstream refused an authentication on a guard whose model it does not manage.'
        );

        // And again on the resume path, which is the listener that fires on
        // every request rather than only at sign-in.
        $this->app['auth']->forgetGuards();

        $this->assertSame(
            $admin->getKey(),
            Auth::guard('admin')->id(),
            'Jetstream refused to resume an authentication on a guard whose model it does not manage.'
        );

        // Meanwhile the model it does manage is still blocked, on the same
        // request, from the same predicate.
        $this->assertTrue(
            app(BlockedUsers::class)->isBlocked($this->createUser(blocked: true))
        );
    }

    public function test_the_challenge_refusal_acts_on_fortifys_guard_not_the_default_one(): void
    {
        // The challenge event carries a user and no guard. Fortify's own
        // StatefulGuard is bound from config('fortify.guard'), which an
        // application may point away from the default — and Fortify is what
        // wrote the half-finished login state a moment earlier, so it is
        // Fortify's guard whose state is being taken back.
        //
        // What separates the two is the recaller. Invalidating the session
        // clears everything in it whichever guard is named, but a remember
        // cookie survives session invalidation by design — that is what it is
        // for — so expiring the default guard's recaller here would sign out
        // an unrelated, unblocked, remembered user.
        config()->set('auth.guards.members', ['driver' => 'session', 'provider' => 'users']);
        config()->set('fortify.guard', 'members');

        $this->assertSame('web', config('auth.defaults.guard'), 'The two guards are the same, so this proves nothing.');

        $user = $this->createUser(blocked: true);

        try {
            app(BlockedUsers::class)->rejectChallenge(new TwoFactorAuthenticationChallenged($user));

            $this->fail('The challenge was not refused.');
        } catch (ValidationException) {
            // The refusal itself is asserted elsewhere; what is under test
            // here is which guard it cleaned up after.
        }

        $expired = array_map(
            fn (Cookie $cookie): string => $cookie->getName(),
            app('cookie')->getQueuedCookies()
        );

        $this->assertContains(
            Auth::guard('members')->getRecallerName(),
            $expired,
            "Fortify's own guard kept its remember cookie after the challenge was refused."
        );

        $this->assertNotContains(
            Auth::guard('web')->getRecallerName(),
            $expired,
            'Refusing a challenge expired the remember cookie of a guard Fortify was not using.'
        );
    }
}
