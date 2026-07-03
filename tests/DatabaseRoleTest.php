<?php

namespace Laravel\Jetstream\Tests;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Actions\CreateRole;
use Laravel\Jetstream\Actions\DeleteRole;
use Laravel\Jetstream\Actions\UpdateRole;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

class DatabaseRoleTest extends OrchestraTestCase
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

    protected function createOwnerAndTenant(): array
    {
        $owner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = Tenant::forceCreate(['name' => 'Acme', 'slug' => 'acme-'.uniqid(), 'user_id' => $owner->id]);

        return [$owner, $tenant];
    }

    public function test_roles_are_resolved_from_the_database_with_static_fallback()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        Jetstream::role('static-role', 'Static Role', ['read']);

        // Static fallback...
        $this->assertSame('Static Role', Jetstream::findRole('static-role', $tenant)->name);

        // Database default beats the static definition...
        Role::forceCreate(['tenant_id' => null, 'key' => 'static-role', 'name' => 'Database Default', 'permissions' => ['read', 'update']]);

        app(RoleRegistry::class)->flush();

        $role = Jetstream::findRole('static-role', $tenant);

        $this->assertSame('Database Default', $role->name);
        $this->assertSame(['read', 'update'], $role->permissions);

        // Tenant override beats the database default...
        Role::forceCreate(['tenant_id' => $tenant->id, 'key' => 'static-role', 'name' => 'Tenant Override', 'permissions' => ['read']]);

        app(RoleRegistry::class)->flush();

        $this->assertSame('Tenant Override', Jetstream::findRole('static-role', $tenant)->name);

        // Other tenants keep the default...
        $otherTenant = Tenant::forceCreate(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'user_id' => $owner->id]);

        $this->assertSame('Database Default', Jetstream::findRole('static-role', $otherTenant)->name);
    }

    public function test_find_role_uses_the_tenant_in_context_when_none_is_given()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        Role::forceCreate(['tenant_id' => $tenant->id, 'key' => 'contextual', 'name' => 'Contextual', 'permissions' => ['read']]);

        $this->assertNull(Jetstream::findRole('contextual'));

        app(TenantContext::class)->set($tenant);
        app(RoleRegistry::class)->flush();

        $this->assertSame('Contextual', Jetstream::findRole('contextual')->name);
    }

    public function test_role_rule_validates_against_tenant_roles()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        Role::forceCreate(['tenant_id' => $tenant->id, 'key' => 'custom-role', 'name' => 'Custom', 'permissions' => ['read']]);

        $rule = new \Laravel\Jetstream\Rules\Role;

        $this->assertFalse($rule->passes('role', 'custom-role'));

        app(TenantContext::class)->set($tenant);
        app(RoleRegistry::class)->flush();

        $this->assertTrue($rule->passes('role', 'custom-role'));
        $this->assertFalse($rule->passes('role', 'missing-role'));
    }

    public function test_tenant_owners_can_create_update_and_delete_roles()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        Jetstream::permissions(['create', 'read', 'update', 'delete']);

        $role = (new CreateRole)->create($owner, $tenant, [
            'key' => 'support-agent',
            'name' => 'Support Agent',
            'description' => 'Handles support tickets.',
            'permissions' => ['read', 'update', 'not-in-catalog'],
        ]);

        $this->assertSame(['read', 'update'], $role->permissions);
        $this->assertEquals($tenant->id, $role->tenant_id);
        $this->assertSame('Support Agent', Jetstream::findRole('support-agent', $tenant)->name);

        (new UpdateRole)->update($owner, $tenant, $role, [
            'name' => 'Agent',
            'permissions' => ['read'],
        ]);

        $this->assertSame('Agent', Jetstream::findRole('support-agent', $tenant)->name);

        (new DeleteRole)->delete($owner, $tenant, $role->fresh());

        $this->assertNull(Jetstream::findRole('support-agent', $tenant));
    }

    public function test_the_owner_key_is_reserved()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        Jetstream::permissions(['read']);

        $this->expectException(ValidationException::class);

        (new CreateRole)->create($owner, $tenant, [
            'key' => 'owner', 'name' => 'Owner', 'permissions' => ['read'],
        ]);
    }

    public function test_duplicate_keys_within_a_tenant_are_rejected()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        Jetstream::permissions(['read']);

        (new CreateRole)->create($owner, $tenant, [
            'key' => 'dupe', 'name' => 'Dupe', 'permissions' => ['read'],
        ]);

        $this->expectException(ValidationException::class);

        (new CreateRole)->create($owner, $tenant, [
            'key' => 'dupe', 'name' => 'Dupe Again', 'permissions' => ['read'],
        ]);
    }

    public function test_roles_that_are_still_assigned_cannot_be_deleted()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        Jetstream::permissions(['read']);

        $role = (new CreateRole)->create($owner, $tenant, [
            'key' => 'assigned-role', 'name' => 'Assigned', 'permissions' => ['read'],
        ]);

        $member = User::forceCreate([
            'name' => 'Adam Wathan',
            'email' => 'adam@laravel.com',
            'password' => 'secret',
        ]);

        $member->tenants()->attach($tenant, ['role' => 'assigned-role']);

        try {
            (new DeleteRole)->delete($owner, $tenant, $role);

            $this->fail('Assigned roles should not be deletable.');
        } catch (ValidationException $e) {
            $this->assertNotNull($role->fresh());
        }

        $tenant->users()->detach($member);

        (new DeleteRole)->delete($owner, $tenant, $role->fresh());

        $this->assertNull($role->fresh());
    }

    public function test_non_owners_without_permission_cannot_manage_roles()
    {
        [$owner, $tenant] = $this->createOwnerAndTenant();

        Jetstream::permissions(['read']);

        $outsider = User::forceCreate([
            'name' => 'Adam Wathan',
            'email' => 'adam@laravel.com',
            'password' => 'secret',
        ]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        (new CreateRole)->create($outsider, $tenant, [
            'key' => 'nope', 'name' => 'Nope', 'permissions' => ['read'],
        ]);
    }
}
