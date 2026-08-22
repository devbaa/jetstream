<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Console\InstallCommand;

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
        $this->app[Kernel::class]->registerCommand(new InstallCommandGuardProbe);

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
