<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\DomainClaim;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Actions\VerifyDomainClaim;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * One active claim per domain, on whichever database is underneath.
 *
 * The concurrent side of this needs two connections to one server and so runs
 * on PostgreSQL only. The rule itself is not PostgreSQL's: the generated
 * column and its unique index are created on every driver, and this asserts
 * that on whichever one the suite is running against, so sqlite is not left
 * with the documentation of an invariant and none of the enforcement.
 */
class DomainClaimUniquenessTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTeamEnvironment($app);

        $features = $app->config->get('jetstream.features', []);

        $features[] = Features::domainAdmin();

        $app->config->set('jetstream.features', $features);

        Jetstream::useUserModel(User::class);
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

    protected function claimFor(User $user, string $domain = 'acme.com'): DomainClaim
    {
        return DomainClaim::forceCreate([
            'user_id' => $user->id,
            'domain' => $domain,
            'token' => 'token-'.$user->email.'-'.$domain,
        ]);
    }

    public function test_the_generated_column_exists_on_this_driver(): void
    {
        $this->assertTrue(Schema::hasColumn('domain_claims', 'active_domain'));
    }

    public function test_a_second_active_claim_for_a_domain_is_rejected(): void
    {
        $first = $this->claimFor($this->createUser('taylor@acme.com'));
        $second = $this->claimFor($this->createUser('adam@acme.com'));

        DB::table('domain_claims')->where('id', $first->getKey())
            ->update(['verified_at' => now(), 'superseded_at' => null]);

        $this->expectException(QueryException::class);

        DB::table('domain_claims')->where('id', $second->getKey())
            ->update(['verified_at' => now(), 'superseded_at' => null]);
    }

    public function test_superseded_and_unverified_claims_do_not_collide(): void
    {
        // Only the active one occupies the domain. Every other claim for it —
        // unverified, or superseded years ago — reads as NULL and any number
        // of them may coexist, which is what keeps the historic tree intact.
        $active = $this->claimFor($this->createUser('taylor@acme.com'));
        $superseded = $this->claimFor($this->createUser('adam@acme.com'));
        $unverified = $this->claimFor($this->createUser('jess@acme.com'));

        DB::table('domain_claims')->where('id', $active->getKey())
            ->update(['verified_at' => now(), 'superseded_at' => null]);

        DB::table('domain_claims')->where('id', $superseded->getKey())
            ->update(['verified_at' => now()->subDay(), 'superseded_at' => now()]);

        $this->assertSame(3, DB::table('domain_claims')->where('domain', 'acme.com')->count());
        $this->assertSame(1, DB::table('domain_claims')->whereNotNull('active_domain')->count());
        $this->assertNull($unverified->fresh()->active_domain);
    }

    public function test_the_column_tracks_the_timestamps_rather_than_being_written(): void
    {
        // Generated, so it cannot drift: superseding a claim frees the domain
        // without anyone having to remember to clear a second column.
        $claim = $this->claimFor($this->createUser('taylor@acme.com'));

        DB::table('domain_claims')->where('id', $claim->getKey())
            ->update(['verified_at' => now(), 'superseded_at' => null]);

        $this->assertSame('acme.com', $claim->fresh()->active_domain);

        DB::table('domain_claims')->where('id', $claim->getKey())->update(['superseded_at' => now()]);

        $this->assertNull($claim->fresh()->active_domain);
    }

    public function test_ordinary_supersession_still_works(): void
    {
        $first = $this->claimFor($this->createUser('taylor@acme.com'));
        $second = $this->claimFor($this->createUser('adam@acme.com'));

        app(VerifyDomainClaim::class)->activate($first, 'dns');

        $this->assertTrue($first->fresh()->isActive());

        app(VerifyDomainClaim::class)->activate($second, 'dns');

        $this->assertFalse($first->fresh()->isActive());
        $this->assertTrue($second->fresh()->isActive());
        $this->assertNotNull($first->fresh()->superseded_at);
    }

    public function test_a_claim_can_be_reactivated_after_being_superseded(): void
    {
        $first = $this->claimFor($this->createUser('taylor@acme.com'));
        $second = $this->claimFor($this->createUser('adam@acme.com'));

        app(VerifyDomainClaim::class)->activate($first, 'dns');
        app(VerifyDomainClaim::class)->activate($second, 'dns');
        app(VerifyDomainClaim::class)->activate($first, 'dns');

        $this->assertTrue($first->fresh()->isActive());
        $this->assertFalse($second->fresh()->isActive());
    }
}
