<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateCustomerAccount;
use App\Actions\Jetstream\CreateTenant;
use App\Actions\Jetstream\InviteCustomer;
use App\Models\CustomerAccount;
use App\Models\CustomerInvitation;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\CustomerAccountPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * Two people inviting the same person at the same moment produce one invitation.
 *
 * The action looks for an existing invitation before it inserts one, which
 * decides nothing when two requests read before either writes: both see none,
 * and both insert. Nothing about that is exotic — two staff members clearing
 * the same signup queue is enough.
 *
 * The interleaving is exercised with two real connections to one PostgreSQL
 * database. sqlite cannot take part — a second connection to ":memory:" is a
 * different database — so these tests skip there rather than pretending a
 * sequential run proves anything.
 */
class CustomerInvitationRaceTest extends OrchestraTestCase
{
    /**
     * The name of the second, independent connection to the same database.
     */
    protected const OTHER = 'jetstream_competitor';

    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(CustomerAccount::class, CustomerAccountPolicy::class);
        Jetstream::useUserModel(User::class);
        Jetstream::createCustomerAccountsUsing(CreateCustomerAccount::class);

        // A second connection to whatever database the suite is running
        // against, so two transactions can be held open at once.
        $app->config->set(
            'database.connections.'.static::OTHER,
            $app->config->get('database.connections.'.$app->config->get('database.default'))
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // The lazy refresh migrates on first access; do it before a second
        // connection looks at the schema.
        DB::table('users')->count();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('A concurrent invitation needs one database reachable from two connections.');
        }

        // The suite wraps each test in a transaction on the default
        // connection, which a second connection cannot see. These tests commit
        // their own fixtures and clean up after themselves instead.
        DB::rollBack();

        $this->wipe();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->wipe();

            DB::purge(static::OTHER);

            DB::beginTransaction();
        }

        parent::tearDown();
    }

    protected function wipe(): void
    {
        DB::table('customer_invitations')->delete();
        DB::table('customer_account_user')->delete();
        DB::table('customer_accounts')->delete();
        DB::table('tenant_user')->delete();
        DB::table('roles')->delete();
        DB::table('tenants')->delete();
        DB::table('users')->delete();
    }

    protected function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * A tenant and the staff member who owns it.
     *
     * @return array{\Laravel\Jetstream\Tests\Fixtures\User, \App\Models\Tenant}
     */
    protected function createOwnerAndTenant(): array
    {
        $owner = $this->createUser('owner@acme.test');

        return [$owner, (new CreateTenant)->create($owner, ['name' => 'Acme'])];
    }

    /**
     * Insert an invitation row the way a request would, on a given connection.
     */
    protected function insertInvitation(string $connection, Tenant $tenant, string $email, ?string $accountId = null): void
    {
        DB::connection($connection)->table('customer_invitations')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->getKey(),
            'customer_account_id' => $accountId,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * How many pending invitations name this tenant and email.
     */
    protected function pendingCount(Tenant $tenant, string $email): int
    {
        return DB::table('customer_invitations')
            ->where('tenant_id', $tenant->getKey())
            ->where('email', $email)
            ->whereNull('customer_account_id')
            ->count();
    }

    public function test_two_simultaneous_invitations_to_become_a_new_customer_cannot_both_persist(): void
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $other = DB::connection(static::OTHER);

        // Both requests look for an existing invitation before either has
        // written one. This is the whole of the action's duplicate check, and
        // it is true for both of them at this instant.
        $this->assertSame(0, $this->pendingCount($tenant, 'jane@example.test'));

        $other->beginTransaction();

        $this->assertSame(
            0,
            $other->table('customer_invitations')
                ->where('tenant_id', $tenant->getKey())
                ->where('email', 'jane@example.test')
                ->whereNull('customer_account_id')
                ->count()
        );

        // The first request inserts and commits in full.
        $this->insertInvitation(static::OTHER, $tenant, 'jane@example.test');

        $other->commit();

        // The second request now finishes on the state it read.
        $failed = null;

        try {
            $this->insertInvitation(DB::getDefaultConnection(), $tenant, 'jane@example.test');
        } catch (QueryException $e) {
            $failed = $e;
        }

        $this->assertNotNull($failed, 'The database accepted a second pending invitation for the same person.');

        $this->assertSame(
            1,
            $this->pendingCount($tenant, 'jane@example.test'),
            'Accepting both would have given one person two customer accounts in one tenant.'
        );
    }

    public function test_an_invitation_waits_for_a_competing_one_to_finish(): void
    {
        // Contention, not just rejection: while the first request's
        // transaction is open, the second must wait on the index rather than
        // read "none exist" and carry on to its own insert.
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $other = DB::connection(static::OTHER);

        $other->beginTransaction();

        $this->insertInvitation(static::OTHER, $tenant, 'jane@example.test');

        DB::statement("set lock_timeout = '500ms'");

        $waited = false;

        try {
            $this->insertInvitation(DB::getDefaultConnection(), $tenant, 'jane@example.test');
        } catch (QueryException $e) {
            $waited = str_contains($e->getMessage(), 'lock timeout');
        } finally {
            DB::statement('set lock_timeout = 0');

            $other->rollBack();
        }

        $this->assertTrue($waited, 'The second invitation did not wait for the uncommitted first one.');
    }

    public function test_losing_the_race_inside_the_action_reads_as_a_validation_error(): void
    {
        // The exact interleaving, through the action: the duplicate lands and
        // commits after this request has run its check and before it inserts.
        // A model event is the only place that instant can be reached from.
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $competitor = function () use ($tenant): void {
            $other = DB::connection(static::OTHER);

            $other->beginTransaction();

            $this->insertInvitation(static::OTHER, $tenant, 'jane@example.test');

            $other->commit();
        };

        CustomerInvitation::creating(function () use (&$competitor): void {
            if ($competitor !== null) {
                $race = $competitor;

                // Once: the retry-free path under test inserts exactly once,
                // and a second firing would mean the action had swallowed the
                // failure and tried again.
                $competitor = null;

                $race();
            }
        });

        try {
            app(TenantContext::class)->runFor(
                $tenant, fn () => (new InviteCustomer)->invite($owner, $tenant, 'jane@example.test')
            );

            $this->fail('The invitation that lost the race was accepted anyway.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        } finally {
            CustomerInvitation::flushEventListeners();
        }

        $this->assertSame(
            1,
            $this->pendingCount($tenant, 'jane@example.test'),
            'The losing request left a second invitation behind.'
        );
    }
}
