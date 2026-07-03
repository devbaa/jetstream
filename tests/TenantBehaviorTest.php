<?php

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\AddTenantStaff;
use App\Actions\Jetstream\CreateTenant;
use App\Actions\Jetstream\DeleteTenant;
use App\Actions\Jetstream\RemoveTenantStaff;
use App\Actions\Jetstream\UpdateTenantName;
use App\Models\CustomerAccount;
use App\Models\Role;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Actions\UpdateTenantStaffRole;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

class TenantBehaviorTest extends OrchestraTestCase
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

    protected function createOwner(): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);
    }

    public function test_tenant_names_can_be_updated()
    {
        $owner = $this->createOwner();

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        (new UpdateTenantName)->update($owner, $tenant, ['name' => 'Acme Inc.']);

        $this->assertSame('Acme Inc.', $tenant->fresh()->name);
    }

    public function test_slugs_are_unique_per_tenant_name()
    {
        $owner = $this->createOwner();

        $first = (new CreateTenant)->create($owner, ['name' => 'Acme']);
        $second = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $this->assertSame('acme', $first->slug);
        $this->assertSame('acme-2', $second->slug);
    }

    public function test_staff_can_be_added_and_removed()
    {
        Jetstream::role('admin', 'Administrator', ['read', 'create', 'update', 'delete']);
        Jetstream::role('staff', 'Staff', ['read']);

        $owner = $this->createOwner();

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $member = User::forceCreate([
            'name' => 'Adam Wathan',
            'email' => 'adam@laravel.com',
            'password' => 'secret',
        ]);

        (new AddTenantStaff)->add($owner, $tenant, 'adam@laravel.com', 'staff');

        $tenant = $tenant->fresh();
        $member = $member->fresh();

        $this->assertTrue($member->belongsToTenant($tenant));
        $this->assertTrue($member->hasTenantRole($tenant, 'staff'));

        // Staff role can be updated...
        (new UpdateTenantStaffRole)->update($owner, $tenant, $member->id, 'admin');

        $this->assertTrue($member->fresh()->hasTenantRole($tenant->fresh(), 'admin'));

        // Staff can be removed...
        (new RemoveTenantStaff)->remove($owner, $tenant->fresh(), $member->fresh());

        $this->assertFalse($member->fresh()->belongsToTenant($tenant->fresh()));
    }

    public function test_unknown_users_cannot_be_added_as_staff()
    {
        $owner = $this->createOwner();

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $this->expectException(ValidationException::class);

        (new AddTenantStaff)->add($owner, $tenant, 'missing@laravel.com', 'staff');
    }

    public function test_tenant_owners_cannot_be_removed_from_their_tenant()
    {
        $owner = $this->createOwner();

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $this->expectException(ValidationException::class);

        (new RemoveTenantStaff)->remove($owner, $tenant, $owner);
    }

    public function test_deleting_a_tenant_purges_its_resources()
    {
        $owner = $this->createOwner();

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $member = User::forceCreate([
            'name' => 'Adam Wathan',
            'email' => 'adam@laravel.com',
            'password' => 'secret',
        ]);

        $member->tenants()->attach($tenant, ['role' => null]);
        $member->forceFill(['current_tenant_id' => $tenant->id])->save();

        $team = Team::forceCreate([
            'user_id' => $owner->id, 'name' => 'Sub Team', 'personal_team' => false, 'tenant_id' => $tenant->id,
        ]);

        $account = CustomerAccount::forceCreate([
            'tenant_id' => $tenant->id, 'user_id' => $member->id, 'name' => 'Customer',
        ]);

        $member->forceFill(['current_customer_account_id' => $account->id])->save();

        $tenant->customerInvitations()->create(['email' => 'invitee@laravel.com']);

        Role::forceCreate(['tenant_id' => $tenant->id, 'key' => 'custom', 'name' => 'Custom', 'permissions' => ['read']]);

        (new DeleteTenant)->delete($tenant->fresh());

        $this->assertNull($tenant->fresh());
        $this->assertNull($team->fresh());
        $this->assertNull($account->fresh());
        $this->assertSame(0, Role::withoutTenancy()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('customer_invitations')->count());
        $this->assertNull($member->fresh()->current_tenant_id);
        $this->assertNull($member->fresh()->current_customer_account_id);
        $this->assertNull($owner->fresh()->current_tenant_id);
    }
}
