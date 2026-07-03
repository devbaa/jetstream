<?php

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateTenant;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

class CurrentTenantControllerTest extends OrchestraTestCase
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

    public function test_can_switch_to_tenant_the_user_belongs_to()
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = (new CreateTenant)->create($user, ['name' => 'Acme']);

        $user->forceFill(['current_tenant_id' => null])->save();

        $response = $this->actingAs($user->fresh())->put('/current-tenant', ['tenant_id' => $tenant->id]);

        $response->assertRedirect('/home');

        $this->assertEquals($tenant->id, $user->fresh()->current_tenant_id);
    }

    public function test_cant_switch_to_tenant_the_user_does_not_belong_to()
    {
        $owner = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = (new CreateTenant)->create($owner, ['name' => 'Acme']);

        $otherUser = User::forceCreate([
            'name' => 'Adam Wathan',
            'email' => 'adam@laravel.com',
            'password' => 'secret',
        ]);

        $response = $this->actingAs($otherUser)->put('/current-tenant', ['tenant_id' => $tenant->id]);

        $response->assertStatus(403);

        $this->assertNull($otherUser->fresh()->current_tenant_id);
    }

    public function test_stale_tenant_access_is_healed_by_the_context_middleware()
    {
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

        $member->tenants()->attach($tenant, ['role' => null]);
        $member->forceFill(['current_tenant_id' => $tenant->id])->save();

        // Revoke membership while the tenant is still "current"...
        $tenant->users()->detach($member);

        $this->actingAs($member->fresh())->get('/tenants/create');

        $this->assertNull($member->fresh()->current_tenant_id);
    }
}
