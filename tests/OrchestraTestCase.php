<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\JetstreamServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;

abstract class OrchestraTestCase extends TestCase
{
    use LazilyRefreshDatabase, WithWorkbench;

    protected function setUp(): void
    {
        // Surface lazy loads and silently discarded fills as exceptions so
        // the suite runs Eloquent strictly. Missing-attribute prevention is
        // left off: models returned by create() do not carry their database
        // defaults, so it throws for real columns that were simply absent
        // from the insert.
        Model::preventLazyLoading();
        Model::preventSilentlyDiscardingAttributes();

        parent::setUp();
    }

    /**
     * Set up the environment every test class shares.
     *
     * Testbench calls this alongside defineEnvironment(), which the test
     * classes themselves override, so shared setup lives here where it
     * cannot be overwritten by a subclass forgetting to call the parent.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    #[\Override]
    protected function getEnvironmentSetUp($app)
    {
        // Testbench's in-memory sqlite connection stays the default, but an
        // explicit DB_CONNECTION wins so the suite can be pointed at a real
        // server (the PostgreSQL CI job) instead of being pinned to sqlite.
        $app->config->set('database.default', env('DB_CONNECTION', 'testing'));
    }

    protected function defineHasTeamEnvironment($app)
    {
        $features = $app->config->get('jetstream.features', []);

        $features[] = Features::teams(['invitations' => true]);

        $app->config->set('jetstream.features', $features);
    }

    protected function defineHasTenantEnvironment($app, bool $portal = true)
    {
        $this->defineHasTeamEnvironment($app);

        $features = $app->config->get('jetstream.features', []);

        $features[] = $portal
            ? Features::tenants(['portal' => true, 'customer-registration' => true])
            : Features::tenants();

        $app->config->set('jetstream.features', $features);
    }
}
