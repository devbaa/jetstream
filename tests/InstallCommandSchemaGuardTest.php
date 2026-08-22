<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Console\InstallCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * The installer refuses to install onto a database its migration cannot fix.
 *
 * Jetstream publishes its own users migration over Laravel's, under the same
 * file name, and that one migration creates users, password_reset_tokens and
 * sessions. Laravel tracks migrations by name, so the ledger decides whether
 * the published file runs at all — and it can disagree with the schema in
 * both directions. Recorded, the replacement is skipped and whatever shape
 * the tables already had is final. Unrecorded, it runs and "Schema::create"
 * fails on a table that is already there.
 */
class InstallCommandSchemaGuardTest extends OrchestraTestCase
{
    /**
     * A column as the given driver reports it.
     *
     * @return array<string, mixed>
     */
    protected static function column(string $name, string $typeName, string $type = '', bool $autoIncrement = false): array
    {
        return [
            'name' => $name,
            'type_name' => $typeName,
            'type' => $type === '' ? $typeName : $type,
            'auto_increment' => $autoIncrement,
        ];
    }

    /**
     * A key column, without its name.
     *
     * @return array<string, mixed>
     */
    protected static function key(string $typeName, string $type = '', bool $autoIncrement = false): array
    {
        $column = self::column('id', $typeName, $type, $autoIncrement);

        unset($column['name']);

        return $column;
    }

    /**
     * The three tables as this package's migration leaves them on PostgreSQL.
     *
     * Each override replaces one table wholesale; null removes it.
     *
     * @param  array<string, list<array<string, mixed>>|null>  $overrides
     * @return array<string, list<array<string, mixed>>|null>
     */
    protected static function tables(array $overrides = []): array
    {
        return $overrides + [
            'users' => [
                self::column('id', 'uuid'),
                self::column('email', 'varchar', 'character varying(255)'),
                self::column('current_team_id', 'uuid'),
                self::column('profile_photo_path', 'varchar', 'character varying(2048)'),
            ],
            'password_reset_tokens' => [
                self::column('email', 'varchar', 'character varying(255)'),
            ],
            'sessions' => [
                self::column('id', 'varchar', 'character varying(255)'),
                self::column('user_id', 'uuid'),
            ],
        ];
    }

