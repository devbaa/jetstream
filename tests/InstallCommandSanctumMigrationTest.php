<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Filesystem\Filesystem;
use Laravel\Jetstream\Console\InstallCommand;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A fresh install creates Sanctum's token column, it does not fix it later.
 *
 * "install:api" publishes Sanctum's migration stamped with the current
 * date-time, so a migration shipped by this package cannot be relied on to
 * sort after it — installed far enough in the future, the correction would run
 * before the table it corrects exists. Rewriting the published file removes
 * the ordering question: the application creates the right column once.
 */
class InstallCommandSanctumMigrationTest extends OrchestraTestCase
{
    /**
     * Sanctum's migration, as it publishes it.
     */
    protected function sanctumStub(): string
    {
        $paths = (array) glob(
            __DIR__.'/../vendor/laravel/sanctum/database/migrations/*_create_personal_access_tokens_table.php'
        );

        $path = $paths[0] ?? null;

        if (! is_string($path)) {
            $this->markTestSkipped('Sanctum does not ship a personal access tokens migration.');
        }

        return (string) file_get_contents($path);
    }

    /**
     * Publish the given migration content under a date-stamped name.
     */
    protected function publish(string $name, string $contents): string
    {
        (new Filesystem)->ensureDirectoryExists(database_path('migrations'));

        file_put_contents($path = database_path('migrations/'.$name), $contents);

        return $path;
    }

    /**
     * Run the installer's correction over whatever is on disk.
     */
    protected function correct(): void
    {
        (new class extends InstallCommand
        {
            public function run_correction(): void
            {
                $this->correctSanctumTokenableColumn();
            }
        })->run_correction();
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

    public function test_the_published_migration_stops_declaring_an_integer_key(): void
    {
        $path = $this->publish('2026_08_27_120000_create_personal_access_tokens_table.php', $this->sanctumStub());

        // Sanctum really does ship morphs(); if it ever stops, this test is
        // asserting against something that is no longer there.
        $this->assertStringContainsString("\$table->morphs('tokenable');", (string) file_get_contents($path));

        $this->correct();

        $corrected = (string) file_get_contents($path);

        $this->assertStringNotContainsString("\$table->morphs('tokenable');", $corrected);
        $this->assertStringContainsString("\$table->string('tokenable_id');", $corrected);
        $this->assertStringContainsString("\$table->string('tokenable_type');", $corrected);
    }

    public function test_the_morph_index_is_kept(): void
    {
        // morphs() indexes the pair. Replacing it with two bare columns would
        // silently drop the index every token lookup reads through.
        $path = $this->publish('2026_08_27_120000_create_personal_access_tokens_table.php', $this->sanctumStub());

        $this->correct();

        $this->assertStringContainsString(
            "\$table->index(['tokenable_type', 'tokenable_id']);",
            (string) file_get_contents($path)
        );
    }

    public function test_the_corrected_migration_is_still_valid_php(): void
    {
        $path = $this->publish('2026_08_27_120000_create_personal_access_tokens_table.php', $this->sanctumStub());

        $this->correct();

        // It is edited as text, so the only real proof it is still a migration
        // is that PHP will parse it and it still returns one.
        $this->assertInstanceOf(\Illuminate\Database\Migrations\Migration::class, require $path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unrelatedMigrationProvider(): array
    {
        return [
            'another table' => ['2026_08_27_120000_create_widgets_table.php'],
            'a table whose name merely contains the word' => ['2026_08_27_120000_create_team_tokens_table.php'],
        ];
    }

    #[DataProvider('unrelatedMigrationProvider')]
    public function test_other_migrations_are_left_alone(string $name): void
    {
        // The correction matches by file name, so a migration that happens to
        // use morphs() for something else must not be rewritten.
        $original = <<<'PHP'
        <?php

        return new class
        {
            public function up(): void
            {
                $table->morphs('tokenable');
            }
        };
        PHP;

        $path = $this->publish($name, $original);

        $this->correct();

        $this->assertSame($original, (string) file_get_contents($path));
    }

    public function test_running_it_twice_changes_nothing_further(): void
    {
        // The installer is re-runnable, and a second pass finds no morphs()
        // call to replace, so the file it already corrected stays as it is.
        $path = $this->publish('2026_08_27_120000_create_personal_access_tokens_table.php', $this->sanctumStub());

        $this->correct();

        $once = (string) file_get_contents($path);

        $this->correct();

        $this->assertSame($once, (string) file_get_contents($path));
    }
}
