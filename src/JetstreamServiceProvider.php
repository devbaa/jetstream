<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Features as FortifyFeatures;
use Laravel\Fortify\Fortify;
use Laravel\Jetstream\Audit\AuthenticationEventSubscriber;
use Laravel\Jetstream\Contracts\VerifiesDomains;
use Laravel\Jetstream\Domains\VerifyDomainViaDnsOrMeta;
use Laravel\Jetstream\Http\Livewire\Admin\TenantManager as AdminTenantManager;
use Laravel\Jetstream\Http\Livewire\Admin\UserManager as AdminUserManager;
use Laravel\Jetstream\Http\Livewire\ApiTokenManager;
use Laravel\Jetstream\Http\Livewire\AuditLogViewer;
use Laravel\Jetstream\Http\Livewire\CreateTeamForm;
use Laravel\Jetstream\Http\Livewire\CreateTenantForm;
use Laravel\Jetstream\Http\Livewire\CustomerAccountManager;
use Laravel\Jetstream\Http\Livewire\DataPrivacyForm;
use Laravel\Jetstream\Http\Livewire\DeleteTeamForm;
use Laravel\Jetstream\Http\Livewire\DeleteTenantForm;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm;
use Laravel\Jetstream\Http\Livewire\DomainAdminManager;
use Laravel\Jetstream\Http\Livewire\LogoutOtherBrowserSessionsForm;
use Laravel\Jetstream\Http\Livewire\NavigationMenu;
use Laravel\Jetstream\Http\Livewire\PasskeyManager;
use Laravel\Jetstream\Http\Livewire\Portal\AccountMemberManager;
use Laravel\Jetstream\Http\Livewire\Portal\UpdateAccountNameForm;
use Laravel\Jetstream\Http\Livewire\RoleManager;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Http\Livewire\TenantStaffManager;
use Laravel\Jetstream\Http\Livewire\TwoFactorAuthenticationForm;
use Laravel\Jetstream\Http\Livewire\UpdatePasswordForm;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Laravel\Jetstream\Http\Livewire\UpdateRecoveryChannelsForm;
use Laravel\Jetstream\Http\Livewire\UpdateTeamNameForm;
use Laravel\Jetstream\Http\Livewire\UpdateTenantNameForm;
use Laravel\Jetstream\Http\Middleware\EnsureCustomerAccountContext;
use Laravel\Jetstream\Http\Middleware\EnsureTenantContext;
use Laravel\Jetstream\Http\Middleware\EnsureUserIsNotBlocked;
use Laravel\Jetstream\Http\Middleware\EnsureUserIsSystemAdmin;
use Laravel\Jetstream\Listeners\AddVerifiedUserToDomainTeams;
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

        $this->app->singletonIf(VerifiesDomains::class, VerifyDomainViaDnsOrMeta::class);
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
        $this->configureRateLimiting();
        $this->configureAuditing();

        foreach (['banner' => 'success', 'warningBanner' => 'warning', 'dangerBanner' => 'danger'] as $macro => $style) {
            RedirectResponse::macro($macro, function ($message) use ($style): RedirectResponse {
                /** @var \Illuminate\Http\RedirectResponse $this */
                return $this->with('flash', [
                    'bannerStyle' => $style,
                    'banner' => $message,
                ]);
            });
        }

        if (class_exists(Livewire::class)) {
            Livewire::component('navigation-menu', NavigationMenu::class);
            Livewire::component('profile.update-profile-information-form', UpdateProfileInformationForm::class);
            Livewire::component('profile.update-password-form', UpdatePasswordForm::class);
            Livewire::component('profile.two-factor-authentication-form', TwoFactorAuthenticationForm::class);
            Livewire::component('profile.logout-other-browser-sessions-form', LogoutOtherBrowserSessionsForm::class);
            Livewire::component('profile.delete-user-form', DeleteUserForm::class);

            if (FortifyFeatures::canManagePasskeys()) {
                Livewire::component('profile.passkey-manager', PasskeyManager::class);
            }

            if (Features::hasDataPrivacyFeatures()) {
                Livewire::component('profile.data-privacy-form', DataPrivacyForm::class);
            }

            if (Features::hasAccountRecoveryFeatures()) {
                Livewire::component('profile.update-recovery-channels-form', UpdateRecoveryChannelsForm::class);
            }

            Livewire::component('audit-log-viewer', AuditLogViewer::class);

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
                Livewire::component('admin.user-manager', AdminUserManager::class);
            }

            if (Features::hasCustomerPortalFeatures()) {
                Livewire::component('portal.update-account-name-form', UpdateAccountNameForm::class);
                Livewire::component('portal.account-member-manager', AccountMemberManager::class);
            }

            if (Features::hasDomainAdminFeatures()) {
                Livewire::component('domains.domain-admin-manager', DomainAdminManager::class);
            }
        }

        if (Features::hasDomainAdminFeatures() && Features::hasTeamFeatures()) {
            Event::listen(Verified::class, AddVerifiedUserToDomainTeams::class);
        }
    }

    /**
     * Configure the rate limiters used by Jetstream's routes.
     *
     * System administrators, IP addresses listed in the
     * "jetstream.throttle.bypass_ips" configuration option, and requests
     * approved by the "Jetstream::bypassThrottlingUsing" callback bypass
     * the limits entirely.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('jetstream', function (Request $request) {
            if (Jetstream::bypassesThrottling($request)) {
                return Limit::none();
            }

            $attempts = config('jetstream.throttle.attempts', 60);

            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(is_int($attempts) && $attempts > 0 ? $attempts : 60)
                ->by(is_scalar($userId) ? 'user:'.$userId : 'ip:'.($request->ip() ?? 'unknown'));
        });

        RateLimiter::for('jetstream-guest', function (Request $request) {
            if (Jetstream::bypassesThrottling($request)) {
                return Limit::none();
            }

            $attempts = config('jetstream.throttle.guest_attempts', 6);

            return Limit::perMinute(is_int($attempts) && $attempts > 0 ? $attempts : 6)
                ->by('ip:'.($request->ip() ?? 'unknown'));
        });
    }

    /**
     * Configure audit logging for authentication activity.
     *
     * @return void
     */
    protected function configureAuditing()
    {
        if (config('jetstream.audit.enabled', true) !== true) {
            return;
        }

        Event::subscribe(AuthenticationEventSubscriber::class);
    }

    /**
     * Configure the middleware used to resolve tenant and customer context.
     *
     * @return void
     */
    protected function configureTenancy()
    {
        Route::aliasMiddleware('account.active', EnsureUserIsNotBlocked::class);

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

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/2026_07_03_800000_create_audit_logs_table.php' => database_path('migrations/2026_07_03_800000_create_audit_logs_table.php'),
            __DIR__.'/../database/migrations/2026_07_03_810000_create_data_requests_table.php' => database_path('migrations/2026_07_03_810000_create_data_requests_table.php'),
            __DIR__.'/../database/migrations/2026_07_03_820000_add_soft_delete_columns.php' => database_path('migrations/2026_07_03_820000_add_soft_delete_columns.php'),
            __DIR__.'/../database/migrations/2026_07_03_830000_add_account_recovery_columns.php' => database_path('migrations/2026_07_03_830000_add_account_recovery_columns.php'),
            __DIR__.'/../database/migrations/2026_07_03_840000_add_blocking_and_freezing_columns.php' => database_path('migrations/2026_07_03_840000_add_blocking_and_freezing_columns.php'),
            __DIR__.'/../database/migrations/2026_07_03_850000_add_name_columns.php' => database_path('migrations/2026_07_03_850000_add_name_columns.php'),
            __DIR__.'/../database/migrations/2026_07_03_860000_add_phone_verification_columns.php' => database_path('migrations/2026_07_03_860000_add_phone_verification_columns.php'),
        ], 'jetstream-compliance-migrations');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/2026_07_07_100000_create_domain_claims_table.php' => database_path('migrations/2026_07_07_100000_create_domain_claims_table.php'),
            __DIR__.'/../database/migrations/2026_07_07_200000_create_domain_activities_table.php' => database_path('migrations/2026_07_07_200000_create_domain_activities_table.php'),
        ], 'jetstream-domain-migrations');

        $this->publishes([
            __DIR__.'/../routes/livewire.php' => base_path('routes/jetstream.php'),
        ], 'jetstream-routes');
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
                $this->loadRoutesFrom(__DIR__.'/../routes/livewire.php');
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
            Console\CreateUserCommand::class,
            Console\InstallCommand::class,
            Console\PurgeCommand::class,
        ]);
    }
}
