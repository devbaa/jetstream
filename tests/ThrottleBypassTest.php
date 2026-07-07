<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

class ThrottleBypassTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        Jetstream::useUserModel(User::class);
    }

    protected function tearDown(): void
    {
        Jetstream::$bypassesThrottlingUsing = null;

        parent::tearDown();
    }

    protected function defineRoutes($router)
    {
        $router->get('/throttle-probe', fn () => response('ok'))
            ->middleware(['web', 'throttle:jetstream-guest']);
    }

    protected function createUser(bool $admin = false): User
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $admin ? 'admin@laravel.com' : 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        if ($admin) {
            $user->forceFill(['is_system_admin' => true])->save();
        }

        return $user;
    }

    protected function resolveLimit(string $limiter, Request $request): mixed
    {
        $callback = RateLimiter::limiter($limiter);

        $this->assertNotNull($callback);

        return $callback($request);
    }

    public function test_authenticated_requests_are_limited_per_user(): void
    {
        $user = $this->createUser();

        $request = Request::create('/user/profile');
        $request->setUserResolver(fn () => $user);

        $limit = $this->resolveLimit('jetstream', $request);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertNotInstanceOf(Unlimited::class, $limit);
        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame('user:'.$user->id, $limit->key);
    }

    public function test_guest_requests_are_limited_per_ip(): void
    {
        $request = Request::create('/portal/register/acme');

        $limit = $this->resolveLimit('jetstream-guest', $request);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(6, $limit->maxAttempts);
        $this->assertStringStartsWith('ip:', (string) $limit->key);
    }

    public function test_limits_are_configurable(): void
    {
        config(['jetstream.throttle.attempts' => 120]);

        $request = Request::create('/user/profile');

        $limit = $this->resolveLimit('jetstream', $request);

        $this->assertSame(120, $limit->maxAttempts);
    }

    public function test_system_admins_bypass_throttling(): void
    {
        $admin = $this->createUser(admin: true);

        $request = Request::create('/user/profile');
        $request->setUserResolver(fn () => $admin);

        $this->assertInstanceOf(Unlimited::class, $this->resolveLimit('jetstream', $request));
        $this->assertInstanceOf(Unlimited::class, $this->resolveLimit('jetstream-guest', $request));
    }

    public function test_configured_ip_addresses_bypass_throttling(): void
    {
        config(['jetstream.throttle.bypass_ips' => ['127.0.0.1']]);

        $request = Request::create('/portal/register/acme');

        $this->assertInstanceOf(Unlimited::class, $this->resolveLimit('jetstream-guest', $request));
    }

    public function test_a_custom_callback_can_grant_a_bypass(): void
    {
        Jetstream::bypassThrottlingUsing(function (Request $request): bool {
            return $request->header('X-Internal-Job') === 'true';
        });

        $request = Request::create('/user/profile');
        $request->headers->set('X-Internal-Job', 'true');

        $this->assertInstanceOf(Unlimited::class, $this->resolveLimit('jetstream', $request));

        $plain = Request::create('/user/profile');

        $this->assertNotInstanceOf(Unlimited::class, $this->resolveLimit('jetstream', $plain));
    }

    public function test_requests_over_the_limit_receive_a_429_response(): void
    {
        config(['jetstream.throttle.guest_attempts' => 2]);

        $this->get('/throttle-probe')->assertOk();
        $this->get('/throttle-probe')->assertOk();
        $this->get('/throttle-probe')->assertStatus(429);
    }

    public function test_bypassed_requests_are_never_throttled(): void
    {
        config(['jetstream.throttle.guest_attempts' => 2, 'jetstream.throttle.bypass_ips' => ['127.0.0.1']]);

        $this->get('/throttle-probe')->assertOk();
        $this->get('/throttle-probe')->assertOk();
        $this->get('/throttle-probe')->assertOk();
        $this->get('/throttle-probe')->assertOk();
    }
}
