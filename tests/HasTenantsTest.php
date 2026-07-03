<?php

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateTenant;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\OwnerRole;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

class HasTenantsTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $app->config->set('jetstream.stack', 'livewire');

        $this->defineHasTenantEnvironment($app);

        Gate::policy(Tenant::class, TenantPolicy::class);
        Jetstream::useUserModel(User::class);
    }

    public function test_tenant_relationship_methods()
    {
        $action = new CreateTenant;

        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = $action->create($user, ['name' => 'Acme Corp']);

        $this->assertInstanceOf(\Laravel\Jetstream\Tenant::class, $tenant);
        $this->assertSame('acme-corp', $tenant->slug);

        $this->assertTrue($user->belongsToTenant($tenant));
        $this->assertTrue($user->ownsTenant($tenant));
        $this->assertCount(1, $user->fresh()->ownedTenants);
        $this->assertCount(1, $user->fresh()->allTenants());
        $this->assertEquals($tenant->id, $user->fresh()->currentTenant->id);
        $this->assertTrue($user->fresh()->isCurrentTenant($tenant));

        $this->assertInstanceOf(OwnerRole::class, $user->tenantRole($tenant));
        $this->assertSame(['*'], $user->tenantPermissions($tenant));
        $this->assertTrue($user->hasTenantPermission($tenant, 'anything'));

        // Another user that is not on the tenant...
        $otherUser = User::forceCreate([
            'name' => 'Adam Wathan',
            'email' => 'adam@laravel.com',
            'password' => 'secret',
        ]);

        $this->assertFalse($otherUser->belongsToTenant($tenant));
        $this->assertFalse($otherUser->ownsTenant($tenant));
        $this->assertFalse($otherUser->hasTenantPermission($tenant, 'foo'));

        // Add the other user as staff...
        Jetstream::role('editor', 'Editor', ['foo', 'bar:create']);

        $otherUser->tenants()->attach($tenant, ['role' => 'editor']);
        $otherUser = $otherUser->fresh();

        $this->assertTrue($otherUser->belongsToTenant($tenant));
        $this->assertFalse($otherUser->ownsTenant($tenant));
        $this->assertTrue($otherUser->hasTenantRole($tenant, 'editor'));
        $this->assertFalse($otherUser->hasTenantRole($tenant, 'admin'));

        $this->assertTrue($otherUser->hasTenantPermission($tenant, 'foo'));
        $this->assertFalse($otherUser->hasTenantPermission($tenant, 'baz'));

        $this->assertTrue($tenant->userHasPermission($otherUser, 'foo'));
        $this->assertTrue($tenant->hasUser($otherUser));
        $this->assertTrue($tenant->hasUserWithEmail('adam@laravel.com'));
        $this->assertCount(2, $tenant->fresh()->allUsers());
    }

    public function test_wildcard_tenant_permissions()
    {
        Jetstream::role('creator', 'Creator', ['*:create']);

        $owner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $member = User::forceCreate([
            'name' => 'Adam Wathan',
            'email' => 'adam@laravel.com',
            'password' => 'secret',
        ]);

        $member->tenants()->attach($tenant, ['role' => 'creator']);
        $member = $member->fresh();

        $this->assertTrue($member->hasTenantPermission($tenant, 'post:create'));
        $this->assertFalse($member->hasTenantPermission($tenant, 'post:update'));
    }

    public function test_switching_tenants_repoints_the_current_team()
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenantA = (new CreateTenant)->create($user, ['name' => 'Tenant A']);
        $tenantB = (new CreateTenant)->create($user, ['name' => 'Tenant B']);

        $teamInA = \App\Models\Team::forceCreate([
            'user_id' => $user->id, 'name' => 'A Team', 'personal_team' => false, 'tenant_id' => $tenantA->id,
        ]);

        $teamInB = \App\Models\Team::forceCreate([
            'user_id' => $user->id, 'name' => 'B Team', 'personal_team' => false, 'tenant_id' => $tenantB->id,
        ]);

        $user = $user->fresh();

        $this->assertTrue($user->switchTenant($tenantA));
        $this->assertEquals($tenantA->id, $user->fresh()->current_tenant_id);
        $this->assertEquals($teamInA->id, $user->fresh()->current_team_id);

        $this->assertTrue($user->fresh()->switchTenant($tenantB));
        $this->assertEquals($teamInB->id, $user->fresh()->current_team_id);
    }

    public function test_users_cannot_switch_to_tenants_they_do_not_belong_to()
    {
        $owner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $outsider = User::forceCreate([
            'name' => 'Adam Wathan',
            'email' => 'adam@laravel.com',
            'password' => 'secret',
        ]);

        $this->assertFalse($outsider->switchTenant($tenant));
        $this->assertNull($outsider->fresh()->current_tenant_id);
    }

    public function test_is_system_admin()
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $this->assertFalse($user->isSystemAdmin());

        $user->forceFill(['is_system_admin' => true])->save();

        $this->assertTrue($user->fresh()->isSystemAdmin());
    }
}
