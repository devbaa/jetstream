<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Console\InstallCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * The installer refuses to install onto a users table it did not create.
 *
 * Jetstream publishes its users table migration over Laravel's, under the
 * same file name. Laravel never re-runs a migration it has already recorded,
 * so an application that migrated first keeps whatever shape its table
 * already had while everything referencing it is built for Jetstream's.
 */
class InstallCommandSchemaGuardTest extends OrchestraTestCase
{
    /**
     * Build the column description a driver reports for a users table.
     *
     * @param  array<string, mixed>  $id
     * @param  list<string>  $others
     * @return list<array<string, mixed>>
     */
    protected static function table(array $id, array $others = ['current_team_id', 'profile_photo_path']): array
    {
        $columns = [['name' => 'id'] + $id];

        foreach ($others as $name) {
            $columns[] = ['name' => $name, 'type_name' => 'varchar', 'type' => 'varchar', 'auto_increment' => false];
        }

        return $columns;
    }

    /**
     * @return array<string, array{list<array<string, mixed>>, bool}>
     */
    public static function usersTableProvider(): array
    {
        $uuid = ['type_name' => 'uuid', 'type' => 'uuid', 'auto_increment' => false];
        $char36 = ['type_name' => 'char', 'type' => 'char(36)', 'auto_increment' => false];
        $varchar = ['type_name' => 'varchar', 'type' => 'varchar', 'auto_increment' => false];
        $bigint = ['type_name' => 'bigint', 'type' => 'bigint(20) unsigned', 'auto_increment' => true];

        return [
            // What this package's migration actually produces, per driver.
            'postgres uuid key' => [self::table($uuid), true],
            'mysql char(36) key' => [self::table($char36), true],
            'sqlite varchar key' => [self::table($varchar), true],

            // An integer key means Laravel's migration built the table.
            'laravel stock table' => [self::table($bigint, []), false],
            'integer key without auto increment' => [
                self::table(['type_name' => 'bigint', 'type' => 'bigint', 'auto_increment' => false]),
                false,
            ],

            // Upstream Laravel Jetstream adds both columns over an
            // auto-incrementing key, so the columns alone prove nothing.
            'upstream jetstream table' => [self::table($bigint), false],

            // The same skipped migration by another route: the key happens to
            // be compatible, but Jetstream's columns were never added.
            'uuid key without jetstream columns' => [self::table($uuid, []), false],
            'uuid key missing one jetstream column' => [self::table($uuid, ['current_team_id']), false],

            // Other identifier schemes are not this package's schema.
            'mysql ulid key' => [
                self::table(['type_name' => 'char', 'type' => 'char(26)', 'auto_increment' => false]),
                false,
            ],
            'binary key' => [
                self::table(['type_name' => 'binary', 'type' => 'binary(16)', 'auto_increment' => false]),
                false,
            ],

            'no id column at all' => [[['name' => 'name', 'type_name' => 'varchar']], false],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     */
    #[DataProvider('usersTableProvider')]
    public function test_only_the_table_this_package_creates_is_accepted(array $columns, bool $compatible): void
    {
        $mismatch = InstallCommand::usersTableMismatch($columns);

        $this->assertSame($compatible, $mismatch === null, (string) $mismatch);
    }

    public function test_the_mismatch_says_which_half_of_the_contract_failed(): void
    {
        $this->assertStringContainsString('does not hold a UUID', (string) InstallCommand::usersTableMismatch(
            self::table(['type_name' => 'bigint', 'type' => 'bigint(20) unsigned', 'auto_increment' => true])
        ));

        $this->assertStringContainsString('current_team_id', (string) InstallCommand::usersTableMismatch(
            self::table(['type_name' => 'uuid', 'type' => 'uuid', 'auto_increment' => false], [])
        ));

        $this->assertStringContainsString('no id column', (string) InstallCommand::usersTableMismatch(
            [['name' => 'email', 'type_name' => 'varchar']]
        ));
    }

    public function test_the_schema_this_package_migrates_is_accepted(): void
    {
        // Checked against a real users table built by this package's own
        // migrations, rather than a hand-written description of one.
        $this->assertNull(InstallCommand::usersTableMismatch(Schema::getColumns('users')));
    }

    public function test_the_installer_never_runs_a_destructive_command(): void
    {
        // The guard reports and stops; rebuilding the database is left to the
        // operator. An application can hold data in tables this command knows
        // nothing about, and an empty users table says nothing about them.
        //
        // Every string literal in the file is examined, so neither quoting
        // style hides one. A command assembled from a variable would escape
        // this, which is why the guard is also covered behaviourally above.
        $file = (string) (new ReflectionClass(InstallCommand::class))->getFileName();
        $destructive = ['migrate:fresh', 'migrate:reset', 'migrate:refresh', 'db:wipe', 'migrate:rollback'];

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $this->assertNotContains(
                trim($token[1], "'\""),
                $destructive,
                'The installer must never invoke a destructive database command itself.'
            );
        }
    }
}
