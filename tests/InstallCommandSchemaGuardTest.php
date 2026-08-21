<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Console\InstallCommand;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The installer refuses to migrate onto a users table it did not create.
 *
 * Jetstream publishes its users table migration over Laravel's, under the
 * same file name. Laravel never re-runs a migration it has already recorded,
 * so an application that migrated first keeps an auto-incrementing key while
 * every table referencing users is built expecting a UUID.
 */
class InstallCommandSchemaGuardTest extends OrchestraTestCase
{
    /**
     * Key column descriptions as each supported driver reports them.
     *
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function keyColumnProvider(): array
    {
        return [
            // Incompatible: an integer key, however the driver spells it.
            'sqlite auto-increment' => [
                ['name' => 'id', 'type_name' => 'integer', 'type' => 'integer', 'auto_increment' => true],
                true,
            ],
            'mysql bigint unsigned' => [
                ['name' => 'id', 'type_name' => 'bigint', 'type' => 'bigint(20) unsigned', 'auto_increment' => true],
                true,
            ],
            'postgres bigserial' => [
                ['name' => 'id', 'type_name' => 'int8', 'type' => 'bigint', 'auto_increment' => true],
                true,
            ],
            'integer key without auto increment' => [
                ['name' => 'id', 'type_name' => 'bigint', 'type' => 'bigint', 'auto_increment' => false],
                true,
            ],
            'display width only' => [
                ['name' => 'id', 'type_name' => 'int(11)', 'type' => 'int(11)', 'auto_increment' => false],
                true,
            ],

            // Compatible: a string key holding a UUID.
            'sqlite uuid' => [
                ['name' => 'id', 'type_name' => 'varchar', 'type' => 'varchar', 'auto_increment' => false],
                false,
            ],
            'mysql char 36' => [
                ['name' => 'id', 'type_name' => 'char', 'type' => 'char(36)', 'auto_increment' => false],
                false,
            ],
            'postgres uuid' => [
                ['name' => 'id', 'type_name' => 'uuid', 'type' => 'uuid', 'auto_increment' => false],
                false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $column
     */
    #[DataProvider('keyColumnProvider')]
    public function test_an_integer_key_is_recognised_whatever_the_driver_calls_it(array $column, bool $expected): void
    {
        $this->assertSame($expected, InstallCommand::keyColumnIsIntegerBased($column));
    }

    public function test_upstream_jetstreams_columns_do_not_make_an_integer_key_acceptable(): void
    {
        // Upstream Laravel Jetstream adds profile_photo_path and
        // current_team_id over an auto-incrementing key, so neither column
        // says anything about whether this fork's schema is in place. Only
        // the key itself does.
        $this->assertTrue(InstallCommand::keyColumnIsIntegerBased([
            'name' => 'id',
            'type_name' => 'bigint',
            'type' => 'bigint(20) unsigned',
            'auto_increment' => true,
        ]));
    }

    public function test_the_schema_this_package_migrates_is_accepted(): void
    {
        // Sanity check against a real users table built by this package's own
        // migrations, rather than a hand-written description of one.
        $column = null;

        foreach (Schema::getColumns('users') as $candidate) {
            if (($candidate['name'] ?? null) === 'id') {
                $column = $candidate;
            }
        }

        $this->assertNotNull($column);
        $this->assertFalse(InstallCommand::keyColumnIsIntegerBased($column));
    }

    public function test_the_installer_never_drops_anything_itself(): void
    {
        // The guard reports and stops; rebuilding the database is left to the
        // operator. An application can hold data in tables this command knows
        // nothing about, and an empty users table says nothing about them, so
        // there must be no path from installing to dropping.
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(InstallCommand::class))->getFileName()
        );

        foreach (['migrate:fresh', 'migrate:reset', 'migrate:refresh', 'db:wipe'] as $destructive) {
            $this->assertStringNotContainsString(
                "'".$destructive."'",
                $source,
                'The installer must never invoke '.$destructive.' itself.'
            );
        }
    }

    public function test_data_outside_the_users_table_is_irrelevant_to_the_decision(): void
    {
        // An empty users table is not an empty database. The guard is decided
        // by the key type alone, so an application whose data lives elsewhere
        // is treated exactly the same as one with users in it.
        DB::table('users')->delete();

        $this->assertSame(0, DB::table('users')->count());

        $integerKeyed = ['name' => 'id', 'type_name' => 'bigint', 'type' => 'bigint', 'auto_increment' => true];

        $this->assertTrue(InstallCommand::keyColumnIsIntegerBased($integerKeyed));
    }
}
