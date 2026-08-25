<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The active_domain migration says the right thing to each driver.
 *
 * There is no SQL Server here to run against, and inventing one would be
 * worse than admitting it: what can be checked honestly is the decision the
 * migration makes per driver, which is where the portability actually lives.
 * The driver it is running on is covered for real by
 * DomainClaimUniquenessTest.
 */
class DomainClaimActiveDomainMigrationTest extends OrchestraTestCase
{
    /**
     * The migration under test.
     *
     * The file returns the instance, so its decisions can be asked for
     * directly rather than inferred from the schema it happens to produce.
     */
    protected function migration(): Migration
    {
        return require __DIR__.'/../database/migrations/2026_08_25_100000_add_active_domain_to_domain_claims.php';
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
     * @return array<string, array{string, bool}>
     */
    public static function filteredIndexProvider(): array
    {
        return [
            // NULLs are distinct for uniqueness, so a plain unique index
            // already means "at most one active claim per domain".
            'pgsql' => ['pgsql', false],
            'mysql' => ['mysql', false],
            'mariadb' => ['mariadb', false],
            'sqlite' => ['sqlite', false],

            // SQL Server compares NULLs as equal, so a plain unique index
            // would allow one NULL row in the whole table — one inactive
            // claim, ever — and would refuse to be created at all on a
            // database that already holds history.
            'sqlsrv' => ['sqlsrv', true],
        ];
    }

    #[DataProvider('filteredIndexProvider')]
    public function test_only_the_driver_that_compares_nulls_needs_a_filter(string $driver, bool $filtered): void
    {
        $this->assertSame($filtered, $this->migration()->usesFilteredUniqueIndex($driver));
    }

    public function test_the_filtered_index_excludes_null_rows(): void
    {
        $sql = $this->migration()->filteredUniqueIndexSql();

        $this->assertStringContainsString('create unique index', $sql);
        $this->assertStringContainsString('[active_domain]', $sql);

        // The filter is the whole point: without it the index is the plain
        // one that cannot hold more than a single inactive claim.
        $this->assertStringContainsString('where [active_domain] is not null', $sql);
    }

    public function test_both_spellings_of_the_index_share_one_name(): void
    {
        // down() drops the index by name and does not know which spelling
        // up() chose, so the two must agree.
        $migration = $this->migration();

        $this->assertStringContainsString('['.$migration::INDEX.']', $migration->filteredUniqueIndexSql());

        // And the name is the one Laravel generates for the plain index, so
        // nothing already published under the default name is orphaned.
        $this->assertSame('domain_claims_active_domain_unique', $migration::INDEX);
    }

    public function test_the_expression_is_the_definition_of_active(): void
    {
        // isActive() is verified_at !== null && superseded_at === null. If the
        // column ever stopped agreeing with that, the constraint would be
        // guarding a different condition than the application reads.
        $expression = $this->migration()->expression();

        $this->assertStringContainsString('verified_at is not null', $expression);
        $this->assertStringContainsString('superseded_at is null', $expression);
        $this->assertStringContainsString('then domain', $expression);
    }

    public function test_the_running_driver_actually_built_the_index(): void
    {
        // Whatever the decision was for this driver, it has to have produced a
        // real index — the per-driver reasoning above is only worth anything
        // if the branch it picks works.
        $indexes = collect(Schema::getIndexes('domain_claims'))
            ->filter(fn (array $index): bool => $index['columns'] === ['active_domain']);

        $this->assertCount(1, $indexes, 'No index over active_domain was created.');
        $this->assertTrue($indexes->first()['unique'] ?? false);
    }
}
