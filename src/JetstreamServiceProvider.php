<?php

namespace Laravel\Jetstream;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Inertia\Inertia;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Fortify;
use Laravel\Jetstream\Http\Livewire\Admin\TenantManager as AdminTenantManager;
use Laravel\Jetstream\Http\Livewire\ApiTokenManager;
use Laravel\Jetstream\Http\Livewire\CreateTeamForm;
use Laravel\Jetstream\Http\Livewire\CreateTenantForm;
use Laravel\Jetstream\Http\Livewire\CustomerAccountManager;
use Laravel\Jetstream\Http\Livewire\DeleteTeamForm;
use Laravel\Jetstream\Http\Livewire\DeleteTenantForm;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm;
use Laravel\Jetstream\Http\Livewire\LogoutOtherBrowserSessionsForm;
use Laravel\Jetstream\Http\Livewire\NavigationMenu;
use Laravel\Jetstream\Http\Livewire\Portal\AccountMemberManager;
use Laravel\Jetstream\Http\Livewire\Portal\UpdateAccountNameForm;
use Laravel\Jetstream\Http\Livewire\RoleManager;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Http\Livewire\TenantStaffManager;
use Laravel\Jetstream\Http\Livewire\TwoFactorAuthenticationForm;
use Laravel\Jetstream\Http\Livewire\UpdatePasswordForm;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Laravel\Jetstream\Http\Livewire\UpdateTeamNameForm;
use Laravel\Jetstream\Http\Livewire\UpdateTenantNameForm;
use Laravel\Jetstream\Http\Middleware\EnsureCustomerAccountContext;
use Laravel\Jetstream\Http\Middleware\EnsureTenantContext;
use Laravel\Jetstream\Http\Middleware\EnsureUserIsSystemAdmin;
use Laravel\Jetstream\Http\Middleware\ShareInertiaData;
use Laravel\Jetstream\Tenancy\CustomerContext;
use Laravel\Jetstream\Tenancy\TenantContext;
use Livewire\Livewire;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jetstream.php', 'jetstream');

        $this->app->scoped(TenantContext::class);
        $this->app->scoped(CustomerContext::class);
        $this->app->scoped(RoleRegistry::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Fortify::viewPrefix('auth.');

        $this->configurePublishing();
        $this->configureRoutes();
        $this->configureCommands();
        $this->configureTenancy();

        RedirectResponse::macro('banner', function ($message): RedirectResponse {
            /** @var \Illuminate\Http\RedirectResponse $this */
            return $this->with('flash', [
                'bannerStyle' => 'success',
                'banner' => $message,
            ]);
        });

        RedirectResponse::macro('warningBanner', function ($message): RedirectResponse {
            /** @var \Illuminate\Http\RedirectResponse $this */
            return $this->with('flash', [
                'bannerStyle' => 'warning',
                'banner' => $message,
            ]);
        });

        RedirectResponse::macro('dangerBanner', function ($message): RedirectResponse {
            /** @var \Illuminate\Http\RedirectResponse $this */
            return $this->with('flash', [
                'bannerStyle' => 'danger',
                'banner' => $message,
            ]);
        });

        if (config('jetstream.stack') === 'inertia' && class_exists(Inertia::class)) {
            $this->bootInertia();
        }

        if (config('jetstream.stack') === 'livewire' && class_exists(Livewire::class)) {
            Livewire::component('navigation-menu', NavigationMenu::class);
            Livewire::component('profile.update-profile-information-form', UpdateProfileInformationForm::class);
            Livewire::component('profile.update-password-form', UpdatePasswordForm::class);
            Livewire::component('profile.two-factor-authentication-form', TwoFactorAuthenticationForm::class);
            Livewire::component('profile.logout-other-browser-sessions-form', LogoutOtherBrowserSessionsForm::class);
            Livewire::component('profile.delete-user-form', DeleteUserForm::class);

            if (Features::hasApiFeatures()) {
                Livewire::component('api.api-token-manager', ApiTokenManager::class);
            }

            if (Features::hasTeamFeatures()) {
                Livewire::component('teams.create-team-form', CreateTeamForm::class);
                Livewire::component('teams.update-team-name-form', UpdateTeamNameForm::class);
                Livewire::component('teams.team-member-manager', TeamMemberManager::class);
                Livewire::component('teams.delete-team-form', DeleteTeamForm::class);
            }

            if (Features::hasTenantFeatures()) {
                Livewire::component('tenants.create-tenant-form', CreateTenantForm::class);
                Livewire::component('tenants.update-tenant-name-form', UpdateTenantNameForm::class);
                Livewire::component('tenants.tenant-staff-manager', TenantStaffManager::class);
                Livewire::component('tenants.role-manager', RoleManager::class);
                Livewire::component('tenants.delete-tenant-form', DeleteTenantForm::class);
                Livewire::component('customers.customer-account-manager', CustomerAccountManager::class);
                Livewire::component('admin.tenant-manager', AdminTenantManager::class);
            }

            if (Features::hasCustomerPortalFeatures()) {
                Livewire::component('portal.update-account-name-form', UpdateAccountNameForm::class);
                Livewire::component('portal.account-member-manager', AccountMemberManager::class);
            }
        }
    }

    /**
     * Configure the middleware used to resolve tenant and customer context.
     *
     * @return void
     */
    protected function configureTenancy()
    {
        if (! Features::hasTenantFeatures()) {
            return;
        }

        Route::aliasMiddleware('tenant.context', EnsureTenantContext::class);
        Route::aliasMiddleware('customer.context', EnsureCustomerAccountContext::class);
        Route::aliasMiddleware('system.admin', EnsureUserIsSystemAdmin::class);
    }

    /**
     * Configure publishing for the package.
     *
     * @return void
     */
    protected function configurePublishing()
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../stubs/config/jetstream.php' => config_path('jetstream.php'),
        ], 'jetstream-config');

        $this->publishes([
            __DIR__.'/../database/migrations/0001_01_01_000000_create_users_table.php' => database_path('migrations/0001_01_01_000000_create_users_table.php'),
        ], 'jetstream-migrations');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/2020_05_21_100000_create_teams_table.php' => database_path('migrations/2020_05_21_100000_create_teams_table.php'),
            __DIR__.'/../database/migrations/2020_05_21_200000_create_team_user_table.php' => database_path('migrations/2020_05_21_200000_create_team_user_table.php'),
            __DIR__.'/../database/migrations/2020_05_21_300000_create_team_invitations_table.php' => database_path('migrations/2020_05_21_300000_create_team_invitations_table.php'),
        ], 'jetstream-team-migrations');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/2026_07_03_100000_create_tenants_table.php' => database_path('migrations/2026_07_03_100000_create_tenants_table.php'),
            __DIR__.'/../database/migrations/2026_07_03_200000_create_tenant_user_table.php' => database_path('migrations/2026_07_03_200000_create_tenant_user_table.php'),
            __DIR__.'/../database/migrations/2026_07_03_300000_create_roles_table.php' => database_path('migrations/2026_07_03_300000_create_roles_table.php'),
            __DIR__.'/../database/migrations/2026_07_03_400000_add_tenant_columns.php' => database_path('migrations/2026_07_03_400000_add_tenant_columns.php'),
            __DIR__.'/../database/migrations/2026_07_03_500000_create_customer_accounts_table.php' => database_path('migrations/2026_07_03_500000_create_customer_accounts_table.php'),
            __DIR__.'/../database/migrations/2026_07_03_600000_create_customer_account_user_table.php' => database_path('migrations/2026_07_03_600000_create_customer_account_user_table.php'),
            __DIR__.'/../database/migrations/2026_07_03_700000_create_customer_invitations_table.php' => database_path('migrations/2026_07_03_700000_create_customer_invitations_table.php'),
        ], 'jetstream-tenant-migrations');

        $this->publishes([
            __DIR__.'/../routes/'.config('jetstream.stack').'.php' => base_path('routes/jetstream.php'),
        ], 'jetstream-routes');

        $this->publishes([
            __DIR__.'/../stubs/inertia/resources/js/Pages/Auth' => resource_path('js/Pages/Auth'),
            __DIR__.'/../stubs/inertia/resources/js/Components/AuthenticationCard.vue' => resource_path('js/Components/AuthenticationCard.vue'),
            __DIR__.'/../stubs/inertia/resources/js/Components/AuthenticationCardLogo.vue' => resource_path('js/Components/AuthenticationCardLogo.vue'),
            __DIR__.'/../stubs/inertia/resources/js/Components/Checkbox.vue' => resource_path('js/Components/Checkbox.vue'),
        ], 'jetstream-inertia-auth-pages');
    }

    /**
     * Configure the routes offered by the application.
     *
     * @return void
     */
    protected function configureRoutes()
    {
        if (Jetstream::$registersRoutes) {
            Route::group([
                'namespace' => 'Laravel\Jetstream\Http\Controllers',
                'domain' => config('jetstream.domain', null),
                'prefix' => config('jetstream.prefix', config('jetstream.path')),
            ], function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/'.config('jetstream.stack').'.php');
            });
        }
    }

    /**
     * Configure the commands offered by the application.
     *
     * @return void
     */
    protected function configureCommands()
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\InstallCommand::class,
        ]);
    }

    /**
     * Boot any Inertia related services.
     *
     * @return void
     */
    protected function bootInertia()
    {
        $kernel = $this->app->make(Kernel::class);

        $kernel->appendMiddlewareToGroup('web', ShareInertiaData::class);
        $kernel->appendToMiddlewarePriority(ShareInertiaData::class);

        if (class_exists(HandleInertiaRequests::class)) {
            $kernel->appendToMiddlewarePriority(HandleInertiaRequests::class);
        }

        Event::listen(function (PasswordUpdatedViaController $event) {
            if (request()->hasSession()) {
                request()->session()->put(['password_hash_sanctum' => Auth::user()->getAuthPassword()]);
            }
        });

        Fortify::loginView(function () {
            return Inertia::render('Auth/Login', [
                'canResetPassword' => Route::has('password.request'),
                'status' => session('status'),
            ]);
        });

        Fortify::requestPasswordResetLinkView(function () {
            return Inertia::render('Auth/ForgotPassword', [
                'status' => session('status'),
            ]);
        });

        Fortify::resetPasswordView(function (Request $request) {
            return Inertia::render('Auth/ResetPassword', [
                'email' => $request->input('email'),
                'token' => $request->route('token'),
            ]);
        });

        Fortify::registerView(function () {
            return Inertia::render('Auth/Register');
        });

        Fortify::verifyEmailView(function () {
            return Inertia::render('Auth/VerifyEmail', [
                'status' => session('status'),
            ]);
        });

        Fortify::twoFactorChallengeView(function () {
            return Inertia::render('Auth/TwoFactorChallenge');
        });

        Fortify::confirmPasswordView(function () {
            return Inertia::render('Auth/ConfirmPassword');
        });
    }
}
