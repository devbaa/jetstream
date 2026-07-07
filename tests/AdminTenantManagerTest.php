<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateTenant;
use App\Actions\Jetstream\DeleteTenant;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Events\TenantFrozen;
use Laravel\Jetstream\Events\TenantUnfrozen;
use Laravel\Jetstream\Http\Livewire\Admin\TenantManager;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

class AdminTenantManagerTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTenantEnvironment($app);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Gate::policy(Tenant::class, TenantPolicy::class);
        Jetstream::useUserModel(User::class);
        Jetstream::createTenantsUsing(CreateTenant::class);
        Jetstream::deleteTenantsUsing(DeleteTenant::class);
    }

    protected function createAdmin(): User
    {
        $admin = User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@laravel.com',
            'password' => 'secret',
        ]);

        $admin->forceFill(['is_system_admin' => true])->save();

        return $admin;
    }

    protected function createUser(string $email = 'taylor@laravel.com'): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    protected function createTenant(User $owner, string $name = 'Acme'): Tenant
    {
        return (new CreateTenant)->create($owner, ['name' => $name]);
    }

    public function test_admins_can_create_a_tenant_for_an_existing_owner(): void
    {
        $admin = $this->createAdmin();
        $owner = $this->createUser();

        $this->actingAs($admin);

        Livewire::test(TenantManager::class)
            ->call('createTenant')
            ->assertSet('creatingTenant', true)
            ->set('createTenantForm.name', 'Acme')
            ->set('createTenantForm.owner_email', $owner->email)
            ->call('saveTenant')
            ->assertHasNoErrors()
            ->assertSet('creatingTenant', false)
            ->assertDispatched('saved');

        $tenant = Tenant::query()->where('name', 'Acme')->first();

        $this->assertNotNull($tenant);
        $this->assertTrue($tenant->user_id === $owner->id);
        $this->assertTrue($owner->fresh()->ownsTenant($tenant));
    }

    public function test_tenants_cannot_be_created_for_unregistered_owners(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Livewire::test(TenantManager::class)
            ->call('createTenant')
            ->set('createTenantForm.name', 'Acme')
            ->set('createTenantForm.owner_email', 'missing@example.com')
            ->call('saveTenant')
            ->assertHasErrors(['owner_email']);

        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_a_tenant_can_be_frozen_and_unfrozen(): void
    {
        Event::fake([TenantFrozen::class, TenantUnfrozen::class]);

        $admin = $this->createAdmin();
        $tenant = $this->createTenant($this->createUser());

        $this->actingAs($admin);

        Livewire::test(TenantManager::class)
            ->call('toggleTenantFreeze', $tenant->id)
            ->assertDispatched('saved');

        $this->assertTrue($tenant->fresh()->isFrozen());
        Event::assertDispatched(TenantFrozen::class, fn ($event) => $event->tenant->id === $tenant->id);

        Livewire::test(TenantManager::class)
            ->call('toggleTenantFreeze', $tenant->id)
            ->assertDispatched('saved');

        $this->assertFalse($tenant->fresh()->isFrozen());
        Event::assertDispatched(TenantUnfrozen::class, fn ($event) => $event->tenant->id === $tenant->id);
    }

    public function test_customer_self_registration_can_be_toggled(): void
    {
        $admin = $this->createAdmin();
        $tenant = $this->createTenant($this->createUser());

        $this->assertFalse((bool) $tenant->allow_customer_registration);

        $this->actingAs($admin);

        Livewire::test(TenantManager::class)->call('toggleCustomerRegistration', $tenant->id);

        $this->assertTrue((bool) $tenant->fresh()->allow_customer_registration);

        Livewire::test(TenantManager::class)->call('toggleCustomerRegistration', $tenant->id);

        $this->assertFalse((bool) $tenant->fresh()->allow_customer_registration);
    }

    public function test_a_tenant_can_be_deleted_after_confirmation(): void
    {
        $admin = $this->createAdmin();
        $tenant = $this->createTenant($this->createUser());

        $this->actingAs($admin);

        Livewire::test(TenantManager::class)
            ->call('confirmTenantDeletion', $tenant->id)
            ->assertSet('confirmingTenantDeletion', true)
            ->assertSet('tenantIdBeingDeleted', $tenant->id)
            ->call('deleteTenant')
            ->assertSet('confirmingTenantDeletion', false)
            ->assertSet('tenantIdBeingDeleted', null);

        $this->assertTrue(Tenant::withTrashed()->findOrFail($tenant->id)->trashed());
    }

    public function test_the_tenant_list_can_be_searched(): void
    {
        $admin = $this->createAdmin();

        $this->createTenant($this->createUser('acme-owner@example.com'), 'Acme Corp');
        $this->createTenant($this->createUser('beta-owner@example.com'), 'Beta Widgets');

        $this->actingAs($admin);

        Livewire::test(TenantManager::class)
            ->assertSee('Acme Corp')
            ->assertSee('Beta Widgets')
            ->set('search', 'beta')
            ->assertDontSee('Acme Corp')
            ->assertSee('Beta Widgets');
    }
}
