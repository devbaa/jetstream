<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\DomainClaim;
use App\Models\Team;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Events\DomainClaimSuperseded;
use Laravel\Jetstream\Events\DomainClaimVerified;
use Laravel\Jetstream\Events\UserBlocked;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\Admin\UserManager;
use Laravel\Jetstream\Http\Livewire\DomainAdminManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\PasswordSetup;
use Laravel\Jetstream\Tests\Fixtures\FakeDomainVerifier;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

class DomainAdminTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTeamEnvironment($app);

        $features = $app->config->get('jetstream.features', []);

        $features[] = Features::domainAdmin();

        $app->config->set('jetstream.features', $features);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Jetstream::useUserModel(User::class);
        Jetstream::verifyDomainsUsing(FakeDomainVerifier::class);

        FakeDomainVerifier::$result = 'dns';
        FakeDomainVerifier::$checked = [];
    }

    protected function createUser(string $email = 'taylor@acme.com', bool $verified = true): User
    {
        return User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'secret',
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    protected function claimAndVerify(User $user, ?string $domain = null): DomainClaim
    {
        $this->actingAs($user);

        $component = Livewire::test(DomainAdminManager::class);

        if ($domain !== null) {
            $component->set('domainForm.domain', $domain);
        }

        $component->call('startClaim')->assertHasNoErrors();

        $claim = $user->domainClaims()->latest()->firstOrFail();

        $component->call('checkClaim', $claim->id)->assertHasNoErrors();

        return $claim->fresh();
    }

    public function test_the_domains_route_is_registered_when_the_feature_is_enabled(): void
    {
        $this->assertTrue(Route::has('domains.show'));
    }

    public function test_the_domains_controller_returns_the_domains_view(): void
    {
        $user = $this->createUser();

        $request = \Illuminate\Http\Request::create('/user/domains');
        $request->setUserResolver(fn () => $user);

        $view = (new \Laravel\Jetstream\Http\Controllers\Livewire\DomainAdminController)->show($request);

        $this->assertSame('domains.show', $view->name());
    }

    public function test_unverified_users_cannot_start_a_claim(): void
    {
        $user = $this->createUser('taylor@acme.com', verified: false);

        $this->actingAs($user);

        Livewire::test(DomainAdminManager::class)
            ->call('startClaim')
            ->assertStatus(403);

        $this->assertSame(0, DomainClaim::query()->count());
    }

    public function test_single_mode_always_claims_the_users_own_email_domain(): void
    {
        $user = $this->createUser('taylor@acme.com');

        $this->actingAs($user);

        Livewire::test(DomainAdminManager::class)
            ->set('domainForm.domain', 'other.com')
            ->call('startClaim')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        $claim = $user->domainClaims()->firstOrFail();

        $this->assertSame('acme.com', $claim->domain);
        $this->assertNotSame('', $claim->token);
        $this->assertFalse($claim->isVerified());
    }

    public function test_multi_mode_allows_claiming_other_domains(): void
    {
        config(['jetstream-options.domain-admin.multi-domain' => true]);

        $user = $this->createUser('taylor@acme.com');

        $this->actingAs($user);

        Livewire::test(DomainAdminManager::class)
            ->set('domainForm.domain', 'Other.COM')
            ->call('startClaim')
            ->assertHasNoErrors();

        $this->assertTrue($user->domainClaims()->where('domain', 'other.com')->exists());
    }

    public function test_invalid_domains_are_rejected_in_multi_mode(): void
    {
        config(['jetstream-options.domain-admin.multi-domain' => true]);

        $user = $this->createUser('taylor@acme.com');

        $this->actingAs($user);

        Livewire::test(DomainAdminManager::class)
            ->set('domainForm.domain', 'not a domain')
            ->call('startClaim')
            ->assertHasErrors(['domain']);

        $this->assertSame(0, DomainClaim::query()->count());
    }

    public function test_every_claimant_receives_their_own_unique_token(): void
    {
        $first = $this->createUser('taylor@acme.com');
        $second = $this->createUser('adam@acme.com');

        $this->actingAs($first);
        Livewire::test(DomainAdminManager::class)->call('startClaim')->assertHasNoErrors();

        $this->actingAs($second);
        Livewire::test(DomainAdminManager::class)->call('startClaim')->assertHasNoErrors();

        $tokens = DomainClaim::query()->where('domain', 'acme.com')->pluck('token');

        $this->assertCount(2, $tokens);
        $this->assertSame(2, $tokens->unique()->count());
    }

    public function test_starting_a_claim_twice_does_not_rotate_the_token(): void
    {
        $user = $this->createUser('taylor@acme.com');

        $this->actingAs($user);

        Livewire::test(DomainAdminManager::class)->call('startClaim')->assertHasNoErrors();

        $token = $user->domainClaims()->firstOrFail()->token;

        Livewire::test(DomainAdminManager::class)->call('startClaim')->assertHasNoErrors();

        $this->assertSame(1, $user->domainClaims()->count());
        $this->assertSame($token, $user->domainClaims()->firstOrFail()->token);
    }

    public function test_a_successful_verification_grants_the_domain_admin_flag(): void
    {
        Event::fake([DomainClaimVerified::class]);

        $user = $this->createUser('taylor@acme.com');

        $claim = $this->claimAndVerify($user);

        $this->assertTrue($claim->isActive());
        $this->assertSame('dns', $claim->method);
        $this->assertTrue($user->fresh()->isDomainAdminOf('acme.com'));

        Event::assertDispatched(DomainClaimVerified::class, fn ($event) => $event->claim->id === $claim->id);

        $this->assertTrue($claim->activities()->where('action', 'domain:verified')->exists());
    }

    public function test_a_failed_verification_does_not_grant_the_flag(): void
    {
        FakeDomainVerifier::$result = null;

        $user = $this->createUser('taylor@acme.com');

        $this->actingAs($user);

        Livewire::test(DomainAdminManager::class)->call('startClaim');

        $claim = $user->domainClaims()->firstOrFail();

        Livewire::test(DomainAdminManager::class)
            ->call('checkClaim', $claim->id)
            ->assertHasErrors(['verification']);

        $this->assertFalse($claim->fresh()->isVerified());
        $this->assertFalse($user->fresh()->isDomainAdminOf('acme.com'));
    }

    public function test_the_latest_successful_verification_supersedes_earlier_claims(): void
    {
        Event::fake([DomainClaimSuperseded::class]);

        $first = $this->createUser('taylor@acme.com');
        $second = $this->createUser('adam@acme.com');

        $firstClaim = $this->claimAndVerify($first);

        $this->assertTrue($first->fresh()->isDomainAdminOf('acme.com'));

        $secondClaim = $this->claimAndVerify($second);

        $firstClaim = $firstClaim->fresh();

        $this->assertTrue($secondClaim->isActive());
        $this->assertTrue($second->fresh()->isDomainAdminOf('acme.com'));

        $this->assertTrue($firstClaim->isVerified());
        $this->assertFalse($firstClaim->isActive());
        $this->assertNotNull($firstClaim->superseded_at);
        $this->assertFalse($first->fresh()->isDomainAdminOf('acme.com'));

        Event::assertDispatched(DomainClaimSuperseded::class, fn ($event) => $event->claim->id === $firstClaim->id);

        // The superseded claim's activity survives as a historic tree...
        $this->assertTrue($firstClaim->activities()->where('action', 'domain:verified')->exists());
    }

    public function test_a_superseded_admin_can_reverify_to_take_the_flag_back(): void
    {
        $first = $this->createUser('taylor@acme.com');
        $second = $this->createUser('adam@acme.com');

        $firstClaim = $this->claimAndVerify($first);
        $this->claimAndVerify($second);

        $this->actingAs($first);

        Livewire::test(DomainAdminManager::class)
            ->call('checkClaim', $firstClaim->id)
            ->assertHasNoErrors();

        $this->assertTrue($firstClaim->fresh()->isActive());
        $this->assertFalse($second->fresh()->isDomainAdminOf('acme.com'));
    }

    public function test_domain_admins_can_block_and_unblock_members_of_their_domain(): void
    {
        Event::fake([UserBlocked::class]);

        $admin = $this->createUser('taylor@acme.com');
        $member = $this->createUser('adam@acme.com');

        $claim = $this->claimAndVerify($admin);

        Livewire::test(DomainAdminManager::class)
            ->call('manageClaim', $claim->id)
            ->assertSee('adam@acme.com')
            ->call('confirmMemberBlock', $member->id)
            ->set('blockReason', 'Compromised account')
            ->call('blockMember')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        $this->assertTrue($member->fresh()->isBlocked());
        Event::assertDispatched(UserBlocked::class);

        $activity = $claim->activities()->where('action', 'member:blocked')->firstOrFail();
        $this->assertSame($member->id, $activity->subject_id);
        $this->assertSame(['reason' => 'Compromised account'], $activity->details);

        Livewire::test(DomainAdminManager::class)
            ->call('manageClaim', $claim->id)
            ->call('unblockMember', $member->id);

        $this->assertFalse($member->fresh()->isBlocked());
        $this->assertTrue($claim->activities()->where('action', 'member:unblocked')->exists());
    }

    public function test_unverified_members_are_invisible_and_unmanageable(): void
    {
        $admin = $this->createUser('taylor@acme.com');
        $member = $this->createUser('adam@acme.com', verified: false);

        $claim = $this->claimAndVerify($admin);

        Livewire::test(DomainAdminManager::class)
            ->call('manageClaim', $claim->id)
            ->assertDontSee('adam@acme.com')
            ->call('unblockMember', $member->id)
            ->assertStatus(403);
    }

    public function test_users_of_other_domains_cannot_be_managed(): void
    {
        $admin = $this->createUser('taylor@acme.com');
        $outsider = $this->createUser('jane@other.com');

        $claim = $this->claimAndVerify($admin);

        Livewire::test(DomainAdminManager::class)
            ->call('manageClaim', $claim->id)
            ->call('unblockMember', $outsider->id)
            ->assertStatus(403);
    }

    public function test_system_admins_cannot_be_managed_by_domain_admins(): void
    {
        $admin = $this->createUser('taylor@acme.com');

        $systemAdmin = $this->createUser('root@acme.com');
        $systemAdmin->forceFill(['is_system_admin' => true])->save();

        $claim = $this->claimAndVerify($admin);

        Livewire::test(DomainAdminManager::class)
            ->call('manageClaim', $claim->id)
            ->assertDontSee('root@acme.com')
            ->call('unblockMember', $systemAdmin->id)
            ->assertStatus(403);
    }

    public function test_superseded_admins_can_no_longer_manage_the_domain(): void
    {
        $first = $this->createUser('taylor@acme.com');
        $second = $this->createUser('adam@acme.com');

        $firstClaim = $this->claimAndVerify($first);
        $this->claimAndVerify($second);

        $this->actingAs($first);

        Livewire::test(DomainAdminManager::class)
            ->call('manageClaim', $firstClaim->id)
            ->assertStatus(403);
    }

    public function test_users_cannot_verify_claims_of_other_users(): void
    {
        $first = $this->createUser('taylor@acme.com');
        $second = $this->createUser('adam@acme.com');

        $this->actingAs($first);
        Livewire::test(DomainAdminManager::class)->call('startClaim');

        $claim = $first->domainClaims()->firstOrFail();

        $this->actingAs($second);

        Livewire::test(DomainAdminManager::class)
            ->call('checkClaim', $claim->id)
            ->assertStatus(404);
    }

    protected function createTeamFor(User $user): Team
    {
        return Team::forceCreate([
            'user_id' => $user->id,
            'name' => explode('@', $user->email, 2)[0]."'s Team",
            'personal_team' => true,
        ]);
    }

    public function test_verifying_a_claim_enrolls_existing_domain_users_into_the_masters_team(): void
    {
        $master = $this->createUser('taylor@acme.com');
        $team = $this->createTeamFor($master);

        $member = $this->createUser('adam@acme.com');
        $unverified = $this->createUser('ghost@acme.com', verified: false);
        $outsider = $this->createUser('jane@other.com');

        $systemAdmin = $this->createUser('root@acme.com');
        $systemAdmin->forceFill(['is_system_admin' => true])->save();

        $claim = $this->claimAndVerify($master);

        $team = $team->fresh();

        $this->assertTrue($team->hasUser($member->fresh()));
        $this->assertFalse($team->hasUser($unverified->fresh()));
        $this->assertFalse($team->hasUser($outsider->fresh()));
        $this->assertFalse($team->hasUser($systemAdmin->fresh()));

        $activity = $claim->activities()->where('action', 'member:added-to-team')->firstOrFail();
        $this->assertSame($member->id, $activity->subject_id);
        $this->assertSame(['team_id' => $team->id], $activity->details);
    }

    public function test_users_are_enrolled_into_the_masters_team_when_they_verify_their_email(): void
    {
        $master = $this->createUser('taylor@acme.com');
        $team = $this->createTeamFor($master);

        $this->claimAndVerify($master);

        $late = $this->createUser('late@acme.com', verified: false);

        $this->assertFalse($team->fresh()->hasUser($late));

        $late->forceFill(['email_verified_at' => now()])->save();

        event(new Verified($late));

        $this->assertTrue($team->fresh()->hasUser($late->fresh()));
    }

    public function test_enrollment_is_idempotent(): void
    {
        $master = $this->createUser('taylor@acme.com');
        $team = $this->createTeamFor($master);

        $member = $this->createUser('adam@acme.com');

        $this->claimAndVerify($master);

        event(new Verified($member->fresh()));

        $this->assertSame(1, DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $member->id)
            ->count());
    }

    public function test_the_cli_creates_a_verified_user_with_the_given_password(): void
    {
        Mail::fake();

        $this->artisan('jetstream:create-user', [
            'email' => 'new@acme.com',
            '--name' => 'New User',
            '--password' => 'super-secret-password',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'new@acme.com')->firstOrFail();

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('super-secret-password', $user->password));
        $this->assertNotNull($user->personalTeam());

        Mail::assertNothingSent();
    }

    public function test_the_cli_sends_a_password_setup_link_when_no_password_is_given(): void
    {
        Mail::fake();

        $this->artisan('jetstream:create-user', ['email' => 'new@acme.com'])->assertSuccessful();

        $user = User::query()->where('email', 'new@acme.com')->firstOrFail();

        Mail::assertSent(PasswordSetup::class, fn (PasswordSetup $mail): bool => $mail->user->id === $user->id);
    }

    public function test_the_password_setup_link_can_be_skipped(): void
    {
        Mail::fake();

        $this->artisan('jetstream:create-user', [
            'email' => 'new@acme.com',
            '--skip-reset-mail' => true,
        ])->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'new@acme.com')->exists());

        Mail::assertNothingSent();
    }

    public function test_the_cli_can_create_a_domain_master_superseding_the_previous_one(): void
    {
        Mail::fake();

        $previous = $this->createUser('taylor@acme.com');
        $previousClaim = $this->claimAndVerify($previous);

        $member = $this->createUser('adam@acme.com');

        $this->artisan('jetstream:create-user', [
            'email' => 'boss@acme.com',
            '--master' => true,
            '--skip-reset-mail' => true,
        ])->assertSuccessful();

        $boss = User::query()->where('email', 'boss@acme.com')->firstOrFail();

        $this->assertTrue($boss->isDomainAdminOf('acme.com'));
        $this->assertSame('admin', $boss->activeDomainClaims()->firstOrFail()->method);

        $this->assertFalse($previousClaim->fresh()->isActive());
        $this->assertFalse($previous->fresh()->isDomainAdminOf('acme.com'));

        // Existing domain users were enrolled into the new master's team...
        $this->assertTrue($boss->personalTeam()->fresh()->hasUser($member->fresh()));
    }

    public function test_additional_master_domains_require_multi_domain_mode(): void
    {
        Mail::fake();

        $this->artisan('jetstream:create-user', [
            'email' => 'boss@acme.com',
            '--master-domain' => ['other.com'],
            '--skip-reset-mail' => true,
        ])->assertFailed();

        $this->assertFalse(User::query()->where('email', 'boss@acme.com')->exists());

        config(['jetstream-options.domain-admin.multi-domain' => true]);

        $this->artisan('jetstream:create-user', [
            'email' => 'boss@acme.com',
            '--master' => true,
            '--master-domain' => ['other.com'],
            '--skip-reset-mail' => true,
        ])->assertSuccessful();

        $boss = User::query()->where('email', 'boss@acme.com')->firstOrFail();

        $this->assertTrue($boss->isDomainAdminOf('acme.com'));
        $this->assertTrue($boss->isDomainAdminOf('other.com'));
    }

    public function test_duplicate_emails_are_rejected(): void
    {
        $this->createUser('taylor@acme.com');

        $this->artisan('jetstream:create-user', [
            'email' => 'taylor@acme.com',
            '--skip-reset-mail' => true,
        ])->assertFailed();
    }

    public function test_system_admins_can_create_a_domain_master_from_the_admin_screen(): void
    {
        Mail::fake();

        $admin = $this->createUser('root@example.org');
        $admin->forceFill(['is_system_admin' => true])->save();

        $member = $this->createUser('adam@acme.com');

        $this->actingAs($admin);

        Livewire::test(UserManager::class)
            ->call('createUser')
            ->set('createUserForm.name', 'Boss')
            ->set('createUserForm.email', 'boss@acme.com')
            ->set('createUserForm.domain_master', true)
            ->set('createUserForm.send_reset_mail', false)
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        $boss = User::query()->where('email', 'boss@acme.com')->firstOrFail();

        $this->assertTrue($boss->isDomainAdminOf('acme.com'));
        $this->assertSame('admin', $boss->activeDomainClaims()->firstOrFail()->method);
        $this->assertTrue($boss->personalTeam()->fresh()->hasUser($member->fresh()));

        Mail::assertNothingSent();
    }
}
