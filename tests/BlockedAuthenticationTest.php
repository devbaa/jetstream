<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\User as AppUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features as FortifyFeatures;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
use Laravel\Passkeys\Passkey;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\StandaloneUser;
use Laravel\Jetstream\Tests\Fixtures\StubPasskeyVerificationRequest;
use Laravel\Jetstream\Tests\Fixtures\User;

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

        $verify = $this->createMock(VerifyPasskey::class);

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
}
