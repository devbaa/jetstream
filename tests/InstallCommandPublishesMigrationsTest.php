<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Filesystem\Filesystem;
use Laravel\Jetstream\Console\InstallCommand;
use ReflectionMethod;

/**
 * Fortify's migrations are published exactly once each.
 *
 * Fortify's tag stamps every file it publishes with the current date-time
 * rather than overwriting, and it covers more than one migration: its own
 * two-factor columns, and the passkeys table it integrates. An application
 * can therefore hold either one without the other — one that used Fortify
 * before passkeys were part of it has exactly that — so no single file can
 * stand in for the group.
 */
class InstallCommandPublishesMigrationsTest extends OrchestraTestCase
{
    /**
     * Publish a group of migrations into the application's migration path.
     *
     * Stands in for Fortify's tag: it stamps each file with the current
     * date-time instead of overwriting whatever is already there.
     *
     * @param  list<string>  $names
     */
    protected function publishGroup(array $names, string $stamp): void
    {
        (new Filesystem)->ensureDirectoryExists(database_path('migrations'));

        foreach ($names as $index => $name) {
            file_put_contents(
                database_path('migrations/'.$stamp.str_pad((string) $index, 2, '0', STR_PAD_LEFT).'_'.$name.'.php'),
                '<?php // '.$name
            );
        }
    }

    /**
     * Run the installer's Fortify publishing against a stubbed tag.
     *
     * @param  list<string>  $group
     */
    protected function publishFortifyMigrations(array $group, string $stamp): void
    {
        $command = new class($group, $stamp) extends InstallCommand
        {
            /**
             * @param  list<string>  $group
             */
            public function __construct(protected array $group, protected string $stamp)
            {
                parent::__construct();
            }

            /** {@inheritdoc} */
            #[\Override]
            public function callSilent($command, array $arguments = [])
            {
                foreach ($this->group as $index => $name) {
                    file_put_contents(
                        database_path(
                            'migrations/'.$this->stamp.str_pad((string) $index, 2, '0', STR_PAD_LEFT).'_'.$name.'.php'
                        ),
                        '<?php // '.$name
                    );
                }

                return 0;
            }

            public function runPublish(): void
            {
                $this->publishFortifyMigrations();
            }
        };

        $command->runPublish();
    }

    /**
     * The migration names present, without their date-time stamps.
     *
     * @return array<string, int>
     */
    protected function publishedNames(): array
    {
        $names = [];

        foreach ((array) glob(database_path('migrations/*.php')) as $path) {
            $name = (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename((string) $path, '.php'));

            $names[$name] = ($names[$name] ?? 0) + 1;
        }

        return $names;
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new Filesystem)->deleteDirectory(database_path('migrations'));
        (new Filesystem)->ensureDirectoryExists(database_path('migrations'));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(database_path('migrations'));

        parent::tearDown();
    }

    public function test_a_first_install_publishes_every_migration_in_the_group(): void
    {
        $this->publishFortifyMigrations(
            ['add_two_factor_columns_to_users_table', 'create_passkeys_table'], '2026_01_01_0000'
        );

        $this->assertSame([
            'add_two_factor_columns_to_users_table' => 1,
            'create_passkeys_table' => 1,
        ], $this->publishedNames());
    }

    public function test_installing_again_does_not_duplicate_anything(): void
    {
        $group = ['add_two_factor_columns_to_users_table', 'create_passkeys_table'];

        $this->publishGroup($group, '2026_01_01_0000');
        $this->publishFortifyMigrations($group, '2026_06_06_0000');

        $this->assertSame([
            'add_two_factor_columns_to_users_table' => 1,
            'create_passkeys_table' => 1,
        ], $this->publishedNames());
    }

    public function test_a_missing_passkeys_migration_is_still_published(): void
    {
        // An application that used Fortify before passkeys were part of it.
        // Treating the two-factor migration as a proxy for the group would
        // skip the tag and leave the passkeys table unmigrated forever.
        $this->publishGroup(['add_two_factor_columns_to_users_table'], '2026_01_01_0000');

        $this->publishFortifyMigrations(
            ['add_two_factor_columns_to_users_table', 'create_passkeys_table'], '2026_06_06_0000'
        );

        $this->assertSame([
            'add_two_factor_columns_to_users_table' => 1,
            'create_passkeys_table' => 1,
        ], $this->publishedNames());
    }

    public function test_a_missing_two_factor_migration_is_published_without_duplicating_passkeys(): void
    {
        $this->publishGroup(['create_passkeys_table'], '2026_01_01_0000');

        $this->publishFortifyMigrations(
            ['add_two_factor_columns_to_users_table', 'create_passkeys_table'], '2026_06_06_0000'
        );

        $this->assertSame([
            'create_passkeys_table' => 1,
            'add_two_factor_columns_to_users_table' => 1,
        ], $this->publishedNames());
    }

    public function test_migrations_published_by_other_tags_are_left_alone(): void
    {
        $this->publishGroup(['create_tenants_table', 'create_roles_table'], '2026_01_01_0000');

        $this->publishFortifyMigrations(['add_two_factor_columns_to_users_table'], '2026_06_06_0000');

        $this->assertSame([
            'create_tenants_table' => 1,
            'create_roles_table' => 1,
            'add_two_factor_columns_to_users_table' => 1,
        ], $this->publishedNames());
    }

    public function test_the_publishing_helper_is_the_one_the_installer_uses(): void
    {
        // The stub above overrides callSilent, so this pins that the method
        // under test is the installer's own rather than a copy of it.
        $this->assertTrue((new ReflectionMethod(InstallCommand::class, 'publishFortifyMigrations'))->isProtected());
    }
}
