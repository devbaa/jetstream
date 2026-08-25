<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateTenant;
use App\Models\Tenant;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The account_key migration says the right thing to each driver, and repairs
 * what it finds before insisting on the rule.
 *
 * There is no MySQL or SQL Server here to run against, and inventing one would
 * be worse than admitting it: what can be checked honestly is the decision the
 * migration makes per driver. The driver it is running on is covered for real
 * by CustomerInvitationUniquenessTest.
 */
class CustomerInvitationAccountKeyMigrationTest extends OrchestraTestCase
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
        return require __DIR__.'/../database/migrations/2026_08_28_100000_add_account_key_to_customer_invitations.php';
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

    public function test_postgresql_is_told_to_cast_the_uuid(): void
    {
        // customer_account_id is a native uuid there, and coalesce insists
        // both of its branches share a type. Without the cast the generated
        // column cannot be created at all. PostgreSQL's unqualified varchar is
        // unbounded, so it needs no length.
        $expression = $this->migration()->expression('pgsql');

        $this->assertSame("coalesce(cast(customer_account_id as varchar), '')", $expression);
    }

    public function test_sql_server_is_told_to_cast_the_uuid_to_a_bounded_string(): void
    {
        // foreignUuid() compiles to uniqueidentifier there, not to character
        // data. SQL Server would convert rather than refuse, and that is worse:
        // coalesce returns the operand with the highest data type precedence,
        // and uniqueidentifier outranks varchar, so the '' branch would be
        // converted to uniqueidentifier and fail on the empty string — the one
        // case this column exists to represent.
        $expression = $this->migration()->expression('sqlsrv');

        $this->assertSame("coalesce(cast(customer_account_id as varchar(36)), '')", $expression);

        // The length is load-bearing: CAST to an unqualified varchar on SQL
        // Server means varchar(30), which truncates a 36-character UUID and
        // leaves the key standing for a prefix of the account.
        $this->assertStringNotContainsString('as varchar)', $expression);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function castProvider(): array
    {
        return [
            // Unbounded there, and the only driver whose CAST needs no length.
            'pgsql' => ['pgsql', "coalesce(cast(customer_account_id as varchar), '')"],

            // An unqualified varchar means varchar(30) on SQL Server, which
            // would truncate a 36-character UUID; the length is load-bearing.
            'sqlsrv' => ['sqlsrv', "coalesce(cast(customer_account_id as varchar(36)), '')"],

            // char(36) is the portable CAST spelling across the MySQL and
            // MariaDB versions this package supports.
            'mysql' => ['mysql', "coalesce(cast(customer_account_id as char(36)), '')"],
            'mariadb' => ['mariadb', "coalesce(cast(customer_account_id as char(36)), '')"],

            // The one driver where a uuid column is character data whatever
            // the server, so there is nothing to convert.
            'sqlite' => ['sqlite', "coalesce(customer_account_id, '')"],
        ];
    }

    #[DataProvider('castProvider')]
    public function test_each_driver_is_told_to_convert_in_its_own_spelling(string $driver, string $expression): void
    {
        $this->assertSame($expression, $this->migration()->expression($driver));
    }

    public function test_only_sqlite_is_trusted_to_hold_the_account_as_a_string(): void
    {
        // sqlite is the only driver deliberately left uncast; every other one
        // converts. That is the strategy, not an inference from column types:
        // MySQL casts even though its uuid column is already char(36), so that
        // the expression does not quietly depend on which server version an
        // application runs — the MariaDB grammar picks a native uuid from 10.7,
        // and a contract that changes under it is not a contract.
        foreach (['pgsql', 'sqlsrv', 'mysql', 'mariadb'] as $driver) {
            $this->assertStringContainsString('cast(customer_account_id as ', $this->migration()->expression($driver));
        }

        $this->assertStringNotContainsString('cast(', $this->migration()->expression('sqlite'));
    }

    public function test_the_key_stands_for_the_account_on_every_driver(): void
    {
        // Whatever the spelling, the column has to read as the account when
        // there is one and as a value — not NULL — when there is not. A NULL
        // there would put the constraint back where it started.
        foreach (['pgsql', 'mysql', 'mariadb', 'sqlite', 'sqlsrv'] as $driver) {
            $expression = $this->migration()->expression($driver);

            $this->assertStringContainsString('coalesce(', $expression);
            $this->assertStringContainsString('customer_account_id', $expression);
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
        // asked of Laravel's own grammar rather than assumed. Assuming it is
        // how customer_account_id came to be treated as character data on SQL
        // Server, where the consequence is silent: SQL Server converts the
        // other operand rather than refusing.
        //
        // No server is contacted: compiling DDL is the grammar's own work and
        // the PDO closure is never called.
        $stub = new $connection(
            fn () => throw new RuntimeException('The grammar must not need a connection.'),
            'jetstream', '', []
        );

        $stub->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($stub, 'customer_invitations');

        $blueprint->uuid('customer_account_id');

        $this->assertStringContainsString($type, implode(' ', $blueprint->toSql()));
    }

    public function test_the_index_is_named_by_its_columns(): void
    {
        // Both the index it creates and the one it replaces are named by
        // columns, so Laravel regenerates the name it generated originally —
        // including the connection's table prefix, which a literal name would
        // ignore.
        $migration = $this->migration();

        $this->assertSame(['tenant_id', 'account_key', 'email'], $migration::UNIQUE);
        $this->assertSame(['tenant_id', 'customer_account_id', 'email'], $migration::REPLACED);
    }

    public function test_the_running_driver_actually_built_the_index(): void
    {
        // Whatever the decision was for this driver, it has to have produced a
        // real index — the per-driver reasoning above is only worth anything
        // if the branch it picks works.
        $indexes = collect(Schema::getIndexes('customer_invitations'))
            ->filter(fn (array $index): bool => $index['columns'] === ['tenant_id', 'account_key', 'email']);

        $this->assertCount(1, $indexes, 'No index over the invitation key was created.');
        $this->assertTrue($indexes->first()['unique'] ?? false);
    }

    public function test_the_index_it_replaces_is_gone(): void
    {
        // Every pair the old index separated, the new one separates too.
        // Leaving it would mean a second unique index that can never be the
        // one to reject anything.
        $superseded = collect(Schema::getIndexes('customer_invitations'))
            ->filter(fn (array $index): bool => $index['columns'] === ['tenant_id', 'customer_account_id', 'email']);

        $this->assertCount(0, $superseded);
    }

    public function test_duplicates_already_in_the_table_are_collapsed_to_the_oldest(): void
    {
        // An application that has been running the racing version may already
        // hold two rows for one invitation, and the index cannot be created
        // over them. Rehearsed by reversing the migration, writing the rows
        // the old schema allowed, and running it forward again.
        $migration = $this->migration();

        $migration->down();

        $owner = User::forceCreate([
            'name' => 'Owner',
            'email' => 'owner@acme.test',
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $first = $this->insertLegacyInvitation($tenant, 'jane@example.test', '2026-01-01 00:00:00');

        $this->insertLegacyInvitation($tenant, 'jane@example.test', '2026-02-01 00:00:00');
        $this->insertLegacyInvitation($tenant, 'jane@example.test', '2026-03-01 00:00:00');

        // A different person in the same tenant, and the same person in the
        // same tenant with a real destination: neither is a duplicate, and
        // neither may be swept up by the repair.
        $untouched = $this->insertLegacyInvitation($tenant, 'other@example.test', '2026-01-01 00:00:00');

        $this->assertSame(3, DB::table('customer_invitations')->where('email', 'jane@example.test')->count());

        $migration->up();

        $survivors = DB::table('customer_invitations')
            ->where('email', 'jane@example.test')
            ->pluck('id')
            ->all();

        $this->assertSame([$first], $survivors, 'The repair did not keep the oldest invitation.');
        $this->assertSame(1, DB::table('customer_invitations')->where('id', $untouched)->count());

        // And the rule now holds, which is what the repair was for.
        $this->assertTrue(
            collect(Schema::getIndexes('customer_invitations'))
                ->contains(fn (array $index): bool => $index['columns'] === ['tenant_id', 'account_key', 'email'])
        );
    }

    public function test_reversing_it_restores_the_index_it_replaced(): void
    {
        // down() has to leave the table as it found it, not merely without the
        // new index: dropping the new one and forgetting the old would leave
        // the table with no uniqueness at all.
        $migration = $this->migration();

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('customer_invitations', 'account_key'));

            $restored = collect(Schema::getIndexes('customer_invitations'))
                ->filter(fn (array $index): bool => $index['columns'] === ['tenant_id', 'customer_account_id', 'email']);

            $this->assertCount(1, $restored);
            $this->assertTrue($restored->first()['unique'] ?? false);
        } finally {
            $migration->up();
        }
    }

    /**
     * Insert an invitation the way the schema before this migration allowed.
     */
    protected function insertLegacyInvitation(Tenant $tenant, string $email, string $createdAt): string
    {
        DB::table('customer_invitations')->insert([
            'id' => $id = (string) Str::uuid7(),
            'tenant_id' => $tenant->getKey(),
            'customer_account_id' => null,
            'email' => $email,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    }
}
