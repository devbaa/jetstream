<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Console\InstallCommand;

/**
 * A migration that fails ends the install as a failure.
 *
 * The installer shells out to "artisan migrate --force" and used to discard
 * the exit status, so a migration could fail in full view of the operator and
 * the very next line would say the installation succeeded — and the command
 * would exit zero, which is what any script wrapping it reads.
 *
 * The child process here is real: a stand-in binary that writes the kind of
 * output a broken migration writes and exits non-zero. What is under test is
 * that the installer notices, keeps the child's output, and carries the
 * failure through to its own exit status.
 */
class InstallCommandMigrationFailureTest extends OrchestraTestCase
{
    /**
     * The message a failing migration writes, which must survive to the screen.
     */
    protected const CHILD_OUTPUT = 'SQLSTATE[42S01]: Base table or view already exists';

    /**
     * The prompt the installer asks before it migrates.
     */
    protected const PROMPT = 'New database migrations were added. Would you like to run your migrations?';

    /**
     * Path to the stand-in for the PHP binary the installer shells out to.
     */
    protected function binary(string $name): string
    {
        return $this->app->basePath('jetstream-test-'.$name);
    }

    /**
     * Write an executable stand-in that exits with the given status.
     */
    protected function writeBinary(string $name, int $status): string
    {
        $path = $this->binary($name);

        file_put_contents($path, implode("\n", [
            '#!/bin/sh',
            'echo "  '.static::CHILD_OUTPUT.'"',
            'echo "  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:825" >&2',
            'exit '.$status,
        ])."\n");

        chmod($path, 0755);

        return $path;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The stand-in migration binary is a shell script.');
        }

        // The lazy refresh migrates on the first query and discards the console
        // application afterwards, so touch the database before the probe is
        // registered or the registration is thrown away with it.
        Schema::hasTable('migrations');
    }

    protected function tearDown(): void
    {
        foreach (['failing', 'succeeding'] as $name) {
            (new Filesystem)->delete($this->binary($name));
        }

        parent::tearDown();
    }

    /**
     * Register a probe that migrates through the given stand-in binary.
     */
    protected function registerProbe(string $name, int $status): void
    {
        $this->app[Kernel::class]->registerCommand(
            new InstallCommandMigrationProbe($this->writeBinary($name, $status))
        );
    }

    public function test_a_failing_migration_is_reported_as_a_failure_and_exits_non_zero(): void
    {
        $this->registerProbe('failing', 1);

        $this->artisan('jetstream:migration-probe')
            ->expectsConfirmation(static::PROMPT, 'yes')
            // The child's own diagnosis is not swallowed...
            ->expectsOutputToContain(static::CHILD_OUTPUT)
            // ...and the installer says plainly that it failed...
            ->expectsOutputToContain('The database migrations failed with exit status 1.')
            // ...rather than announcing success.
            ->doesntExpectOutputToContain('installed successfully')
            ->assertExitCode(1);
    }

    public function test_an_unusual_exit_status_is_still_a_failure(): void
    {
        // Nothing here treats 1 as "the" failure status; anything but 0 is one.
        $this->registerProbe('failing', 137);

        $this->artisan('jetstream:migration-probe')
            ->expectsConfirmation(static::PROMPT, 'yes')
            ->expectsOutputToContain('The database migrations failed with exit status 137.')
            ->doesntExpectOutputToContain('installed successfully')
            ->assertExitCode(1);
    }

    public function test_a_successful_migration_still_reports_success_and_exits_zero(): void
    {
        // The other half of the contract: this must not turn every install
        // into a failure. Same stand-in, same output, exit status zero.
        $this->registerProbe('succeeding', 0);

        $this->artisan('jetstream:migration-probe')
            ->expectsConfirmation(static::PROMPT, 'yes')
            ->expectsOutputToContain(static::CHILD_OUTPUT)
            ->expectsOutputToContain('installed successfully')
            ->doesntExpectOutputToContain('The database migrations failed')
            ->assertExitCode(0);
    }

    public function test_a_refused_migration_is_still_reported_as_a_refusal(): void
    {
        // The pre-flight consistency check and a failed child process are
        // different problems and keep their own messages, but both end the
        // install as a failure.
        $this->registerProbe('failing', 1);

        Schema::dropIfExists('sessions');

        $this->artisan('jetstream:migration-probe')
            ->expectsOutputToContain('the database was not migrated')
            ->doesntExpectOutputToContain('installed successfully')
            // The child never ran, so its output is not on screen either.
            ->doesntExpectOutputToContain(static::CHILD_OUTPUT)
            ->assertExitCode(1);
    }
}

/**
 * Runs the installer's migration step and its outcome reporting.
 *
 * Both are the installer's own methods, and the exit status is the same
 * `exitStatus()` the real `handle()` returns, so what is asserted is the
 * production decision rather than a copy of it. Everything before this point
 * in a real install — Composer, npm, the scaffolding — is what makes running
 * `jetstream:install` itself impractical here; the generated-application CI
 * job covers that end of it.
 */
class InstallCommandMigrationProbe extends InstallCommand
{
    /** @var string */
    protected $signature = 'jetstream:migration-probe';

    public function __construct(protected string $binary)
    {
        parent::__construct();
    }

    /** {@inheritdoc} */
    #[\Override]
    public function handle()
    {
        $this->finishInstallation();

        return $this->exitStatus();
    }

    /** {@inheritdoc} */
    #[\Override]
    protected function phpBinary()
    {
        return $this->binary;
    }
}