    /**
     * @return array<string, array{string, array<string, mixed>, bool}>
     */
    public static function keyColumnProvider(): array
    {
        return [
            // What "$table->uuid(...)" produces on each driver.
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

            // A stock foreign key is an integer without being auto-incrementing.
            'pgsql stock foreign key' => ['pgsql', self::key('int8', 'bigint'), false],
            'mysql stock foreign key' => ['mysql', self::key('bigint', 'bigint(20) unsigned'), false],
            'sqlite stock foreign key' => ['sqlite', self::key('integer'), false],

            // An unfamiliar driver cannot be held to a shape, so only the
            // stock integer column is ruled out.
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

    public function test_an_unrecorded_migration_with_none_of_its_tables_is_safe(): void
    {
        $this->assertNull(InstallCommand::usersMigrationMismatch(false, [
            'users' => null,
            'password_reset_tokens' => null,
            'sessions' => null,
        ], 'pgsql'));
    }

    public function test_an_unrecorded_migration_is_refused_when_its_tables_already_exist(): void
    {
        // "Schema::create" fails on the first table that is already there, so
        // the shape of these tables is beside the point.
        $this->assertSame('users already exists without the migration that creates it',
            InstallCommand::usersMigrationMismatch(false, self::tables([
                'password_reset_tokens' => null,
                'sessions' => null,
            ]), 'pgsql')
        );

        $this->assertSame('users, password_reset_tokens and sessions already exist without the migration that creates them',
            InstallCommand::usersMigrationMismatch(false, self::tables(), 'pgsql')
        );

        // Even a table this package did create, if the ledger has been lost.
        $this->assertNotNull(InstallCommand::usersMigrationMismatch(false, self::tables([
            'users' => null,
            'password_reset_tokens' => null,
        ]), 'pgsql'));
    }

    public function test_a_recorded_migration_is_safe_when_every_table_already_has_the_right_shape(): void
    {
        $this->assertNull(InstallCommand::usersMigrationMismatch(true, self::tables(), 'pgsql'));
    }

    /**
     * @return array<string, array{array<string, list<array<string, mixed>>|null>, string}>
     */
    public static function recordedMismatchProvider(): array
    {
        $stockKey = self::column('id', 'int8', 'bigint', true);
        $stockForeignKey = fn (string $name) => self::column($name, 'int8', 'bigint');

        return [
            // A table the migration creates was never created at all.
            'no users table' => [self::tables(['users' => null]), 'users is missing'],
            'no password reset tokens table' => [
                self::tables(['password_reset_tokens' => null]), 'password_reset_tokens is missing',
            ],
            'no sessions table' => [self::tables(['sessions' => null]), 'sessions is missing'],

            // Laravel's own users table, which is what "composer
            // create-project" leaves behind when it offers to migrate.
            'stock users key' => [
                self::tables(['users' => [$stockKey, $stockForeignKey('current_team_id')]]),
                'users.id does not hold a UUID',
            ],
            'no users key at all' => [
                self::tables(['users' => [self::column('email', 'varchar')]]), 'users.id is missing',
            ],

            // Upstream Laravel Jetstream adds both columns over an
            // auto-incrementing key, so their presence proves nothing...
            'upstream jetstream users table' => [
                self::tables(['users' => [
                    $stockKey,
                    $stockForeignKey('current_team_id'),
                    self::column('profile_photo_path', 'varchar', 'character varying(2048)'),
                ]]),
                'users.id does not hold a UUID',
            ],

            // ...and a UUID key proves nothing about the rest of the table.
            'uuid key but a stock team foreign key' => [
                self::tables(['users' => [
                    self::column('id', 'uuid'),
                    $stockForeignKey('current_team_id'),
                    self::column('profile_photo_path', 'varchar'),
                ]]),
                'users.current_team_id does not hold a UUID',
            ],
            'uuid key but no team column' => [
                self::tables(['users' => [self::column('id', 'uuid')]]), 'users.current_team_id is missing',
            ],
            'uuid key but no profile photo column' => [
                self::tables(['users' => [self::column('id', 'uuid'), self::column('current_team_id', 'uuid')]]),
                'users.profile_photo_path is missing',
            ],

            // ...or about sessions, which the installer points the session
            // driver at, so every request writes a user_id into it.
            'stock sessions user id' => [
                self::tables(['sessions' => [self::column('id', 'varchar'), $stockForeignKey('user_id')]]),
                'sessions.user_id does not hold a UUID',
            ],
            'no sessions user id' => [
                self::tables(['sessions' => [self::column('id', 'varchar')]]), 'sessions.user_id is missing',
            ],
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>|null>  $tables
     */
    #[DataProvider('recordedMismatchProvider')]
    public function test_a_recorded_migration_is_refused_when_the_schema_does_not_match(
        array $tables,
        string $mismatch
    ): void {
        $this->assertSame($mismatch, InstallCommand::usersMigrationMismatch(true, $tables, 'pgsql'));
    }

    public function test_the_schema_this_package_migrates_is_accepted(): void
    {
        // Checked against the real tables built by this package's own
        // migration, on whichever driver the suite is running against. The
        // suite migrates, so the recorded branch is the one that applies.
        $tables = [];

        foreach (InstallCommand::USERS_MIGRATION_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' should have been migrated.');

            $tables[$table] = Schema::getColumns($table);
        }

        $this->assertNull(InstallCommand::usersMigrationMismatch(
            true, $tables, Schema::getConnection()->getDriverName()
        ));
    }

    public function test_the_migration_checked_is_the_one_this_package_publishes(): void
    {
        // The guard is keyed on a migration file name, which is only the right
        // name for as long as the file is called that.
        $this->assertFileExists(
            __DIR__.'/../database/migrations/'.InstallCommand::USERS_MIGRATION.'.php'
        );
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
