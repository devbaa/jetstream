<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Jetstream;
use PHPUnit\Framework\Attributes\DataProvider;
use Laravel\Jetstream\Tests\Fixtures\Invoice;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * Auditable is advertised for any Eloquent model, so any key type must store.
 *
 * The README says to drop the trait "onto **any** Eloquent model" and shows an
 * Invoice extending Model — which in a stock Laravel application has an
 * auto-incrementing bigint key. The trait's own docblock says the same. The
 * column those keys land in was declared with nullableUuidMorphs.
 *
 * sqlite will store an integer in that column without complaining, so this is
 * one of the cases where a green sqlite suite says nothing at all. PostgreSQL
 * rejects it.
 */
class AuditableKeyTypeTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTenantEnvironment($app);

        Jetstream::useUserModel(User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::hasTable('migrations');

        // A table of the shape a normal Laravel application gives a model:
        // auto-incrementing key, nothing Jetstream-specific about it.
        Schema::dropIfExists('invoices');

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('invoices');

        parent::tearDown();
    }

    public function test_a_uuid_keyed_model_is_audited(): void
    {
        // The models this package ships. These were always fine.
        $tenant = Tenant::forceCreate([
            'name' => 'Acme',
            'slug' => 'acme',
            'user_id' => User::forceCreate([
                'name' => 'Taylor',
                'email' => 'taylor@laravel.com',
                'password' => 'secret',
            ])->id,
        ]);

        $log = $tenant->auditLogs()->firstOrFail();

        $this->assertSame('created', $log->event);
        $this->assertSame((string) $tenant->getKey(), (string) $log->auditable_id);
    }

    public function test_a_conventional_bigint_keyed_model_is_audited(): void
    {
        // The documented case: any Eloquent model.
        $invoice = Invoice::create(['reference' => 'INV-001']);

        $this->assertIsInt($invoice->getKey());

        $log = $invoice->auditLogs()->firstOrFail();

        $this->assertSame('created', $log->event);
        $this->assertSame((string) $invoice->getKey(), (string) $log->auditable_id);
    }

    public function test_the_audit_trail_of_a_bigint_model_survives_updates_and_deletes(): void
    {
        $invoice = Invoice::create(['reference' => 'INV-001']);

        $invoice->update(['reference' => 'INV-002']);
        $invoice->delete();

        $this->assertSame(
            ['created', 'updated', 'deleted'],
            $invoice->auditLogs()->orderBy('created_at')->pluck('event')->all()
        );
    }

    public function test_the_morph_lookup_is_still_indexed(): void
    {
        // Widening the column must not cost the index the morph relation
        // reads through; an audit table is append-only and grows forever.
        $indexes = collect(Schema::getIndexes('audit_logs'))
            ->filter(fn (array $index): bool => $index['columns'] === ['auditable_type', 'auditable_id']);

        $this->assertCount(1, $indexes, 'The auditable morph index is missing.');
    }

    public function test_an_existing_uuid_column_is_converted_without_losing_history(): void
    {
        // The upgrade case. A database installed before this change has
        // auditable_id typed as the driver's UUID column with real audit
        // history in it; the suite always migrates fresh, so that shape has
        // to be rebuilt here to be tested at all.
        $migration = require __DIR__.'/../database/migrations/2026_08_26_100000_widen_auditable_id_to_any_key_type.php';

        $migration->down();

        $existing = '01a03a00-0000-7000-8000-000000000002';

        DB::table('audit_logs')->insert([
            'id' => '01a03a00-0000-7000-8000-000000000001',
            'event' => 'created',
            'auditable_type' => 'App\\Models\\User',
            'auditable_id' => $existing,
        ]);

        $migration->up();

        // The history that was there is still there...
        $this->assertSame($existing, DB::table('audit_logs')->value('auditable_id'));

        // ...and the column now takes what it could not before.
        $invoice = Invoice::create(['reference' => 'INV-001']);

        $this->assertSame((string) $invoice->getKey(), (string) $invoice->auditLogs()->firstOrFail()->auditable_id);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function indexRebuildProvider(): array
    {
        return [
            // These alter the column in place and rebuild the index
            // themselves. Their live paths are exercised by the tests above,
            // so taking the index off first would be work for nothing.
            'pgsql' => ['pgsql', false],
            'sqlite' => ['sqlite', false],
            'mysql' => ['mysql', false],
            'mariadb' => ['mariadb', false],

            // SQL Server will not alter the type of a column an index covers
            // at all. Its one exception is widening a character column, and
            // uniqueidentifier to nvarchar is not that, so an existing
            // installation would be refused outright.
            'sqlsrv' => ['sqlsrv', true],
        ];
    }

    #[DataProvider('indexRebuildProvider')]
    public function test_only_the_driver_that_locks_indexed_columns_rebuilds(string $driver, bool $rebuild): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_08_26_100000_widen_auditable_id_to_any_key_type.php';

        $this->assertSame($rebuild, $migration->needsIndexRebuildForTypeChange($driver));
    }

    public function test_the_two_key_types_do_not_collide(): void
    {
        // Distinct morph types, so an integer key and a UUID key can coexist
        // in one column without one being mistaken for the other.
        $user = User::forceCreate([
            'name' => 'Taylor',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $invoice = Invoice::create(['reference' => 'INV-001']);

        $this->assertSame(1, $user->auditLogs()->count());
        $this->assertSame(1, $invoice->auditLogs()->count());

        $this->assertNotSame(
            $user->auditLogs()->first()?->auditable_type,
            $invoice->auditLogs()->first()?->auditable_type
        );
    }
}
