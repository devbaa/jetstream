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
     * A key column as the given driver reports it.
     *
     * @return array<string, mixed>
     */
    protected static function key(string $typeName, string $type = '', bool $autoIncrement = false): array
    {
        return [
            'type_name' => $typeName,
            'type' => $type === '' ? $typeName : $type,
            'auto_increment' => $autoIncrement,
        ];
    }

    /**
     * @return array<string, array{string, array<string, mixed>, bool}>
     */
    public static function keyColumnProvider(): array
    {
        return [
            // What "$table->uuid('id')" produces on each driver.
            'pgsql uuid' => ['pgsql', self::key('uuid'), true],
            'mysql char(36)' => ['mysql', self::key('char', 'char(36)'), true],
            'mariadb char(36)' => ['mariadb', self::key('char', 'char(36)'), true],
            'sqlite varchar' => ['sqlite', self::key('varchar'), true],

            // A shape another driver produces is not this driver's schema.
            'pgsql varchar is not a uuid column' => ['pgsql', self::key('varchar', 'character varying(255)'), false],
            'pgsql text is not a uuid column' => ['pgsql', self::key('text'), false],
            'pgsql char(36) is not a uuid column' => ['pgsql', self::key('char', 'character(36)'), false],
            'mysql varchar is not char(36)' => ['mysql', self::key('varchar', 'varchar(255)'), false],
            'mysql text is not char(36)' => ['mysql', self::key('text'), false],
            'mysql char(26) is a ulid' => ['mysql', self::key('char', 'char(26)'), false],
            'sqlite text is not what uuid() writes' => ['sqlite', self::key('text'), false],

            // Laravel's own migration, on every driver.
            'pgsql stock key' => ['pgsql', self::key('int8', 'bigint', true), false],
            'mysql stock key' => ['mysql', self::key('bigint', 'bigint(20) unsigned', true), false],
            'sqlite stock key' => ['sqlite', self::key('integer', 'integer', true), false],

            // An unfamiliar driver cannot be held to a shape, so only the
            // stock integer key is ruled out.
            'unknown driver rejects an integer key' => ['firebird', self::key('bigint', 'bigint', true), false],
            'unknown driver rejects a bare integer type' => ['firebird', self::key('integer'), false],
            'unknown driver accepts a string key' => ['firebird', self::key('varchar', 'varchar(36)'), true],
        ];
    }

    /**
     * @param  array<string, mixed>  $key
     */
    #[DataProvider('keyColumnProvider')]
    public function test_each_driver_is_checked_against_the_shape_it_produces(
        string $driver,
        array $key,
        bool $holdsUuid
    ): void {
        $this->assertSame($holdsUuid, InstallCommand::keyColumnHoldsUuid($key, $driver));
    }

    public function test_the_columns_jetstream_adds_are_required_as_well(): void
    {
        $uuid = self::key('uuid');

        // Upstream Laravel Jetstream adds both columns over an
        // auto-incrementing key, so the columns alone prove nothing...
        $this->assertNotNull(InstallCommand::usersTableMismatch(
            self::table(self::key('int8', 'bigint', true)), 'pgsql'
        ));

        // ...and the same skipped migration by another route: a compatible
        // key, but Jetstream's columns were never added.
        $this->assertNotNull(InstallCommand::usersTableMismatch(self::table($uuid, []), 'pgsql'));
        $this->assertNotNull(InstallCommand::usersTableMismatch(
            self::table($uuid, ['current_team_id']), 'pgsql'
        ));

        $this->assertNull(InstallCommand::usersTableMismatch(self::table($uuid), 'pgsql'));
    }

    public function test_the_mismatch_says_which_half_of_the_contract_failed(): void
    {
        $this->assertStringContainsString('does not hold a UUID', (string) InstallCommand::usersTableMismatch(
            self::table(self::key('bigint', 'bigint(20) unsigned', true)), 'mysql'
        ));

        $this->assertStringContainsString('current_team_id', (string) InstallCommand::usersTableMismatch(
            self::table(self::key('uuid'), []), 'pgsql'
        ));

        $this->assertStringContainsString('no id column', (string) InstallCommand::usersTableMismatch(
            [['name' => 'email', 'type_name' => 'varchar']], 'pgsql'
        ));
    }

    public function test_the_schema_this_package_migrates_is_accepted(): void
    {
        // Checked against a real users table built by this package's own
        // migrations, on whichever driver the suite is running against.
        $this->assertNull(InstallCommand::usersTableMismatch(
            Schema::getColumns('users'), Schema::getConnection()->getDriverName()
        ));
    }

    public function test_the_installer_never_runs_a_destructive_command(): void
    {
        // The guard reports and stops; rebuilding the database is left to the
        // operator. An application can hold data in tables this command knows
        // nothing about, and an empty users table says nothing about them.
        //
        // Every string literal in the file is examined, so neither quoting
        // style hides one. A command assembled from a variable would escape
        // this.
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
