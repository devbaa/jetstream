<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\DomainClaim;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Actions\VerifyDomainClaim;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * A domain has at most one active claim, however the activations interleave.
 *
 * The documented model is that "only the claim whose verification succeeded
 * most recently holds the domain admin flag". Two people verifying the same
 * domain at the same moment are two transactions racing for that flag, and a
 * check-then-write cannot decide it: whichever ordering the database chooses,
 * exactly one of them must end up holding it.
 *
 * Concurrency is exercised with two real connections to one PostgreSQL
 * database. sqlite cannot take part — a second connection to ":memory:" is a
 * different database, and it has no row locks to observe — so those tests skip
 * there rather than pretending a sequential run proves anything.
 */
class DomainClaimActivationRaceTest extends OrchestraTestCase
{
    /**
     * The name of the second, independent connection to the same database.
     */
    protected const OTHER = 'jetstream_competitor';

    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTeamEnvironment($app);

        $features = $app->config->get('jetstream.features', []);

        $features[] = Features::domainAdmin();

        $app->config->set('jetstream.features', $features);

        Jetstream::useUserModel(User::class);

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

        // The lazy refresh migrates on first access; do it before a second
        // connection looks at the schema.
        DB::table('users')->count();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent activation needs one database reachable from two connections.');
        }

        // The suite wraps each test in a transaction on the default
        // connection, which a second connection cannot see. These tests
        // commit their own fixtures and clean up after themselves instead.
        DB::rollBack();

        DB::table('domain_claims')->delete();
        DB::table('users')->delete();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::table('domain_claims')->delete();
            DB::table('users')->delete();

            DB::purge(static::OTHER);

            DB::beginTransaction();
        }

        parent::tearDown();
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
     * An unverified claim on the given domain.
     */
    protected function claimFor(User $user, string $domain = 'acme.com'): DomainClaim
    {
        return DomainClaim::forceCreate([
            'user_id' => $user->id,
            'domain' => $domain,
            'token' => 'token-'.$user->email.'-'.$domain,
        ]);
    }

    /**
     * How many claims currently hold the flag for the domain.
     */
    protected function activeCount(string $domain = 'acme.com'): int
    {
        return DB::table('domain_claims')
            ->where('domain', $domain)
            ->whereNotNull('verified_at')
            ->whereNull('superseded_at')
            ->count();
    }

    public function test_two_simultaneous_first_activations_cannot_both_hold_the_flag(): void
    {
        $first = $this->claimFor($this->createUser('taylor@acme.com'));
        $second = $this->claimFor($this->createUser('adam@acme.com'));

        // The competitor opens its transaction and reads the domain the way
        // an activation does, before anyone has been verified. This is the
        // moment the old code took no locks at all, because no claim for the
        // domain was active yet and so nothing matched.
        $other = DB::connection(static::OTHER);

        $other->beginTransaction();

        $other->table('domain_claims')
            ->where('domain', 'acme.com')
            ->where('id', '!=', $second->getKey())
            ->whereNotNull('verified_at')
            ->whereNull('superseded_at')
            ->lockForUpdate()
            ->get();

        // Meanwhile the first claim is verified and committed in full.
        app(VerifyDomainClaim::class)->activate($first, 'dns');

        // The competitor now finishes its own activation on the state it read.
        $failed = null;

        try {
            $other->table('domain_claims')->where('id', $second->getKey())->update([
                'verified_at' => now(),
                'superseded_at' => null,
                'method' => 'dns',
            ]);

            $other->commit();
        } catch (\Throwable $e) {
            $failed = $e;

            $other->rollBack();
        }

        $this->assertSame(
            1,
            $this->activeCount(),
            'Two concurrent first-time activations both took the domain admin flag.'
        );

        $this->assertNotNull($failed, 'The database accepted a second active claim for the domain.');
    }

    public function test_an_activation_waits_for_a_competing_one_to_finish(): void
    {
        $first = $this->claimFor($this->createUser('taylor@acme.com'));
        $second = $this->claimFor($this->createUser('adam@acme.com'));

        // The competitor holds only its OWN claim row — not the one being
        // activated. Locking the row you are about to write is no evidence of
        // anything; what has to be true is that activating one claim contends
        // with every other claim for the same domain, active or not.
        $other = DB::connection(static::OTHER);

        $other->beginTransaction();
        $other->table('domain_claims')->where('id', $second->getKey())->lockForUpdate()->get();

        DB::statement("set lock_timeout = '500ms'");

        $waited = false;

        try {
            app(VerifyDomainClaim::class)->activate($first, 'dns');
        } catch (\Throwable $e) {
            $waited = str_contains($e->getMessage(), 'lock timeout');
        } finally {
            DB::statement('set lock_timeout = 0');

            $other->rollBack();
        }

        $this->assertTrue($waited, 'The activation did not wait for the transaction holding the domain.');
    }

    public function test_the_database_refuses_a_second_active_claim_outright(): void
    {
        // Nothing here goes through the action: the invariant has to hold
        // against any writer, or it is not an invariant.
        $first = $this->claimFor($this->createUser('taylor@acme.com'));
        $second = $this->claimFor($this->createUser('adam@acme.com'));

        DB::table('domain_claims')->where('id', $first->getKey())->update([
            'verified_at' => now(), 'superseded_at' => null,
        ]);

        // Only the timestamps are written, exactly as any other writer would.
        // The constraint stands on the condition itself, so there is nothing
        // to forget to set and nothing to evade it with.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('domain_claims')->where('id', $second->getKey())->update([
            'verified_at' => now(), 'superseded_at' => null,
        ]);
    }

    public function test_different_domains_do_not_block_one_another(): void
    {
        $taylor = $this->createUser('taylor@acme.com');
        $adam = $this->createUser('adam@example.com');

        $acme = $this->claimFor($taylor, 'acme.com');
        $example = $this->claimFor($adam, 'example.com');

        $other = DB::connection(static::OTHER);

        $other->beginTransaction();
        $other->table('domain_claims')->where('domain', 'example.com')->lockForUpdate()->get();

        DB::statement("set lock_timeout = '500ms'");

        try {
            // Holding example.com must not delay acme.com.
            app(VerifyDomainClaim::class)->activate($acme, 'dns');
        } finally {
            DB::statement('set lock_timeout = 0');

            $other->rollBack();
        }

        $this->assertTrue($acme->fresh()->isActive());
        $this->assertFalse($example->fresh()->isActive());
    }
}
