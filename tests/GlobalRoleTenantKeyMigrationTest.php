<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The tenant_key migration says the right thing to each driver, repairs what it
 * finds, and is worth having in the first place.
 *
 * There is no MySQL or SQL Server here to run against, and inventing one would
 * be worse than admitting it: what can be checked honestly is the decision the
 * migration makes per driver. The driver it is running on is covered for real
 * by GlobalRoleUniquenessTest.
 */
class GlobalRoleTenantKeyMigrationTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        Gate::policy(Tenant::class, TenantPolicy::class);
        Jetstream::useUserModel(User::class);
    }

    /**
     * The migration under test.
     *
     * The file returns the instance, so its decisions can be asked for
     * directly rather than inferred from the schema it happens to produce.
     */
    protected function migration(): Migration
    {
        return require __DIR__.'/../database/migrations/2026_08_29_100000_add_tenant_key_to_roles.php';
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function insertRole(?string $tenantId, string $key, array $permissions, ?string $createdAt = null): string
    {
        DB::table('roles')->insert([
            'id' => $id = (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => 'Role '.$key,
            'permissions' => json_encode($permissions),
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);

        return $id;
    }

    protected function createTenant(): Tenant
    {
        $owner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        return Tenant::forceCreate(['name' => 'Acme', 'slug' => 'acme', 'user_id' => $owner->id]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function generatedColumnStyleProvider(): array
    {
        return [
            // Stored generated columns, indexable, expression kept by the grammar.
            'pgsql' => ['pgsql', 'stored'],
            'mysql' => ['mysql', 'stored'],
            'mariadb' => ['mariadb', 'stored'],

            // ALTER TABLE accepts only virtual generated columns on sqlite.
            'sqlite' => ['sqlite', 'virtual'],

            // SQL Server has no virtualAs/storedAs modifier at all: its
            // grammar's modifiers are Collate, Nullable, Default, Persisted
            // and Increment. Asking for storedAs there drops the expression
            // without complaint and leaves a column that is always NULL.
            'sqlsrv' => ['sqlsrv', 'computed'],
        ];
    }

    #[DataProvider('generatedColumnStyleProvider')]
    public function test_each_driver_gets_the_generated_column_it_supports(string $driver, string $style): void
    {
        $this->assertSame($style, $this->migration()->generatedColumnStyle($driver));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function castProvider(): array
    {
        return [
            // Unbounded there, and the only driver whose CAST needs no length.
            'pgsql' => ['pgsql', "coalesce(cast(tenant_id as varchar), '')"],

            // An unqualified varchar means varchar(30) on SQL Server, which
            // would truncate a 36-character UUID; the length is load-bearing.
            'sqlsrv' => ['sqlsrv', "coalesce(cast(tenant_id as varchar(36)), '')"],

            // char(36) is the portable CAST spelling across the MySQL and
            // MariaDB versions this package supports.
            'mysql' => ['mysql', "coalesce(cast(tenant_id as char(36)), '')"],
            'mariadb' => ['mariadb', "coalesce(cast(tenant_id as char(36)), '')"],

            // The one driver deliberately left uncast.
            'sqlite' => ['sqlite', "coalesce(tenant_id, '')"],
        ];
    }

    #[DataProvider('castProvider')]
    public function test_each_driver_is_told_to_convert_in_its_own_spelling(string $driver, string $expression): void
    {
        $this->assertSame($expression, $this->migration()->expression($driver));
    }

    public function test_sqlite_is_the_only_driver_deliberately_left_uncast(): void
    {
        // sqlite is the only driver deliberately left uncast; every other one
        // converts. That is the strategy, not an inference from column types:
        // MySQL casts even though its uuid column is already char(36), so that
        // the expression does not quietly depend on which server version an
        // application runs — the MariaDB grammar picks a native uuid from 10.7,
        // and a contract that changes under it is not a contract.
        foreach (['pgsql', 'sqlsrv', 'mysql', 'mariadb'] as $driver) {
            $this->assertStringContainsString('cast(tenant_id as ', $this->migration()->expression($driver));
        }

        $this->assertStringNotContainsString('cast(', $this->migration()->expression('sqlite'));
    }

    public function test_the_key_stands_for_the_tenant_on_every_driver(): void
    {
        // Whatever the spelling, the column has to read as the tenant when
        // there is one and as a value — not NULL — when there is not. A NULL
        // there would put the constraint back where it started.
        foreach (['pgsql', 'mysql', 'mariadb', 'sqlite', 'sqlsrv'] as $driver) {
            $expression = $this->migration()->expression($driver);

            $this->assertStringContainsString('coalesce(', $expression);
            $this->assertStringContainsString('tenant_id', $expression);
            $this->assertStringContainsString("''", $expression);
        }
    }

    /**
     * @return array<string, array{class-string<\Illuminate\Database\Connection>, string}>
     */
    public static function uuidColumnTypeProvider(): array
    {
        // Only the two whose typeUuid() is unconditional, and so can be
        // compiled without a server to ask. MariaDB's consults the server
        // version, MySQL's blueprint compilation does, and sqlite's does —
        // asking them through a connection that has none would answer from
        // whatever the stub defaults to, which is not evidence of anything.
        // The driver the suite is running on is checked against its real
        // schema below instead.
        return [
            'pgsql' => [PostgresConnection::class, 'uuid'],
            'sqlsrv' => [SqlServerConnection::class, 'uniqueidentifier'],
        ];
    }

    /**
     * @param  class-string<\Illuminate\Database\Connection>  $connection
     */
    #[DataProvider('uuidColumnTypeProvider')]
    public function test_laravel_does_not_compile_a_uuid_column_to_character_data(string $connection, string $type): void
    {
        // The per-driver decision rests on this and nothing else, so it is
        // asked of Laravel's own grammar rather than assumed.
        //
        // No server is contacted: compiling DDL is the grammar's own work and
        // the PDO closure is never called.
        $stub = new $connection(
            fn () => throw new RuntimeException('The grammar must not need a connection.'),
            'jetstream', '', []
        );

        $stub->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($stub, 'roles');

        $blueprint->uuid('tenant_id');

        $this->assertStringContainsString($type, implode(' ', $blueprint->toSql()));
    }

    public function test_the_index_is_named_by_its_columns(): void
    {
        // Both the index it creates and the one it replaces are named by
        // columns, so Laravel regenerates the name it generated originally —
        // including the connection's table prefix, which a literal name would
        // ignore.
        $migration = $this->migration();

        $this->assertSame(['tenant_key', 'key'], $migration::UNIQUE);
        $this->assertSame(['tenant_id', 'key'], $migration::REPLACED);
    }

    public function test_the_running_driver_actually_built_the_index(): void
    {
        // Whatever the decision was for this driver, it has to have produced a
        // real index — the per-driver reasoning above is only worth anything
        // if the branch it picks works.
        $indexes = collect(Schema::getIndexes('roles'))
            ->filter(fn (array $index): bool => $index['columns'] === ['tenant_key', 'key']);

        $this->assertCount(1, $indexes, 'No index over the role scope was created.');
        $this->assertTrue($indexes->first()['unique'] ?? false);
    }

    public function test_the_index_it_replaces_is_gone(): void
    {
        // Every pair the old index separated, the new one separates too.
        // Leaving it would mean a second unique index that can never be the
        // one to reject anything.
        $superseded = collect(Schema::getIndexes('roles'))
            ->filter(fn (array $index): bool => $index['columns'] === ['tenant_id', 'key']
                && ($index['unique'] ?? false));

        $this->assertCount(0, $superseded);
    }

    public function test_the_tenant_a_role_belongs_to_is_still_indexed_on_its_own(): void
    {
        // The new index leads with tenant_key, so it is no use to the resolver,
        // which asks for the defaults and one tenant's rows by tenant_id. The
        // plain index the table was created with is what serves that, and
        // dropping the old unique one must not have taken it too.
        $this->assertTrue(
            collect(Schema::getIndexes('roles'))->contains(
                fn (array $index): bool => $index['columns'] === ['tenant_id']
            )
        );
    }

    public function test_a_duplicated_default_role_would_make_its_permissions_ambiguous(): void
    {
        // Why the constraint is worth a generated column. Reached by reversing
        // the migration, because it is not reachable with it in place.
        //
        // Two rows exist and one role is resolved: RoleRegistry keys by role
        // key, and its query orders only by whether tenant_id is null — a
        // question both rows answer identically. Whatever comes back last wins,
        // and what a role grants stops being a property of the role.
        $migration = $this->migration();

        $migration->down();

        try {
            $tenant = $this->createTenant();

            $this->insertRole(null, 'support', ['read']);
            $this->insertRole(null, 'support', ['read', 'delete']);

            $this->assertSame(2, DB::table('roles')->whereNull('tenant_id')->where('key', 'support')->count());

            app(RoleRegistry::class)->flush();

            $resolved = Jetstream::findRole('support', $tenant);

            $this->assertNotNull($resolved);

            $this->assertContains(
                $resolved->permissions,
                [['read'], ['read', 'delete']],
                'The resolver returned permissions neither row holds.'
            );

            $this->assertCount(
                1,
                array_filter(
                    app(RoleRegistry::class)->all($tenant->id),
                    fn ($role): bool => $role->key === 'support'
                ),
                'Two rows resolved to two roles, so the ambiguity is somewhere else.'
            );
        } finally {
            DB::table('roles')->delete();

            $migration->up();
        }
    }

    public function test_duplicates_already_in_the_table_are_collapsed_to_the_oldest(): void
    {
        // An installation that has been seeding from more than one node may
        // already hold two rows for one default, and the index cannot be
        // created over them. Rehearsed by reversing the migration, writing the
        // rows the old schema allowed, and running it forward again.
        $migration = $this->migration();

        $migration->down();

        $tenant = $this->createTenant();

        $first = $this->insertRole(null, 'support', ['read'], '2026-01-01 00:00:00');

        $this->insertRole(null, 'support', ['read', 'delete'], '2026-02-01 00:00:00');
        $this->insertRole(null, 'support', ['read', 'update'], '2026-03-01 00:00:00');

        // A different default, and a tenant's own role under the duplicated
        // key: neither is a duplicate, and neither may be swept up.
        $otherDefault = $this->insertRole(null, 'billing', ['read'], '2026-01-01 00:00:00');
        $tenantRole = $this->insertRole($tenant->id, 'support', ['read'], '2026-01-01 00:00:00');

        $this->assertSame(3, DB::table('roles')->whereNull('tenant_id')->where('key', 'support')->count());

        $migration->up();

        $survivors = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('key', 'support')
            ->pluck('id')
            ->all();

        $this->assertSame([$first], $survivors, 'The repair did not keep the oldest default.');
        $this->assertSame(1, DB::table('roles')->where('id', $otherDefault)->count());
        $this->assertSame(1, DB::table('roles')->where('id', $tenantRole)->count());
    }

    public function test_reversing_it_restores_the_index_it_replaced(): void
    {
        // down() has to leave the table as it found it, not merely without the
        // new index: dropping the new one and forgetting the old would leave
        // the table with no uniqueness at all.
        $migration = $this->migration();

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('roles', 'tenant_key'));

            $restored = collect(Schema::getIndexes('roles'))
                ->filter(fn (array $index): bool => $index['columns'] === ['tenant_id', 'key']
                    && ($index['unique'] ?? false));

            $this->assertCount(1, $restored);
        } finally {
            $migration->up();
        }
    }
}
