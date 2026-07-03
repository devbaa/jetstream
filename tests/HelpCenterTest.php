<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

class HelpCenterTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTenantEnvironment($app);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Jetstream::useUserModel(User::class);
    }

    public function test_the_account_help_route_is_registered(): void
    {
        $this->assertTrue(Route::has('help.account'));
    }

    public function test_the_tenant_help_route_is_registered_when_tenant_features_are_enabled(): void
    {
        $this->assertTrue(Route::has('help.tenant'));
    }

    public function test_the_account_help_controller_returns_the_help_view(): void
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $request = \Illuminate\Http\Request::create('/help/account');
        $request->setUserResolver(fn () => $user);

        $view = (new \Laravel\Jetstream\Http\Controllers\Livewire\HelpController)->account($request);

        $this->assertSame('help.account', $view->name());
    }

    public function test_the_tenant_help_controller_returns_the_help_view(): void
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $request = \Illuminate\Http\Request::create('/help/tenant');
        $request->setUserResolver(fn () => $user);

        $view = (new \Laravel\Jetstream\Http\Controllers\Livewire\HelpController)->tenant($request);

        $this->assertSame('help.tenant', $view->name());
    }
}
