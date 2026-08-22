<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

#[AsCommand(name: 'jetstream:install')]
class InstallCommand extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jetstream:install {stack : The development stack that should be installed (livewire)}
                                              {--dark : Indicate that dark mode support should be installed}
                                              {--api : Indicates if API support should be installed}
                                              {--verification : Indicates if email verification support should be installed}
                                              {--pest : Indicates if Pest should be installed}
                                              {--composer=global : Absolute path to the Composer binary which should be used to install packages}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the Jetstream components and resources';

    /**
     * The Laravel migration this package publishes its own version over.
     *
     * @var string
     */
    public const USERS_MIGRATION = '0001_01_01_000000_create_users_table';

    /**
     * The tables that migration creates, in the order it creates them.
     *
     * @var list<string>
     */
    public const USERS_MIGRATION_TABLES = ['users', 'password_reset_tokens', 'sessions'];

    /**
     * Indicates that migrations were skipped because the schema is incompatible.
     *
     * @var bool
     */
    protected $migrationsWereBlocked = false;

    /**
     * Indicates that the migrations ran and the child process reported failure.
     *
     * @var bool
     */
    protected $migrationsFailed = false;

    /**
     * Execute the console command.
     *
     * @return int|null
     */
    public function handle()
    {
        if ($this->argument('stack') !== 'livewire') {
            $this->components->error('Invalid stack. The only supported stack is [livewire].');

            return 1;
        }

        // Migrations first, and then the consistency check, before anything
        // in the application has been touched. The check tells the operator to
        // rebuild with "migrate:fresh", which needs every migration on disk to
        // build the right schema — but nothing else here needs to have run, and
        // refusing after the application had been rewritten would leave it half
        // installed.
        $this->publishMigrations();

        if (! $this->ensureUsersMigrationIsConsistent()) {
            return 1;
        }

        // Publish...
        $this->callSilent('vendor:publish', ['--tag' => 'jetstream-config', '--force' => true]);

        $this->callSilent('vendor:publish', ['--tag' => 'fortify-config', '--force' => true]);
        $this->callSilent('vendor:publish', ['--tag' => 'fortify-support', '--force' => true]);

        // Storage...
        $this->callSilent('storage:link');

        $this->replaceInFile('/home', '/dashboard', config_path('fortify.php'));

        if (file_exists(resource_path('views/welcome.blade.php'))) {
            $this->replaceInFile('/home', '/dashboard', resource_path('views/welcome.blade.php'));
            $this->replaceInFile('Home', 'Dashboard', resource_path('views/welcome.blade.php'));
        }

        // Fortify Provider...
        ServiceProvider::addProviderToBootstrapFile('App\Providers\FortifyServiceProvider');

        // Configure Session...
        $this->configureSession();

        // Configure API...
        if ($this->option('api')) {
            $this->replaceInFile('// Features::api(),', 'Features::api(),', config_path('jetstream.php'));
        }

        // Configure Email Verification...
        if ($this->option('verification')) {
            $this->replaceInFile('// Features::emailVerification(),', 'Features::emailVerification(),', config_path('fortify.php'));
        }

        // Install Stack...
        if (! $this->installLivewireStack()) {
            return 1;
        }

        // Emails...
        (new Filesystem)->ensureDirectoryExists(resource_path('views/emails'));
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/resources/views/emails', resource_path('views/emails'));

        // Tests...
        $stubs = $this->getTestStubsPath();

        if ($this->option('pest') || $this->isUsingPest()) {
            if ($this->hasComposerPackage('phpunit/phpunit')) {
                $this->removeComposerDevPackages(['phpunit/phpunit']);
            }

            if (! $this->requireComposerDevPackages(['pestphp/pest', 'pestphp/pest-plugin-laravel'])) {
                return 1;
            }

            copy($stubs.'/Pest.php', base_path('tests/Pest.php'));
            copy($stubs.'/ExampleTest.php', base_path('tests/Feature/ExampleTest.php'));
            copy($stubs.'/ExampleUnitTest.php', base_path('tests/Unit/ExampleTest.php'));
        }

        copy($stubs.'/AuthenticationTest.php', base_path('tests/Feature/AuthenticationTest.php'));
        copy($stubs.'/EmailVerificationTest.php', base_path('tests/Feature/EmailVerificationTest.php'));
        copy($stubs.'/PasswordConfirmationTest.php', base_path('tests/Feature/PasswordConfirmationTest.php'));
        copy($stubs.'/PasswordResetTest.php', base_path('tests/Feature/PasswordResetTest.php'));
        copy($stubs.'/RegistrationTest.php', base_path('tests/Feature/RegistrationTest.php'));

        return $this->exitStatus();
    }

    /**
     * Publish every migration this package contributes to the application.
     *
     * @return void
     */
    protected function publishMigrations()
    {
        // Jetstream's own tags publish to fixed file names, so re-publishing
        // simply overwrites them.
        foreach ([
            'jetstream-migrations',
            'jetstream-team-migrations',
            'jetstream-tenant-migrations',
            'jetstream-compliance-migrations',
            'jetstream-domain-migrations',
        ] as $tag) {
            $this->callSilent('vendor:publish', ['--tag' => $tag, '--force' => true]);
        }

        $this->publishFortifyMigrations();
    }

    /**
     * Publish Fortify's migrations without duplicating any already present.
     *
     * Fortify's tag stamps every file it publishes with the current date-time
     * rather than overwriting, so publishing it twice leaves two copies of a
     * migration and the duplicate fails the very next "php artisan migrate".
     * The tag covers more than one migration — its own, and the passkeys table
     * it integrates — so no single file can stand in for the group: an
     * application that used Fortify before passkeys were part of it has one
     * but not the other, and either skipping or publishing wholesale gets that
     * case wrong.
     *
     * So the group is published, and any file it just wrote for a migration
     * that was already present is removed again. What is left is each
     * migration exactly once, whichever of them existed beforehand.
     *
     * @return void
     */
    protected function publishFortifyMigrations()
    {
        $before = $this->publishedMigrations();

        $this->callSilent('vendor:publish', ['--tag' => 'fortify-migrations', '--force' => true]);

        foreach ($this->publishedMigrations() as $migration => $paths) {
            if (! isset($before[$migration])) {
                continue;
            }

            foreach (array_diff($paths, $before[$migration]) as $duplicate) {
                (new Filesystem)->delete($duplicate);
            }
        }
    }

    /**
     * Get the published migration files, grouped by name without their stamp.
     *
     * @return array<string, list<string>>
     */
    protected function publishedMigrations()
    {
        $migrations = [];

        foreach ((array) glob(database_path('migrations/*.php')) as $path) {
            if (! is_string($path)) {
                continue;
            }

            $name = (string) preg_replace(
                '/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php')
            );

            $migrations[$name][] = $path;
        }

        return $migrations;
    }

    /**
     * Configure the session driver for Jetstream.
     *
     * @return void
     */
    protected function configureSession()
    {
        $this->replaceInFile('SESSION_DRIVER=cookie', 'SESSION_DRIVER=database', base_path('.env'));
        $this->replaceInFile('SESSION_DRIVER=cookie', 'SESSION_DRIVER=database', base_path('.env.example'));
    }

    /**
     * Install the Livewire stack into the application.
     *
     * @return bool
     */
    protected function installLivewireStack()
    {
        // Install Livewire...
        if (! $this->requireComposerPackages('livewire/livewire:^3.6.4')) {
            return false;
        }

        $this->call('install:api', [
            '--without-migration-prompt' => true,
        ]);

        // NPM Packages...
        $this->updateNodePackages(function ($packages) {
            return [
                '@laravel/passkeys' => '^0.2.0',
                '@tailwindcss/forms' => '^0.5.7',
                '@tailwindcss/typography' => '^0.5.10',
                'autoprefixer' => '^10.4.16',
                'postcss' => '^8.4.32',
                'tailwindcss' => '^3.4.0',
            ] + $packages;
        });

        // Tailwind Configuration...
        copy(__DIR__.'/../../stubs/livewire/tailwind.config.js', base_path('tailwind.config.js'));
        copy(__DIR__.'/../../stubs/livewire/postcss.config.js', base_path('postcss.config.js'));
        copy(__DIR__.'/../../stubs/livewire/vite.config.js', base_path('vite.config.js'));

        // Directories...
        (new Filesystem)->ensureDirectoryExists(app_path('Actions/Fortify'));
        (new Filesystem)->ensureDirectoryExists(app_path('Actions/Jetstream'));
        (new Filesystem)->ensureDirectoryExists(app_path('View/Components'));
        (new Filesystem)->ensureDirectoryExists(resource_path('css'));
        (new Filesystem)->ensureDirectoryExists(resource_path('markdown'));
        (new Filesystem)->ensureDirectoryExists(resource_path('views/api'));
        (new Filesystem)->ensureDirectoryExists(resource_path('views/auth'));
        (new Filesystem)->ensureDirectoryExists(resource_path('views/components'));
        (new Filesystem)->ensureDirectoryExists(resource_path('views/layouts'));
        (new Filesystem)->ensureDirectoryExists(resource_path('views/profile'));

        (new Filesystem)->deleteDirectory(resource_path('sass'));

        // Terms Of Service / Privacy Policy...
        copy(__DIR__.'/../../stubs/resources/markdown/terms.md', resource_path('markdown/terms.md'));
        copy(__DIR__.'/../../stubs/resources/markdown/policy.md', resource_path('markdown/policy.md'));

        // Service Providers...
        copy(__DIR__.'/../../stubs/app/Providers/JetstreamServiceProvider.php', $provider = app_path('Providers/JetstreamServiceProvider.php'));

        $this->replaceInFile([
            PHP_EOL.'use Illuminate\Support\Facades\Vite;',
            PHP_EOL.PHP_EOL.'        Vite::prefetch(concurrency: 3);',
        ], '', $provider);

        ServiceProvider::addProviderToBootstrapFile('App\Providers\JetstreamServiceProvider');

        // Models...
        copy(__DIR__.'/../../stubs/app/Models/User.php', app_path('Models/User.php'));

        // Factories...
        copy(__DIR__.'/../../database/factories/UserFactory.php', base_path('database/factories/UserFactory.php'));

        // Actions...
        copy(__DIR__.'/../../stubs/app/Actions/Fortify/CreateNewUser.php', app_path('Actions/Fortify/CreateNewUser.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Fortify/UpdateUserProfileInformation.php', app_path('Actions/Fortify/UpdateUserProfileInformation.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/DeleteUser.php', app_path('Actions/Jetstream/DeleteUser.php'));

        // Components...
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/components', resource_path('views/components'));

        // View Components...
        copy(__DIR__.'/../../stubs/livewire/app/View/Components/AppLayout.php', app_path('View/Components/AppLayout.php'));
        copy(__DIR__.'/../../stubs/livewire/app/View/Components/GuestLayout.php', app_path('View/Components/GuestLayout.php'));

        // Layouts...
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/layouts', resource_path('views/layouts'));

        // Single Blade Views...
        copy(__DIR__.'/../../stubs/livewire/resources/views/dashboard.blade.php', resource_path('views/dashboard.blade.php'));
        copy(__DIR__.'/../../stubs/livewire/resources/views/navigation-menu.blade.php', resource_path('views/navigation-menu.blade.php'));
        copy(__DIR__.'/../../stubs/livewire/resources/views/terms.blade.php', resource_path('views/terms.blade.php'));
        copy(__DIR__.'/../../stubs/livewire/resources/views/policy.blade.php', resource_path('views/policy.blade.php'));

        // Other Views...
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/api', resource_path('views/api'));
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/profile', resource_path('views/profile'));
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/auth', resource_path('views/auth'));
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/help', resource_path('views/help'));

        if (! Str::contains((string) file_get_contents(base_path('routes/web.php')), "'/dashboard'")) {
            (new Filesystem)->append(base_path('routes/web.php'), $this->livewireRouteDefinition());
        }

        // Assets...
        copy(__DIR__.'/../../stubs/resources/css/app.css', resource_path('css/app.css'));

        if (file_exists(resource_path('js/app.js')) &&
            ! str_contains((string) file_get_contents(resource_path('js/app.js')), '@laravel/passkeys')) {
            (new Filesystem)->append(resource_path('js/app.js'), $this->passkeysScript());
        }

        // Tests...
        $stubs = $this->getTestStubsPath();

        copy($stubs.'/livewire/ApiTokenPermissionsTest.php', base_path('tests/Feature/ApiTokenPermissionsTest.php'));
        copy($stubs.'/livewire/BrowserSessionsTest.php', base_path('tests/Feature/BrowserSessionsTest.php'));
        copy($stubs.'/livewire/CreateApiTokenTest.php', base_path('tests/Feature/CreateApiTokenTest.php'));
        copy($stubs.'/livewire/DeleteAccountTest.php', base_path('tests/Feature/DeleteAccountTest.php'));
        copy($stubs.'/livewire/DeleteApiTokenTest.php', base_path('tests/Feature/DeleteApiTokenTest.php'));
        copy($stubs.'/livewire/ProfileInformationTest.php', base_path('tests/Feature/ProfileInformationTest.php'));
        copy($stubs.'/livewire/TwoFactorAuthenticationSettingsTest.php', base_path('tests/Feature/TwoFactorAuthenticationSettingsTest.php'));
        copy($stubs.'/livewire/UpdatePasswordTest.php', base_path('tests/Feature/UpdatePasswordTest.php'));
        copy($stubs.'/livewire/PasskeyManagementTest.php', base_path('tests/Feature/PasskeyManagementTest.php'));

        // Teams & SaaS...
        $this->installLivewireTeamStack();
        $this->installLivewireSaasStack();

        if (! $this->option('dark')) {
            $this->removeDarkClasses((new Finder)
                ->in(resource_path('views'))
                ->name('*.blade.php')
                ->filter(fn ($file) => $file->getPathname() !== resource_path('views/welcome.blade.php'))
            );
        }

        if (file_exists(base_path('pnpm-lock.yaml'))) {
            $this->runCommands(['pnpm install', 'pnpm run build']);
        } elseif (file_exists(base_path('yarn.lock'))) {
            $this->runCommands(['yarn install', 'yarn run build']);
        } elseif (file_exists(base_path('bun.lockb'))) {
            $this->runCommands(['bun install', 'bun run build']);
        } else {
            $this->runCommands(['npm install', 'npm run build']);
        }

        $this->finishInstallation();

        return true;
    }

    /**
     * Install the Livewire team stack into the application.
     *
     * @return void
     */
    protected function installLivewireTeamStack()
    {
        // Directories...
        (new Filesystem)->ensureDirectoryExists(resource_path('views/teams'));

        // Other Views...
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/teams', resource_path('views/teams'));

        // Tests...
        $stubs = $this->getTestStubsPath();

        copy($stubs.'/livewire/CreateTeamTest.php', base_path('tests/Feature/CreateTeamTest.php'));
        copy($stubs.'/livewire/DeleteTeamTest.php', base_path('tests/Feature/DeleteTeamTest.php'));
        copy($stubs.'/livewire/InviteTeamMemberTest.php', base_path('tests/Feature/InviteTeamMemberTest.php'));
        copy($stubs.'/livewire/LeaveTeamTest.php', base_path('tests/Feature/LeaveTeamTest.php'));
        copy($stubs.'/livewire/RemoveTeamMemberTest.php', base_path('tests/Feature/RemoveTeamMemberTest.php'));
        copy($stubs.'/livewire/UpdateTeamMemberRoleTest.php', base_path('tests/Feature/UpdateTeamMemberRoleTest.php'));
        copy($stubs.'/livewire/UpdateTeamNameTest.php', base_path('tests/Feature/UpdateTeamNameTest.php'));

        // Configuration...
        $this->replaceInFile('// Features::teams([\'invitations\' => true])', 'Features::teams([\'invitations\' => true])', config_path('jetstream.php'));

        // Directories...
        (new Filesystem)->ensureDirectoryExists(app_path('Actions/Jetstream'));
        (new Filesystem)->ensureDirectoryExists(app_path('Events'));
        (new Filesystem)->ensureDirectoryExists(app_path('Policies'));

        // Models...
        copy(__DIR__.'/../../stubs/app/Models/Membership.php', app_path('Models/Membership.php'));
        copy(__DIR__.'/../../stubs/app/Models/TeamInvitation.php', app_path('Models/TeamInvitation.php'));

        // Actions...
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/AddTeamMember.php', app_path('Actions/Jetstream/AddTeamMember.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/CreateTeam.php', app_path('Actions/Jetstream/CreateTeam.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/DeleteTeam.php', app_path('Actions/Jetstream/DeleteTeam.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/InviteTeamMember.php', app_path('Actions/Jetstream/InviteTeamMember.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/RemoveTeamMember.php', app_path('Actions/Jetstream/RemoveTeamMember.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/UpdateTeamName.php', app_path('Actions/Jetstream/UpdateTeamName.php'));

        // Factories...
        copy(__DIR__.'/../../database/factories/UserFactory.php', base_path('database/factories/UserFactory.php'));
        copy(__DIR__.'/../../database/factories/TeamFactory.php', base_path('database/factories/TeamFactory.php'));
    }

    /**
     * Install the Livewire SaaS stack into the application.
     *
     * @return void
     */
    protected function installLivewireSaasStack()
    {
        // Directories...
        (new Filesystem)->ensureDirectoryExists(resource_path('views/tenants'));
        (new Filesystem)->ensureDirectoryExists(resource_path('views/customers'));
        (new Filesystem)->ensureDirectoryExists(resource_path('views/portal'));
        (new Filesystem)->ensureDirectoryExists(resource_path('views/admin'));
        (new Filesystem)->ensureDirectoryExists(resource_path('views/audit'));

        // Other Views...
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/tenants', resource_path('views/tenants'));
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/customers', resource_path('views/customers'));
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/portal', resource_path('views/portal'));
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/admin', resource_path('views/admin'));
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/livewire/resources/views/audit', resource_path('views/audit'));

        // Tests...
        $stubs = $this->getTestStubsPath();

        copy($stubs.'/livewire/CreateTenantTest.php', base_path('tests/Feature/CreateTenantTest.php'));
        copy($stubs.'/livewire/TenantStaffTest.php', base_path('tests/Feature/TenantStaffTest.php'));
        copy($stubs.'/livewire/RoleManagementTest.php', base_path('tests/Feature/RoleManagementTest.php'));
        copy($stubs.'/livewire/CustomerAccountManagementTest.php', base_path('tests/Feature/CustomerAccountManagementTest.php'));
        copy($stubs.'/livewire/PortalTest.php', base_path('tests/Feature/PortalTest.php'));

        $this->ensureApplicationIsSaasCompatible();
    }

    /**
     * Ensure the installed application is ready for multi-tenant SaaS usage.
     *
     * This runs after the team installation steps and overwrites the user
     * model and service provider with their SaaS variants.
     *
     * @return void
     */
    protected function ensureApplicationIsSaasCompatible()
    {
        // Configuration...
        $this->replaceInFile('// Features::tenants([\'portal\' => true, \'customer-registration\' => true])', 'Features::tenants([\'portal\' => true, \'customer-registration\' => true])', config_path('jetstream.php'));

        // Service Providers...
        copy(__DIR__.'/../../stubs/app/Providers/JetstreamServiceProvider.php', app_path('Providers/JetstreamServiceProvider.php'));

        // Models...
        copy(__DIR__.'/../../stubs/app/Models/Team.php', app_path('Models/Team.php'));
        copy(__DIR__.'/../../stubs/app/Models/Tenant.php', app_path('Models/Tenant.php'));
        copy(__DIR__.'/../../stubs/app/Models/TenantMembership.php', app_path('Models/TenantMembership.php'));
        copy(__DIR__.'/../../stubs/app/Models/Role.php', app_path('Models/Role.php'));
        copy(__DIR__.'/../../stubs/app/Models/CustomerAccount.php', app_path('Models/CustomerAccount.php'));
        copy(__DIR__.'/../../stubs/app/Models/CustomerInvitation.php', app_path('Models/CustomerInvitation.php'));
        copy(__DIR__.'/../../stubs/app/Models/AuditLog.php', app_path('Models/AuditLog.php'));
        copy(__DIR__.'/../../stubs/app/Models/DataRequest.php', app_path('Models/DataRequest.php'));
        copy(__DIR__.'/../../stubs/app/Models/DomainClaim.php', app_path('Models/DomainClaim.php'));
        copy(__DIR__.'/../../stubs/app/Models/DomainActivity.php', app_path('Models/DomainActivity.php'));
        copy(__DIR__.'/../../stubs/app/Models/User.php', app_path('Models/User.php'));

        // Actions...
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/CreateTenant.php', app_path('Actions/Jetstream/CreateTenant.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/UpdateTenantName.php', app_path('Actions/Jetstream/UpdateTenantName.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/AddTenantStaff.php', app_path('Actions/Jetstream/AddTenantStaff.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/RemoveTenantStaff.php', app_path('Actions/Jetstream/RemoveTenantStaff.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/DeleteTenant.php', app_path('Actions/Jetstream/DeleteTenant.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/CreateCustomerAccount.php', app_path('Actions/Jetstream/CreateCustomerAccount.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/InviteCustomer.php', app_path('Actions/Jetstream/InviteCustomer.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/RemoveCustomerAccountMember.php', app_path('Actions/Jetstream/RemoveCustomerAccountMember.php'));
        copy(__DIR__.'/../../stubs/app/Actions/Jetstream/DeleteCustomerAccount.php', app_path('Actions/Jetstream/DeleteCustomerAccount.php'));

        // Policies...
        (new Filesystem)->copyDirectory(__DIR__.'/../../stubs/app/Policies', app_path('Policies'));

        // Factories...
        copy(__DIR__.'/../../database/factories/TenantFactory.php', base_path('database/factories/TenantFactory.php'));
        copy(__DIR__.'/../../database/factories/CustomerAccountFactory.php', base_path('database/factories/CustomerAccountFactory.php'));

        // Seeders...
        copy(__DIR__.'/../../database/seeders/DatabaseSeeder.php', base_path('database/seeders/DatabaseSeeder.php'));
        copy(__DIR__.'/../../database/seeders/DefaultRolesSeeder.php', base_path('database/seeders/DefaultRolesSeeder.php'));
        copy(__DIR__.'/../../database/seeders/SystemAdminSeeder.php', base_path('database/seeders/SystemAdminSeeder.php'));
    }

    /**
     * Get the passkeys client bootstrapping that should be appended to app.js.
     *
     * @return string
     */
    protected function passkeysScript()
    {
        return <<<'EOT'

import { Passkeys } from '@laravel/passkeys';

window.Passkeys = Passkeys;

EOT;
    }

    /**
     * Get the route definition(s) that should be installed for Livewire.
     *
     * @return string
     */
    protected function livewireRouteDefinition()
    {
        return <<<'EOF'

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});

EOF;
    }

    /**
     * Returns the path to the correct test stubs.
     *
     * @return string
     */
    protected function getTestStubsPath()
    {
        return $this->option('pest') || $this->isUsingPest()
            ? __DIR__.'/../../stubs/pest-tests'
            : __DIR__.'/../../stubs/tests';
    }

    /**
     * Determine if the given Composer package is installed.
     *
     * @param  string  $package
     * @return bool
     */
    protected function hasComposerPackage($package)
    {
        $packages = json_decode((string) file_get_contents(base_path('composer.json')), true);

        if (! is_array($packages)) {
            return false;
        }

        $require = $packages['require'] ?? [];
        $requireDev = $packages['require-dev'] ?? [];

        return (is_array($require) && array_key_exists($package, $require))
            || (is_array($requireDev) && array_key_exists($package, $requireDev));
    }

    /**
     * Installs the given Composer Packages into the application.
     *
     * @param  mixed  $packages
     * @return bool
     */
    protected function requireComposerPackages($packages)
    {
        $composer = $this->option('composer');

        $command = is_string($composer) && $composer !== 'global'
            ? [$this->phpBinary(), $composer, 'require']
            : ['composer', 'require'];

        $command = array_merge(
            $command,
            array_values(array_filter(is_array($packages) ? $packages : func_get_args(), 'is_string'))
        );

        return (new Process($command, base_path(), ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->run(function ($type, $output) {
                $this->output->write($output);
            }) === 0;
    }

    /**
     * Removes the given Composer Packages as "dev" dependencies.
     *
     * @param  mixed  $packages
     * @return bool
     */
    protected function removeComposerDevPackages($packages)
    {
        $composer = $this->option('composer');

        $command = is_string($composer) && $composer !== 'global'
            ? [$this->phpBinary(), $composer, 'remove', '--dev']
            : ['composer', 'remove', '--dev'];

        $command = array_merge(
            $command,
            array_values(array_filter(is_array($packages) ? $packages : func_get_args(), 'is_string'))
        );

        return (new Process($command, base_path(), ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->run(function ($type, $output) {
                $this->output->write($output);
            }) === 0;
    }

    /**
     * Install the given Composer Packages as "dev" dependencies.
     *
     * @param  mixed  $packages
     * @return bool
     */
    protected function requireComposerDevPackages($packages)
    {
        $composer = $this->option('composer');

        $command = is_string($composer) && $composer !== 'global'
            ? [$this->phpBinary(), $composer, 'require', '--dev']
            : ['composer', 'require', '--dev'];

        $command = array_merge(
            $command,
            array_values(array_filter(is_array($packages) ? $packages : func_get_args(), 'is_string'))
        );

        return (new Process($command, base_path(), ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->run(function ($type, $output) {
                $this->output->write($output);
            }) === 0;
    }

    /**
     * Update the "package.json" file.
     *
     * @param  callable(array<string, string>, string): array<string, string>  $callback
     * @param  bool  $dev
     * @return void
     */
    protected static function updateNodePackages(callable $callback, $dev = true)
    {
        if (! file_exists(base_path('package.json'))) {
            return;
        }

        $configurationKey = $dev ? 'devDependencies' : 'dependencies';

        $packages = json_decode((string) file_get_contents(base_path('package.json')), true);

        if (! is_array($packages)) {
            return;
        }

        $current = $packages[$configurationKey] ?? [];

        $updated = $callback(
            is_array($current) ? self::stringMap($current) : [],
            $configurationKey
        );

        ksort($updated);

        $packages[$configurationKey] = $updated;

        file_put_contents(
            base_path('package.json'),
            json_encode($packages, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL
        );
    }

    /**
     * Reduce the given array to its string keys and string values.
     *
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    protected static function stringMap(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Run the database migrations.
     *
     * @return void
     */
    protected function runDatabaseMigrations()
    {
        if (! $this->ensureUsersMigrationIsConsistent()) {
            return;
        }

        if (! confirm('New database migrations were added. Would you like to run your migrations?', true)) {
            return;
        }

        // The child's own output has already been written through, so the
        // reason for the failure is on screen; what is added here is that it
        // was a failure at all, which the exit status is the only record of.
        $status = (new Process([$this->phpBinary(), 'artisan', 'migrate', '--force'], base_path()))
            ->setTimeout(null)
            ->run(function ($type, $output) {
                $this->output->write($output);
            });

        if ($status !== 0) {
            $this->migrationsFailed = true;

            $this->components->error(sprintf('The database migrations failed with exit status %d.', $status));

            $this->components->warn(
                'The output above is "artisan migrate --force" reporting why. The database may now be partially '
                .'migrated. Jetstream has not attempted repair, rollback, or retry.'
            );
        }
    }

    /**
     * The status this installation should exit with.
     *
     * A migration that was refused and a migration that ran and failed are
     * different problems with the same consequence: the application has been
     * scaffolded against a database that is not the one it expects. Neither
     * may be reported as a successful install.
     *
     * @return int
     */
    protected function exitStatus()
    {
        return $this->migrationsWereBlocked || $this->migrationsFailed ? 1 : 0;
    }

    /**
     * Run the migrations and report how the installation actually ended.
     *
     * @return void
     */
    protected function finishInstallation()
    {
        $this->line('');
        $this->runDatabaseMigrations();

        if ($this->migrationsWereBlocked) {
            $this->components->warn('Livewire scaffolding was installed, but the database was not migrated. See above.');
        } elseif ($this->migrationsFailed) {
            $this->components->warn('Livewire scaffolding was installed, but the migrations did not complete. See above.');
        } else {
            $this->components->info('Livewire scaffolding installed successfully.');
        }
    }

    /**
     * Make sure Jetstream's replacement users migration leaves a usable schema.
     *
     * Jetstream publishes its own users migration over Laravel's, under the
     * same file name, and that one migration creates three tables: users,
     * password_reset_tokens and sessions. Laravel tracks migrations by name,
     * so whether the published file will run at all depends on the ledger, not
     * on the schema — and the two can disagree in both directions:
     *
     * The name is already recorded — "laravel new" and "composer
     * create-project" both offer to migrate — so the replacement never runs.
     * Whatever Laravel's own migration created stays: an auto-incrementing
     * users.id, an integer sessions.user_id, no current_team_id. Nothing fails
     * until something reads a column that was never added, usually the seeder,
     * and sessions is worse than that because the installer sets
     * SESSION_DRIVER=database, so every request writes to a table whose
     * user_id cannot hold the key it is given.
     *
     * Or the name is not recorded and the tables exist anyway, in which case
     * the migration does run and "Schema::create" fails on the first one.
     *
     * A database that cannot be inspected at all is refused the same way. It
     * is not a database that has been shown to be clean, and this command runs
     * "artisan migrate" itself, so proceeding would mean migrating blind.
     *
     * The database is never repaired here. Rebuilding it drops every table,
     * and an application can hold data this command knows nothing about, so
     * the operator is told what to run and installation stops.
     *
     * @return bool  whether installation should continue
     */
    protected function ensureUsersMigrationIsConsistent()
    {
        try {
            $recorded = $this->usersMigrationWasRecorded();

            $tables = [];

            foreach (static::USERS_MIGRATION_TABLES as $table) {
                $tables[$table] = Schema::hasTable($table) ? Schema::getColumns($table) : null;
            }

            $mismatch = static::usersMigrationMismatch(
                $recorded, $tables, Schema::getConnection()->getDriverName()
            );
        } catch (Throwable $e) {
            // A database that cannot be inspected is not a database that has
            // been cleared. Connection failures, a repository an application
            // has replaced, permissions, unreadable table metadata — none of
            // them say the replacement migration will apply, and the installer
            // goes on to run "artisan migrate" itself, so carrying on means
            // migrating blind. Uncertainty is refused like a mismatch.
            $this->migrationsWereBlocked = true;

            $this->components->error(
                'Jetstream could not verify the database schema: '.$e->getMessage()
            );

            $this->components->warn(
                'This command migrates the database itself, so it needs one it can read. Nothing has been installed '
                .'or migrated. Fix the connection and run this command again.'
            );

            return false;
        }

        if ($mismatch === null) {
            return true;
        }

        $this->migrationsWereBlocked = true;

        $this->components->error(sprintf(
            'This database is not one Jetstream can install onto: %s.', $mismatch
        ));

        $this->components->warn($recorded
            ? sprintf(
                'Laravel has already recorded the [%s] migration, so the one Jetstream publishes in its place will '
                .'never run and the mismatch would survive.',
                static::USERS_MIGRATION
            )
            : sprintf(
                'Laravel has no record of the [%s] migration, so it will run and fail on a table that already exists.',
                static::USERS_MIGRATION
            )
        );

        $this->components->warn(
            'Nothing has been installed or migrated. The migrations have been published, so once you have backed up '
            .'or discarded the data in this database you can rebuild it with "php artisan migrate:fresh --seed" and '
            .'run this command again. That drops every table.'
        );

        return false;
    }

    /**
     * Determine whether Laravel has already recorded the users migration.
     *
     * @return bool
     */
    protected function usersMigrationWasRecorded()
    {
        // The repository, rather than the migrations table directly: the
        // ledger's name is configurable and an application may bind its own.
        $repository = $this->laravel->make('migration.repository');

        return $repository->repositoryExists()
            && in_array(static::USERS_MIGRATION, $repository->getRan(), true);
    }

    /**
     * Describe why the replacement users migration cannot be applied cleanly.
     *
     * Which question to ask depends on whether the migration will run. If it
     * will, the only thing that matters is that its tables are not there yet.
     * If it will not, the schema on disk is final, so it has to already be the
     * schema the migration would have produced — checked positively, against
     * the shape this package writes, rather than by ruling out shapes it does
     * not. Upstream Laravel Jetstream adds current_team_id and
     * profile_photo_path over an auto-incrementing key, so the columns alone
     * prove nothing, and a UUID users.id proves nothing about sessions.
     *
     * @param  bool  $recorded  whether Laravel has recorded the migration
     * @param  array<string, iterable<mixed>|null>  $tables  columns per table, null when the table is absent
     * @param  string  $driver
     * @return string|null  the mismatch, or null when the migration applies cleanly
     */
    public static function usersMigrationMismatch($recorded, array $tables, $driver)
    {
        if (! $recorded) {
            $existing = array_values(array_filter(
                static::USERS_MIGRATION_TABLES,
                fn (string $table) => ($tables[$table] ?? null) !== null
            ));

            if ($existing === []) {
                return null;
            }

            return count($existing) === 1
                ? $existing[0].' already exists without the migration that creates it'
                : static::listNames($existing).' already exist without the migration that creates them';
        }

        foreach (static::USERS_MIGRATION_TABLES as $table) {
            if (($tables[$table] ?? null) === null) {
                return $table.' is missing';
            }
        }

        $columns = [];

        foreach (static::USERS_MIGRATION_TABLES as $table) {
            $columns[$table] = static::columnsByName($tables[$table] ?? []);
        }

        foreach ([
            ['users', 'id', true],
            ['users', 'current_team_id', true],
            ['users', 'profile_photo_path', false],
            ['sessions', 'user_id', true],
        ] as [$table, $column, $mustHoldUuid]) {
            if (! isset($columns[$table][$column])) {
                return $table.'.'.$column.' is missing';
            }

            if ($mustHoldUuid && ! static::keyColumnHoldsUuid($columns[$table][$column], $driver)) {
                return $table.'.'.$column.' does not hold a UUID';
            }
        }

        return null;
    }

    /**
     * Key a driver's column descriptions by column name.
     *
     * @param  iterable<mixed>  $columns
     * @return array<string, array<array-key, mixed>>
     */
    protected static function columnsByName(iterable $columns)
    {
        $found = [];

        foreach ($columns as $column) {
            if (is_array($column) && is_string($column['name'] ?? null)) {
                $found[$column['name']] = $column;
            }
        }

        return $found;
    }

    /**
     * Join the given names into a readable list.
     *
     * @param  list<string>  $names
     * @return string
     */
    protected static function listNames(array $names)
    {
        if (count($names) < 2) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    /**
     * Determine if the given column holds a UUID, as this package writes it.
     *
     * Each database renders "$table->uuid(...)" as its own type, so each is
     * checked against the one it actually produces rather than against the
     * union of all of them — a varchar key is this package's schema on sqlite
     * and is not on PostgreSQL, where a uuid column was what would have been
     * created.
     *
     * Sqlite is the loose one: it records no width, so a UUID and a ULID are
     * indistinguishable there. That is why every column the migration is
     * checked on has to line up rather than resting on the key alone.
     *
     * A driver this package does not know cannot be held to a shape, so there
     * the check falls back to ruling out the auto-incrementing key that
     * Laravel's own migration would have left behind.
     *
     * @param  array<array-key, mixed>  $column
     * @param  string  $driver
     * @return bool
     */
    public static function keyColumnHoldsUuid(array $column, $driver)
    {
        if (($column['auto_increment'] ?? false) === true) {
            return false;
        }

        $name = $column['type_name'] ?? '';
        $type = $column['type'] ?? '';

        if (! is_string($name) || ! is_string($type)) {
            return false;
        }

        $name = (string) preg_replace('/[\s(].*$/', '', strtolower(trim($name)));
        $length = static::declaredLength($type) ?? static::declaredLength($name);

        return match ($driver) {
            'pgsql' => $name === 'uuid',
            'mysql', 'mariadb' => $name === 'char' && ($length === null || $length === 36),
            'sqlite' => $name === 'varchar',
            'sqlsrv' => $name === 'uniqueidentifier',
            default => ! static::isIntegerType($name),
        };
    }

    /**
     * Determine if the given type name is an integer, whatever it is called.
     *
     * @param  string  $type
     * @return bool
     */
    protected static function isIntegerType($type)
    {
        return in_array($type, [
            'int', 'int2', 'int4', 'int8', 'integer', 'bigint', 'mediumint',
            'smallint', 'tinyint', 'serial', 'bigserial', 'serial2', 'serial4', 'serial8',
        ], true);
    }

    /**
     * Read the declared width out of a column type such as "char(36)".
     *
     * @return int|null
     */
    protected static function declaredLength(string $type)
    {
        return preg_match('/\((\d+)\)/', $type, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * Replace a given string within a given file.
     *
     * @param  string  $replace
     * @param  string|array<int, string>  $search
     * @param  string  $path
     * @return void
     */
    protected function replaceInFile($search, $replace, $path)
    {
        file_put_contents($path, str_replace($search, $replace, (string) file_get_contents($path)));
    }

    /**
     * Remove Tailwind dark classes from the given files.
     *
     * @param  \Symfony\Component\Finder\Finder  $finder
     * @return void
     */
    protected function removeDarkClasses(Finder $finder)
    {
        foreach ($finder as $file) {
            file_put_contents($file->getPathname(), preg_replace('/\sdark:[^\s"\']+/', '', $file->getContents()));
        }
    }

    /**
     * Get the path to the appropriate PHP binary.
     *
     * @return string
     */
    protected function phpBinary()
    {
        if (function_exists('Illuminate\Support\php_binary')) {
            return \Illuminate\Support\php_binary();
        }

        $binary = (new PhpExecutableFinder())->find(false);

        return $binary !== false ? $binary : 'php';
    }

    /**
     * Run the given commands.
     *
     * @param  array<int, string>  $commands
     * @return void
     */
    protected function runCommands($commands)
    {
        $process = Process::fromShellCommandline(implode(' && ', $commands), null, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $this->output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
            }
        }

        $process->run(function ($type, $line) {
            $this->output->write('    '.$line);
        });
    }

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array<string, \Closure(): (int|string)>
     */
    protected function promptForMissingArgumentsUsing()
    {
        return [
            'stack' => fn () => select(
                label: 'Which Jetstream stack would you like to install?',
                options: [
                    'livewire' => 'Livewire',
                ]
            ),
        ];
    }

    /**
     * Interact further with the user if they were prompted for missing arguments.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output)
    {
        collect(multiselect(
            label: 'Would you like any optional features?',
            options: collect([
                'api' => 'API support',
                'verification' => 'Email verification',
                'dark' => 'Dark mode',
            ])->sort()->all(),
        ))->each(fn ($option) => $input->setOption((string) $option, true));

        $input->setOption('pest', select(
            label: 'Which testing framework do you prefer?',
            options: ['Pest', 'PHPUnit'],
            default: 'Pest',
        ) === 'Pest');
    }

    /**
     * Determine whether the project is already using Pest.
     *
     * @return bool
     */
    protected function isUsingPest()
    {
        return class_exists(\Pest\TestSuite::class);
    }
}
