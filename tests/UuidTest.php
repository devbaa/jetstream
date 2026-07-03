<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\AuditLog;
use App\Models\CustomerAccount;
use App\Models\DataRequest;
use App\Models\Role;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Laravel\Jetstream\DataRequest as DataRequestContract;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

class UuidTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTenantEnvironment($app);

        Jetstream::useUserModel(User::class);
    }

    protected function assertUuidV7(mixed $id): void
    {
        $this->assertIsString($id);
        $this->assertTrue(Str::isUuid($id), "[$id] is not a UUID.");
        $this->assertSame('7', $id[14], "[$id] is not a version 7 UUID.");
    }

    public function test_all_entities_use_version_7_uuid_primary_keys(): void
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $tenant = Tenant::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'slug' => 'acme']);
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Team', 'personal_team' => false]);
        $account = CustomerAccount::forceCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Customer']);
        $role = Role::forceCreate(['tenant_id' => null, 'key' => 'custom', 'name' => 'Custom', 'permissions' => ['read']]);
        $invitation = $tenant->customerInvitations()->create(['email' => 'invitee@laravel.com']);

        $request = DataRequest::query()->forceCreate([
            'user_id' => $user->id,
            'type' => DataRequestContract::TYPE_EXPORT,
            'status' => DataRequestContract::STATUS_COMPLETED,
        ]);

        $this->assertUuidV7($user->id);
        $this->assertUuidV7($tenant->id);
        $this->assertUuidV7($team->id);
        $this->assertUuidV7($account->id);
        $this->assertUuidV7($role->id);
        $this->assertUuidV7($invitation->id);
        $this->assertUuidV7($request->id);

        // Auditable models wrote audit logs; those entries carry UUIDs too.
        $log = AuditLog::query()->firstOrFail();

        $this->assertUuidV7($log->id);
        $this->assertSame($user->id, AuditLog::query()->where('auditable_id', $user->id)->firstOrFail()->auditable_id);
    }

    public function test_uuid_keys_are_time_ordered(): void
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $first = Team::forceCreate(['user_id' => $user->id, 'name' => 'First', 'personal_team' => false]);
        $second = Team::forceCreate(['user_id' => $user->id, 'name' => 'Second', 'personal_team' => false]);

        $this->assertLessThan($second->id, $first->id);
    }

    public function test_ids_cannot_be_enumerated_by_incrementing(): void
    {
        $user = User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        // Sequential integer probes never match a UUID key.
        $this->assertNull(User::find(1));
        $this->assertNull(User::find('1'));
        $this->assertNotNull(User::find($user->id));
    }
}
