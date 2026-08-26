<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\Role;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole;
use Laravel\Jetstream\Actions\UpdateTenantStaffRole;
use Laravel\Jetstream\Events\TeamMemberUpdated;
use Laravel\Jetstream\Events\TenantStaffUpdated;
use Laravel\Jetstream\Http\Livewire\TenantStaffManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\TeamPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

/**
 * A role update names a membership, and only a real one can be changed.
 *
 * Both actions took an id and handed it to updateExistingPivot(), which writes
 * nothing when there is no such membership and reports no error — and the
 * action went on to announce TenantStaffUpdated or TeamMemberUpdated for it
 * anyway. Its return value could not have been consulted instead: for these
 * relations it reports the same thing for a membership that does not exist as
 * for one that already holds the role being set.
 *
 * Two things follow. The caller is told the change succeeded when the database
 * was never touched, and anything listening — an audit trail, a notification,
 * a downstream sync — is told authoritatively that a role changed for someone
 * who has no role here to change.
 *
 * The events are the package's extension points, so nothing in the package
 * listens to them; the damage is entirely in what an application is told.
 */
class RoleUpdateMembershipTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Jetstream::useUserModel(User::class);
    }

    protected function createUser(string $email): User
    {
        return User::forceCreate([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    /**
     * A tenant with two role keys of its own, and the staff member who owns it.
     *
     * @return array{\Laravel\Jetstream\Tests\Fixtures\User, \App\Models\Tenant}
     */
    protected function tenantWithRoles(): array
    {
        $owner = $this->createUser('owner@acme.test');

        $tenant = Tenant::forceCreate(['name' => 'Acme', 'slug' => 'acme', 'user_id' => $owner->id]);

        foreach (['admin', 'editor'] as $key) {
            Role::forceCreate([
                'tenant_id' => $tenant->getKey(),
                'key' => $key,
                'name' => ucfirst($key),
                'permissions' => ['read'],
            ]);
        }

        app(RoleRegistry::class)->flush();

        $tenant->users()->attach($owner, ['role' => 'admin']);

        return [$owner, $tenant];
    }

    /**
     * A team of that tenant, and its owner's membership.
     */
    protected function teamFor(Tenant $tenant, User $owner): Team
    {
        $team = Team::forceCreate([
            'name' => 'Acme Team',
            'user_id' => $owner->id,
            'tenant_id' => $tenant->getKey(),
            'personal_team' => false,
        ]);

        $team->users()->attach($owner, ['role' => 'admin']);

        return $team;
    }

    /**
     * The role recorded for a user against a tenant, if any.
     */
    protected function tenantRole(Tenant $tenant, User $user): ?string
    {
        $role = DB::table('tenant_user')
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');

        return is_string($role) ? $role : null;
    }

    /**
     * The role recorded for a user against a team, if any.
     */
    protected function teamRole(Team $team, User $user): ?string
    {
        $role = DB::table('team_user')
            ->where('team_id', $team->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');

        return is_string($role) ? $role : null;
    }

    public function test_a_tenant_role_cannot_be_updated_for_someone_who_is_not_staff(): void
    {
        [$owner, $tenant] = $this->tenantWithRoles();

        // A real user, with a real account, who simply has no membership here.
        // Not a made-up id: the action already refuses those, because it looks
        // the user up to announce the change.
        $stranger = $this->createUser('stranger@example.test');

        Event::fake([TenantStaffUpdated::class]);

        try {
            (new UpdateTenantStaffRole)->update($owner, $tenant, $stranger->getKey(), 'editor');

            $this->fail('A role was updated for someone with no membership.');
        } catch (ModelNotFoundException) {
            // The membership named does not exist, which is what this reports.
        }

        Event::assertNotDispatched(TenantStaffUpdated::class);

        $this->assertNull($this->tenantRole($tenant, $stranger), 'A membership was created out of a role update.');
    }

    public function test_a_team_role_cannot_be_updated_for_someone_who_is_not_a_member(): void
    {
        [$owner, $tenant] = $this->tenantWithRoles();

        $team = $this->teamFor($tenant, $owner);

        $stranger = $this->createUser('stranger@example.test');

        Event::fake([TeamMemberUpdated::class]);

        try {
            (new UpdateTeamMemberRole)->update($owner, $team, $stranger->getKey(), 'editor');

            $this->fail('A role was updated for someone with no membership.');
        } catch (ModelNotFoundException) {
            // As above.
        }

        Event::assertNotDispatched(TeamMemberUpdated::class);

        $this->assertNull($this->teamRole($team, $stranger));
    }

    public function test_membership_of_another_tenant_is_not_membership_of_this_one(): void
    {
        // The nearer miss, and the one an interface could plausibly produce:
        // somebody who really is staff, of somewhere else.
        [$owner, $acme] = $this->tenantWithRoles();

        $globex = Tenant::forceCreate(['name' => 'Globex', 'slug' => 'globex', 'user_id' => $owner->id]);

        $elsewhere = $this->createUser('elsewhere@globex.test');

        $globex->users()->attach($elsewhere, ['role' => 'admin']);

        Event::fake([TenantStaffUpdated::class]);

        try {
            (new UpdateTenantStaffRole)->update($owner, $acme, $elsewhere->getKey(), 'editor');

            $this->fail('A role was updated across a tenant boundary.');
        } catch (ModelNotFoundException) {
            //
        }

        Event::assertNotDispatched(TenantStaffUpdated::class);

        $this->assertNull($this->tenantRole($acme, $elsewhere));

        // And the membership they do have is untouched.
        $this->assertSame('admin', $this->tenantRole($globex, $elsewhere));
    }

    public function test_a_real_staff_member_is_still_updated_and_announced(): void
    {
        [$owner, $tenant] = $this->tenantWithRoles();

        $staff = $this->createUser('staff@acme.test');

        $tenant->users()->attach($staff, ['role' => 'admin']);

        Event::fake([TenantStaffUpdated::class]);

        (new UpdateTenantStaffRole)->update($owner, $tenant, $staff->getKey(), 'editor');

        $this->assertSame('editor', $this->tenantRole($tenant, $staff));

        Event::assertDispatched(TenantStaffUpdated::class);
    }

    public function test_a_real_team_member_is_still_updated_and_announced(): void
    {
        [$owner, $tenant] = $this->tenantWithRoles();

        $team = $this->teamFor($tenant, $owner);

        $member = $this->createUser('member@acme.test');

        $tenant->users()->attach($member, ['role' => 'admin']);
        $team->users()->attach($member, ['role' => 'admin']);

        Event::fake([TeamMemberUpdated::class]);

        (new UpdateTeamMemberRole)->update($owner, $team, $member->getKey(), 'editor');

        $this->assertSame('editor', $this->teamRole($team, $member));

        Event::assertDispatched(TeamMemberUpdated::class);
    }

    public function test_the_staff_screen_refuses_a_role_change_for_a_non_member(): void
    {
        // Through the screen rather than the action, because that is what the
        // defect was reachable from. The role modal is opened by user id, and
        // manageRole() only looks the user up — it never asks whether they are
        // staff here — so an administrator with permission over this tenant
        // can name anyone at all and then save. The interface only ever lists
        // real staff, but the interface is not what decides this.
        [$owner, $tenant] = $this->tenantWithRoles();

        $stranger = $this->createUser('stranger@example.test');

        Event::fake([TenantStaffUpdated::class]);

        $this->actingAs($owner);

        app(TenantContext::class)->set($tenant);

        try {
            Livewire::test(TenantStaffManager::class, ['tenant' => $tenant])
                ->call('manageRole', $stranger->getKey())
                ->set('currentRole', 'editor')
                ->call('updateRole');

            $this->fail('The staff screen accepted a role change for a non-member.');
        } catch (ModelNotFoundException) {
            // Reported as a missing record, which Laravel renders as a 404.
        }

        Event::assertNotDispatched(TenantStaffUpdated::class);

        $this->assertNull($this->tenantRole($tenant, $stranger));
    }

    public function test_the_update_result_cannot_tell_absence_from_no_change(): void
    {
        // Why membership is established separately instead of read back off
        // the write. These relations name a pivot model, so Laravel calls
        // using() for them and updateExistingPivot() takes its custom-class
        // path: it loads the pivot, fills it, and reports whether anything
        // became dirty. A membership that does not exist reports nothing, and
        // so does one that already holds the role being set. The two are
        // indistinguishable — not on one driver or another, but by
        // construction.
        //
        // Nothing here goes through the action; this is the framework
        // behaviour the fix is shaped around.
        [, $tenant] = $this->tenantWithRoles();

        $stranger = $this->createUser('stranger@example.test');

        $staff = $this->createUser('staff@acme.test');

        $tenant->users()->attach($staff, ['role' => 'editor']);

        $absent = $tenant->users()->updateExistingPivot($stranger->getKey(), ['role' => 'editor']);
        $unchanged = $tenant->users()->updateExistingPivot($staff->getKey(), ['role' => 'editor']);

        $this->assertFalse((bool) $absent, 'Updating a membership that does not exist reported a change.');
        $this->assertFalse((bool) $unchanged, 'Setting the role a member already holds reported a change.');

        // And a real change does report one, so the value is not simply
        // useless — it just cannot answer the question being asked of it.
        $this->assertTrue(
            (bool) $tenant->users()->updateExistingPivot($staff->getKey(), ['role' => 'admin']),
            'A real role change reported nothing.'
        );
    }

    public function test_setting_the_role_a_member_already_has_still_succeeds(): void
    {
        // Decided rather than inherited. The membership exists and ends in the
        // state that was asked for, so the update succeeds and is announced —
        // even though nothing about the row changed.
        //
        // The alternative, taking the result of the write as the answer,
        // cannot decide it at all: as the test above shows, a membership that
        // does not exist and one that already holds the role report the same
        // thing. Whether a membership exists has to be asked separately.
        [$owner, $tenant] = $this->tenantWithRoles();

        $staff = $this->createUser('staff@acme.test');

        $tenant->users()->attach($staff, ['role' => 'editor']);

        Event::fake([TenantStaffUpdated::class]);

        (new UpdateTenantStaffRole)->update($owner, $tenant, $staff->getKey(), 'editor');

        $this->assertSame('editor', $this->tenantRole($tenant, $staff));

        Event::assertDispatched(TenantStaffUpdated::class);
    }
}
