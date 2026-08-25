<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\Role;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole;
use Laravel\Jetstream\Actions\UpdateTenantStaffRole;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\TeamPolicy;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

/**
 * A role is valid for the thing being changed, not for whatever is in context.
 *
 * Roles are per-tenant: each tenant may override the application's defaults
 * and define keys of its own. A user who belongs to two tenants has one
 * ambient "current" tenant and can still be handed an explicit tenant or team
 * to act on — the tenant switcher and route-model binding are separate things.
 * When those two disagree, validating against the ambient one is wrong in both
 * directions at once: it turns away roles the target really has, and accepts
 * roles the target has never heard of.
 *
 * The screens already resolve their role lists from the target — the team
 * manager asks the registry for the team's tenant, the staff manager for the
 * tenant it is managing — so only the validation was reading somewhere else.
 */
class TargetTenantRoleValidationTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);

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

    protected function createTenant(User $owner, string $slug): Tenant
    {
        return Tenant::forceCreate(['name' => ucfirst($slug), 'slug' => $slug, 'user_id' => $owner->id]);
    }

    /**
     * A role key that exists for exactly one tenant.
     */
    protected function roleFor(?Tenant $tenant, string $key): Role
    {
        return Role::forceCreate([
            'tenant_id' => $tenant?->getKey(),
            'key' => $key,
            'name' => ucfirst($key),
            'permissions' => ['read'],
        ]);
    }

    /**
     * Put a tenant into ambient context, the way the tenant switcher does.
     */
    protected function makeCurrent(?Tenant $tenant): void
    {
        app(TenantContext::class)->set($tenant);
    }

    /**
     * The two tenants, their staff, and one role key private to each.
     *
     * @return array{User, Tenant, Tenant}
     */
    protected function twoTenants(): array
    {
        $owner = $this->createUser('taylor@laravel.com');

        $a = $this->createTenant($owner, 'alpha');
        $b = $this->createTenant($owner, 'beta');

        $this->roleFor($a, 'alpha-only');
        $this->roleFor($b, 'beta-only');

        app(RoleRegistry::class)->flush();

        return [$owner, $a, $b];
    }

    /**
     * Attach a user to a tenant as staff so the pivot exists.
     */
    protected function attach(Tenant $tenant, User $user, string $role = 'admin'): void
    {
        $tenant->users()->attach($user, ['role' => $role]);
    }

    public function test_a_role_of_the_target_tenant_is_accepted_while_another_is_ambient(): void
    {
        // Ambient A, target B, role that exists only in B. The screen offers
        // this role; the server has to accept it.
        [$owner, $a, $b] = $this->twoTenants();

        $staff = $this->createUser('adam@laravel.com');

        $this->attach($b, $staff);

        $this->makeCurrent($a);

        app(UpdateTenantStaffRole::class)->update($owner, $b, $staff->id, 'beta-only');

        $this->assertSame('beta-only', $b->users()->find($staff->id)?->membership->role);
    }

    public function test_a_role_of_the_ambient_tenant_is_rejected_for_another_target(): void
    {
        // The other direction, and the one that matters more: ambient A,
        // target B, role that exists only in A. B has never heard of it.
        [$owner, $a, $b] = $this->twoTenants();

        $staff = $this->createUser('adam@laravel.com');

        $this->attach($b, $staff);

        $this->makeCurrent($a);

        $this->expectException(ValidationException::class);

        app(UpdateTenantStaffRole::class)->update($owner, $b, $staff->id, 'alpha-only');
    }

    public function test_the_ordinary_same_context_path_still_works(): void
    {
        // Control: ambient and target are the same tenant. Making validation
        // target-aware must not break the case that was already right.
        [$owner, , $b] = $this->twoTenants();

        $staff = $this->createUser('adam@laravel.com');

        $this->attach($b, $staff);

        $this->makeCurrent($b);

        app(UpdateTenantStaffRole::class)->update($owner, $b, $staff->id, 'beta-only');

        $this->assertSame('beta-only', $b->users()->find($staff->id)?->membership->role);
    }

    public function test_a_role_no_tenant_defines_is_rejected_whatever_is_ambient(): void
    {
        [$owner, $a, $b] = $this->twoTenants();

        $staff = $this->createUser('adam@laravel.com');

        $this->attach($b, $staff);

        $this->makeCurrent($a);

        $this->expectException(ValidationException::class);

        app(UpdateTenantStaffRole::class)->update($owner, $b, $staff->id, 'no-such-role');
    }

    public function test_a_global_role_is_accepted_for_any_target(): void
    {
        // A role with a null tenant_id belongs to every tenant, so it is
        // valid for the target regardless of what is ambient.
        [$owner, $a, $b] = $this->twoTenants();

        $this->roleFor(null, 'everywhere');

        app(RoleRegistry::class)->flush();

        $staff = $this->createUser('adam@laravel.com');

        $this->attach($b, $staff);

        $this->makeCurrent($a);

        app(UpdateTenantStaffRole::class)->update($owner, $b, $staff->id, 'everywhere');

        $this->assertSame('everywhere', $b->users()->find($staff->id)?->membership->role);
    }

    public function test_a_team_is_validated_against_its_own_tenants_roles(): void
    {
        // Teams carry their tenant rather than inheriting the ambient one, and
        // the team screen already lists roles from $team->tenant_id.
        [$owner, $a, $b] = $this->twoTenants();

        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'tenant_id' => $b->id,
            'name' => 'Beta Team',
            'personal_team' => false,
        ]);

        $member = $this->createUser('adam@laravel.com');

        $team->users()->attach($member, ['role' => 'admin']);

        $this->makeCurrent($a);

        app(UpdateTeamMemberRole::class)->update($owner, $team, $member->id, 'beta-only');

        $this->assertSame('beta-only', $team->users()->find($member->id)?->membership->role);
    }

    public function test_a_personal_team_gets_the_roles_every_tenant_shares(): void
    {
        // A personal team has no tenant. That is a target — the roles no
        // tenant owns — and not a gap for the ambient tenant to fill, so a
        // role private to the tenant the owner happens to be looking at is
        // not valid in their personal team.
        [$owner, $a] = $this->twoTenants();

        $this->roleFor(null, 'everywhere');

        app(RoleRegistry::class)->flush();

        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'tenant_id' => null,
            'name' => 'Personal',
            'personal_team' => true,
        ]);

        $member = $this->createUser('adam@laravel.com');

        $team->users()->attach($member, ['role' => 'admin']);

        $this->makeCurrent($a);

        app(UpdateTeamMemberRole::class)->update($owner, $team, $member->id, 'everywhere');

        $this->assertSame('everywhere', $team->users()->find($member->id)?->membership->role);

        $this->expectException(ValidationException::class);

        app(UpdateTeamMemberRole::class)->update($owner, $team, $member->id, 'alpha-only');
    }

    public function test_a_team_rejects_a_role_belonging_to_the_ambient_tenant(): void
    {
        [$owner, $a, $b] = $this->twoTenants();

        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'tenant_id' => $b->id,
            'name' => 'Beta Team',
            'personal_team' => false,
        ]);

        $member = $this->createUser('adam@laravel.com');

        $team->users()->attach($member, ['role' => 'admin']);

        $this->makeCurrent($a);

        $this->expectException(ValidationException::class);

        app(UpdateTeamMemberRole::class)->update($owner, $team, $member->id, 'alpha-only');
    }
}
