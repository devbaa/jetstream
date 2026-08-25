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
 * One pending invitation per person per destination.
 *
 * An invitation names a destination: a customer account to join, or — when it
 * carries no account — a new account to be created for the invitee when they
 * accept. Two rows naming the same tenant, the same email address and the same
 * destination are not two invitations; they are one invitation stored twice.
 *
 * The table's unique index spanned (tenant_id, customer_account_id, email),
 * which leaves the "become a new customer" destination unconstrained: NULL is
 * distinct from NULL in a unique index on PostgreSQL, MySQL and sqlite, so
 * that column never separated one such invitation from another.
 *
 * These tests state the rule against the database directly, without going
 * through the action that also checks it, because a rule only the application
 * keeps is not a rule the table has. The interleaving that produces the
 * duplicate is in CustomerInvitationRaceTest, which needs two connections.
 */
class CustomerInvitationUniquenessTest extends OrchestraTestCase
{
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
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
     * Insert an invitation row directly, the way any writer would.
     */
    protected function insertInvitation(Tenant $tenant, string $email, ?string $accountId = null): void
    {
        DB::table('customer_invitations')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->getKey(),
            'customer_account_id' => $accountId,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * How many pending invitations name this tenant, email and destination.
     */
    protected function pendingCount(Tenant $tenant, string $email, ?string $accountId = null): int
    {
        $query = DB::table('customer_invitations')
            ->where('tenant_id', $tenant->getKey())
            ->where('email', $email);

        $query = $accountId === null
            ? $query->whereNull('customer_account_id')
            : $query->where('customer_account_id', $accountId);

        return $query->count();
    }

    public function test_the_database_refuses_a_second_invitation_to_become_a_new_customer(): void
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $this->insertInvitation($tenant, 'jane@example.test');

        // Nothing here goes through the action: the rule has to hold against
        // any writer, or it is not a rule of the table.
        $this->expectException(QueryException::class);

        $this->insertInvitation($tenant, 'jane@example.test');
    }

    public function test_the_database_refuses_a_second_invitation_into_one_account(): void
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $account = (new CreateCustomerAccount)->create($tenant, $owner, ['name' => 'Jane Co']);

        $this->insertInvitation($tenant, 'jane@example.test', $account->getKey());

        $this->expectException(QueryException::class);

        $this->insertInvitation($tenant, 'jane@example.test', $account->getKey());
    }

    public function test_the_two_destinations_remain_different_invitations(): void
    {
        // Inviting someone to join an existing account and inviting them to
        // become a customer in their own right are different invitations, and
        // giving "no account" a value must not merge them.
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $account = (new CreateCustomerAccount)->create($tenant, $owner, ['name' => 'Jane Co']);

        $this->insertInvitation($tenant, 'jane@example.test');
        $this->insertInvitation($tenant, 'jane@example.test', $account->getKey());

        $this->assertSame(1, $this->pendingCount($tenant, 'jane@example.test'));
        $this->assertSame(1, $this->pendingCount($tenant, 'jane@example.test', $account->getKey()));
    }

    public function test_two_accounts_may_each_invite_the_same_person(): void
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $first = (new CreateCustomerAccount)->create($tenant, $owner, ['name' => 'First Co']);
        $second = (new CreateCustomerAccount)->create($tenant, $owner, ['name' => 'Second Co']);

        $this->insertInvitation($tenant, 'jane@example.test', $first->getKey());
        $this->insertInvitation($tenant, 'jane@example.test', $second->getKey());

        $this->assertSame(1, $this->pendingCount($tenant, 'jane@example.test', $first->getKey()));
        $this->assertSame(1, $this->pendingCount($tenant, 'jane@example.test', $second->getKey()));
    }

    public function test_two_tenants_may_each_invite_the_same_person_as_a_new_customer(): void
    {
        [$owner, $acme] = $this->createOwnerAndTenant();

        $globex = (new CreateTenant)->create($this->createUser('other@globex.test'), ['name' => 'Globex']);

        $this->insertInvitation($acme, 'jane@example.test');
        $this->insertInvitation($globex, 'jane@example.test');

        $this->assertSame(1, $this->pendingCount($acme, 'jane@example.test'));
        $this->assertSame(1, $this->pendingCount($globex, 'jane@example.test'));
    }

    public function test_a_new_invitation_is_accepted_once_the_first_is_taken_up(): void
    {
        // The rule is about pending invitations. Once one is gone — accepted
        // or cancelled — inviting the same person again is ordinary, and the
        // index must not become a permanent record of who was ever asked.
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $this->insertInvitation($tenant, 'jane@example.test');

        DB::table('customer_invitations')
            ->where('tenant_id', $tenant->getKey())
            ->where('email', 'jane@example.test')
            ->delete();

        $this->insertInvitation($tenant, 'jane@example.test');

        $this->assertSame(1, $this->pendingCount($tenant, 'jane@example.test'));
    }

    public function test_the_generated_key_follows_the_account_it_stands_for(): void
    {
        // The column is generated, not written, so no writer can set the
        // account and leave the key naming the previous destination.
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $account = (new CreateCustomerAccount)->create($tenant, $owner, ['name' => 'Jane Co']);

        $this->insertInvitation($tenant, 'jane@example.test');

        $this->assertSame('', DB::table('customer_invitations')->value('account_key'));

        DB::table('customer_invitations')->update(['customer_account_id' => $account->getKey()]);

        $this->assertSame((string) $account->getKey(), DB::table('customer_invitations')->value('account_key'));
    }

    public function test_the_action_still_reports_a_duplicate_as_a_validation_error(): void
    {
        // The database is what decides, but an operator inviting the same
        // person twice through the interface must still see a message rather
        // than a query exception.
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $invite = fn (): mixed => app(TenantContext::class)->runFor(
            $tenant, fn () => (new InviteCustomer)->invite($owner, $tenant, 'jane@example.test')
        );

        $invite();

        try {
            $invite();

            $this->fail('A second invitation to the same person was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $this->assertSame(1, $this->pendingCount($tenant, 'jane@example.test'));
    }

    public function test_a_duplicate_that_lands_after_the_check_also_reads_as_a_validation_error(): void
    {
        // The pre-insert check cannot decide a race, so the constraint has the
        // last word — and what a caller sees when it does must be the same
        // message, not a query exception. The duplicate is made to appear at
        // the one instant that matters: after the check, before the insert.
        //
        // On this connection that is not concurrency, and it does not claim to
        // be; CustomerInvitationRaceTest reaches the same instant from a second
        // connection. What it does establish is that the action converts the
        // violation on whichever driver the suite is running.
        [$owner, $tenant] = $this->createOwnerAndTenant();

        $duplicate = function () use ($tenant): void {
            $this->insertInvitation($tenant, 'jane@example.test');
        };

        // Disarmed after it fires rather than removed afterwards. Eloquent
        // boots a model class once per process, and flushEventListeners()
        // takes every listener with it — including the creating hook
        // BelongsToTenant registered to stamp tenant_id, which no later test
        // in the process would get back.
        CustomerInvitation::creating(function () use (&$duplicate): void {
            if ($duplicate !== null) {
                $insert = $duplicate;

                $duplicate = null;

                $insert();
            }
        });

        $this->expectException(ValidationException::class);

        // A savepoint of its own: a rejected statement aborts the surrounding
        // transaction on PostgreSQL, and the suite's per-test transaction
        // still has a rollback to perform.
        DB::transaction(fn () => app(TenantContext::class)->runFor(
            $tenant, fn () => (new InviteCustomer)->invite($owner, $tenant, 'jane@example.test')
        ));
    }
}
