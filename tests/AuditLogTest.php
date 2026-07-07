<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\AuditLog;
use App\Models\Team;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;
use Laravel\Jetstream\Tests\Fixtures\User;

class AuditLogTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTenantEnvironment($app);

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

    public function test_creating_an_auditable_model_records_a_log_entry(): void
    {
        $user = $this->createUser();

        $log = AuditLog::query()
            ->where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', $user->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->old_values);
        $this->assertSame('Taylor Otwell', $log->new_values['name'] ?? null);
        $this->assertSame('taylor@laravel.com', $log->new_values['email'] ?? null);
        $this->assertNotNull($log->ip_address);
    }

    public function test_hidden_attributes_are_never_recorded(): void
    {
        $user = $this->createUser();

        $log = AuditLog::query()
            ->where('auditable_id', $user->id)
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertIsArray($log->new_values);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertArrayNotHasKey('remember_token', $log->new_values);
    }

    public function test_updates_record_old_and_new_values_for_changed_attributes_only(): void
    {
        $user = $this->createUser();

        $user->forceFill(['name' => 'Taylor O.'])->save();

        $log = AuditLog::query()
            ->where('auditable_id', $user->id)
            ->where('event', 'updated')
            ->firstOrFail();

        $this->assertSame(['name' => 'Taylor Otwell'], $log->old_values);
        $this->assertSame(['name' => 'Taylor O.'], $log->new_values);
    }

    public function test_soft_and_force_deletes_are_distinguished(): void
    {
        $user = $this->createUser();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);

        $team->delete();

        $this->assertNotNull(AuditLog::query()
            ->where('auditable_type', $team->getMorphClass())
            ->where('auditable_id', $team->id)
            ->where('event', 'deleted')
            ->first());

        $team->forceDelete();

        $this->assertNotNull(AuditLog::query()
            ->where('auditable_type', $team->getMorphClass())
            ->where('auditable_id', $team->id)
            ->where('event', 'force_deleted')
            ->first());
    }

    public function test_restores_are_recorded(): void
    {
        $user = $this->createUser();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);

        $team->delete();
        $team->restore();

        $this->assertNotNull(AuditLog::query()
            ->where('auditable_id', $team->id)
            ->where('auditable_type', $team->getMorphClass())
            ->where('event', 'restored')
            ->first());
    }

    public function test_entries_are_attributed_to_the_tenant_in_context(): void
    {
        $user = $this->createUser();

        $tenant = \App\Models\Tenant::forceCreate([
            'user_id' => $user->id, 'name' => 'Acme', 'slug' => 'acme',
        ]);

        app(TenantContext::class)->set($tenant);

        $team = Team::forceCreate([
            'user_id' => $user->id, 'name' => 'Sub Team', 'personal_team' => false, 'tenant_id' => $tenant->id,
        ]);

        $log = AuditLog::query()
            ->where('auditable_id', $team->id)
            ->where('auditable_type', $team->getMorphClass())
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame($tenant->id, $log->tenant_id);
    }

    public function test_authentication_activity_is_recorded_with_ip_and_user_agent(): void
    {
        $user = $this->createUser();

        event(new Login('web', $user, false));

        $log = AuditLog::query()->where('event', 'auth.login')->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertNotNull($log->ip_address);
    }

    public function test_failed_logins_record_the_attempted_email_but_never_the_password(): void
    {
        event(new Failed('web', null, ['email' => 'intruder@laravel.com', 'password' => 'secret']));

        $log = AuditLog::query()->where('event', 'auth.failed')->firstOrFail();

        $this->assertSame('intruder@laravel.com', $log->new_values['email'] ?? null);
        $this->assertStringNotContainsString('secret', (string) json_encode($log->new_values));
    }

    public function test_the_acting_user_is_recorded(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);

        $log = AuditLog::query()
            ->where('auditable_id', $team->id)
            ->where('auditable_type', $team->getMorphClass())
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
    }

    public function test_audit_logging_can_be_disabled(): void
    {
        config(['jetstream.audit.enabled' => false]);

        $this->createUser();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_the_auditable_relation_returns_the_logged_model(): void
    {
        $user = $this->createUser();

        $log = AuditLog::query()->where('auditable_id', $user->id)->firstOrFail();

        $this->assertTrue($user->is($log->auditable));
        $this->assertCount(1, $user->auditLogs);
    }
}
