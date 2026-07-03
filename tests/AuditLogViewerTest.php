<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Http\Livewire\AuditLogViewer;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\TenantPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

class AuditLogViewerTest extends OrchestraTestCase
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
    }

    protected function createUser(string $email = 'taylor@laravel.com'): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    public function test_system_admins_can_view_the_application_wide_audit_log(): void
    {
        $admin = $this->createUser();
        $admin->forceFill(['is_system_admin' => true])->save();

        AuditLog::query()->forceCreate(['event' => 'custom.event', 'created_at' => now()]);

        $this->actingAs($admin);

        Livewire::withoutLazyLoading()
            ->test(AuditLogViewer::class)
            ->assertSee('custom.event');
    }

    public function test_regular_users_cannot_view_the_application_wide_audit_log(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(AuditLogViewer::class)->assertStatus(403);
    }

    public function test_the_tenant_scoped_viewer_only_shows_the_tenants_entries(): void
    {
        $owner = $this->createUser();

        $tenant = Tenant::forceCreate(['user_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);
        $other = Tenant::forceCreate(['user_id' => $owner->id, 'name' => 'Other', 'slug' => 'other']);

        AuditLog::query()->forceCreate(['event' => 'acme.entry', 'tenant_id' => $tenant->id, 'created_at' => now()]);
        AuditLog::query()->forceCreate(['event' => 'other.entry', 'tenant_id' => $other->id, 'created_at' => now()]);

        $this->actingAs($owner);

        Livewire::withoutLazyLoading()
            ->test(AuditLogViewer::class, ['tenant' => $tenant])
            ->assertSee('acme.entry')
            ->assertDontSee('other.entry');
    }

    public function test_users_without_the_tenant_update_permission_cannot_view_its_log(): void
    {
        $owner = $this->createUser();
        $outsider = $this->createUser('adam@laravel.com');

        $tenant = Tenant::forceCreate(['user_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($outsider);

        Livewire::test(AuditLogViewer::class, ['tenant' => $tenant])->assertStatus(403);
    }
}
