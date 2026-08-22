<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Console\InstallCommand;
use RuntimeException;

/**
 * The guard reads Laravel's migration ledger, not just the schema.
 *
 * Whether the users migration Jetstream publishes will run is recorded in the
 * migrations table and nowhere else. A schema that looks right says nothing
 * about it: the same tables mean "already migrated, leave them alone" when the
 * name is recorded and "the migration is about to fail" when it is not.
 */
class InstallCommandMigrationLedgerTest extends OrchestraTestCase
{
    /**
     * Run the installer's guard on its own and return its exit code.
     */
    protected function guard(): int
    {
        return $this->artisan('jetstream:guard-probe')->run();
    }

    public function test_the_suites_own_database_is_accepted(): void
    {
        // Migrated by this package's migrations, so both the ledger and the
        // schema agree — the state a fresh install is meant to reach.
        $this->assertSame(0, $this->guard());
    }

    public function test_the_ledger_decides_rather_than_the_schema(): void
    {
        // Exactly the same tables as the passing case above. Only the ledger
        // entry is gone, and that alone has to be enough to refuse: the
        // migration would run and "Schema::create('users')" would fail.
        $this->assertTrue(Schema::hasTable('users'));

        DB::table('migrations')->where('migration', InstallCommand::USERS_MIGRATION)->delete();

        $this->assertSame(1, $this->guard());
    }

    public function test_a_recorded_migration_whose_tables_are_gone_is_refused(): void
    {
        // The other direction: the ledger says the migration ran, so it never
        // will again, and nothing would create the tables it is missing.
        Schema::dropIfExists('sessions');

        $this->assertSame(1, $this->guard());
    }

    public function test_an_empty_database_is_accepted(): void
    {
        // Nothing recorded and nothing created — no ledger table at all, which
        // is the state "artisan migrate" builds one from. The migration will
        // run and create all three tables itself.
        $connection = Schema::getConnection();

        Schema::disableForeignKeyConstraints();

        foreach (InstallCommand::USERS_MIGRATION_TABLES as $table) {
            // Half the schema has a foreign key onto users, and PostgreSQL
            // refuses to drop a table those still point at.
            $connection->getDriverName() === 'pgsql'
                ? $connection->statement('drop table if exists "'.$table.'" cascade')
                : Schema::dropIfExists($table);
        }

        Schema::dropIfExists('migrations');
        Schema::enableForeignKeyConstraints();

        $this->assertSame(0, $this->guard());
    }

    public function test_a_ledger_that_cannot_be_reached_stops_the_install(): void
    {
        // A connection failure, a permission denied, an application that has
        // bound its own repository — none of them mean the migration will
        // apply, and this command goes on to run "artisan migrate" itself.
        $this->app->bind('migration.repository', fn () => new ThrowingMigrationRepository('repositoryExists'));

        $this->artisan('jetstream:guard-probe')
            ->expectsOutputToContain('could not verify the database schema')
            ->assertExitCode(1);
    }

    public function test_a_ledger_that_cannot_be_listed_stops_the_install(): void
    {
        // The failure can just as easily come one call later.
        $this->app->bind('migration.repository', fn () => new ThrowingMigrationRepository('getRan'));

        $this->assertSame(1, $this->guard());
    }

    public function test_a_schema_that_cannot_be_inspected_stops_the_install(): void
    {
        // The ledger answers, so the failure is in the schema read that
        // follows it: the connection the columns would come from is not one
        // that can be opened.
        $this->app->bind('migration.repository', fn () => new ThrowingMigrationRepository(''));

        $original = $this->app['config']->get('database.default');

        $this->app['config']->set('database.connections.unopenable', [
            'driver' => 'sqlite',
            'database' => __DIR__.'/no-such-directory/database.sqlite',
        ]);

        try {
            $this->app['config']->set('database.default', 'unopenable');

            DB::clearResolvedInstances();
            Schema::clearResolvedInstances();

            $this->assertSame(1, $this->guard());
        } finally {
            $this->app['config']->set('database.default', $original);

            DB::clearResolvedInstances();
            Schema::clearResolvedInstances();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The lazy refresh migrates on the first query and discards the
        // console application afterwards, so touch the database before the
        // probe is registered or the registration is thrown away with it.
        Schema::hasTable('migrations');

        $this->app[Kernel::class]->registerCommand(new InstallCommandGuardProbe);
    }
}

/**
 * Runs the installer's guard without installing anything.
 *
 * The guard is the installer's own method — this only gives it a command to
 * run inside, so the container, the ledger and the console output are wired
 * up exactly as they are during an install.
 */
class InstallCommandGuardProbe extends InstallCommand
{
    /** @var string */
    protected $signature = 'jetstream:guard-probe';

    /** {@inheritdoc} */
    #[\Override]
    public function handle()
    {
        return $this->ensureUsersMigrationIsConsistent() ? 0 : 1;
    }
}

/**
 * A migration repository that fails the way an unreachable one would.
 *
 * Answering everything else lets a test place the failure at one call and
 * watch what the guard does with it.
 */
class ThrowingMigrationRepository implements MigrationRepositoryInterface
{
    public function __construct(protected string $failingMethod)
    {
        //
    }

    protected function answer(string $method, mixed $value): mixed
    {
        if ($method === $this->failingMethod) {
            throw new RuntimeException('SQLSTATE[08006] could not connect to server');
        }

        return $value;
    }

    /** {@inheritdoc} */
    public function getRan()
    {
        return $this->answer('getRan', [InstallCommand::USERS_MIGRATION]);
    }

    /** {@inheritdoc} */
    public function repositoryExists()
    {
        return $this->answer('repositoryExists', true);
    }

    /** {@inheritdoc} */
    public function getMigrations($steps)
    {
        return [];
    }

    /** {@inheritdoc} */
    public function getMigrationsByBatch($batch)
    {
        return [];
    }

    /** {@inheritdoc} */
    public function getLast()
    {
        return [];
    }

    /** {@inheritdoc} */
    public function getMigrationBatches()
    {
        return [];
    }

    /** {@inheritdoc} */
    public function log($file, $batch)
    {
        //
    }

    /** {@inheritdoc} */
    public function delete($migration)
    {
        //
    }

    /** {@inheritdoc} */
    public function getNextBatchNumber()
    {
        return 1;
    }

    /** {@inheritdoc} */
    public function createRepository()
    {
        //
    }

    /** {@inheritdoc} */
    public function deleteRepository()
    {
        //
    }

    /** {@inheritdoc} */
    public function setSource($name)
    {
        //
    }
}
