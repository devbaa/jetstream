<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\DeleteUser;
use App\Models\AuditLog;
use App\Models\CustomerAccount;
use App\Models\DataRequest;
use App\Models\DomainClaim;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\DataRequest as DataRequestContract;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;

class PurgeCommandTest extends OrchestraTestCase
{
    use RefreshDatabase;

    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $this->defineHasTenantEnvironment($app);

        Jetstream::useUserModel(User::class);
        Jetstream::deleteUsersUsing(DeleteUser::class);
    }

    protected function afterRefreshingDatabase()
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function ($table) {
                $table->id();
                $table->foreignId('tokenable_id');
                $table->string('tokenable_type');
            });
        }
    }

    protected function createUser(string $email = 'taylor@laravel.com'): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    public function test_records_trashed_past_retention_are_purged_and_recent_ones_are_kept(): void
    {
        $user = $this->createUser();

        $old = Team::forceCreate(['user_id' => $user->id, 'name' => 'Old', 'personal_team' => false]);
        $recent = Team::forceCreate(['user_id' => $user->id, 'name' => 'Recent', 'personal_team' => false]);

        $old->delete();
        $recent->delete();

        $old->forceFill(['deleted_at' => now()->subDays(45)])->save();

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertNull(Team::withTrashed()->find($old->id));
        $this->assertNotNull(Team::withTrashed()->find($recent->id));
    }

    public function test_purging_a_user_erases_everything_they_own(): void
    {
        $user = $this->createUser();

        $tenant = Tenant::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'slug' => 'acme']);
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Team', 'personal_team' => true]);

        $user->delete();
        $user->forceFill(['deleted_at' => now()->subDays(45)])->save();

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertNull(User::withTrashed()->find($user->id));
        $this->assertNull(Team::withTrashed()->find($team->id));
        $this->assertNull(Tenant::withTrashed()->find($tenant->id));
    }

    public function test_purging_a_user_erases_their_audit_trail_identity(): void
    {
        $user = $this->createUser();

        $user->forceFill(['name' => 'Changed'])->save();

        $this->assertTrue(AuditLog::query()->where('auditable_id', $user->id)->exists());

        $user->delete();
        $user->forceFill(['deleted_at' => now()->subDays(45)])->save();

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertFalse(AuditLog::query()
            ->where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', $user->id)
            ->exists());
    }

    public function test_due_deletion_requests_soft_delete_the_user_and_are_completed(): void
    {
        $user = $this->createUser();

        $request = DataRequest::query()->forceCreate([
            'user_id' => $user->id,
            'type' => DataRequestContract::TYPE_DELETION,
            'status' => DataRequestContract::STATUS_PENDING,
            'process_after' => now()->subMinute(),
        ]);

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertTrue($user->fresh()->trashed());
        $this->assertSame(DataRequestContract::STATUS_COMPLETED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->completed_at);
    }

    public function test_deletion_requests_within_the_grace_period_are_not_processed(): void
    {
        $user = $this->createUser();

        $request = DataRequest::query()->forceCreate([
            'user_id' => $user->id,
            'type' => DataRequestContract::TYPE_DELETION,
            'status' => DataRequestContract::STATUS_PENDING,
            'process_after' => now()->addDays(10),
        ]);

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertFalse($user->fresh()->trashed());
        $this->assertSame(DataRequestContract::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_cancelled_deletion_requests_are_never_processed(): void
    {
        $user = $this->createUser();

        DataRequest::query()->forceCreate([
            'user_id' => $user->id,
            'type' => DataRequestContract::TYPE_DELETION,
            'status' => DataRequestContract::STATUS_CANCELLED,
            'process_after' => now()->subDay(),
        ]);

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_standalone_tenants_trashed_past_retention_are_purged_with_their_resources(): void
    {
        $owner = $this->createUser();

        $tenant = Tenant::forceCreate(['user_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);
        $recent = Tenant::forceCreate(['user_id' => $owner->id, 'name' => 'Recent', 'slug' => 'recent']);

        $staff = $this->createUser('staff@laravel.com');
        $tenant->users()->attach($staff, ['role' => 'admin']);

        $tenant->roles()->create(['key' => 'editor', 'name' => 'Editor', 'permissions' => ['posts:update']]);

        $customer = $this->createUser('jane@example.com');

        $account = CustomerAccount::forceCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $customer->id,
            'name' => 'Jane Co',
        ]);

        $tenant->customerInvitations()->create(['email' => 'invited@example.com']);

        $tenant->delete();
        $recent->delete();

        $tenant->forceFill(['deleted_at' => now()->subDays(45)])->save();

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertNull(Tenant::withTrashed()->find($tenant->id));
        $this->assertNotNull(Tenant::withTrashed()->find($recent->id));
        $this->assertNull(CustomerAccount::withTrashed()->find($account->id));
        $this->assertSame(0, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('roles')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('customer_invitations')->count());
    }

    public function test_standalone_customer_accounts_trashed_past_retention_are_purged(): void
    {
        $owner = $this->createUser();

        $tenant = Tenant::forceCreate(['user_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);

        $customer = $this->createUser('jane@example.com');

        $account = CustomerAccount::forceCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $customer->id,
            'name' => 'Jane Co',
        ]);

        $member = $this->createUser('mate@example.com');
        $account->users()->attach($member);

        $account->customerInvitations()->make(['email' => 'pending@example.com'])
            ->forceFill(['tenant_id' => $tenant->id])
            ->save();

        $customer->forceFill(['current_customer_account_id' => $account->id])->save();

        $account->delete();
        $account->forceFill(['deleted_at' => now()->subDays(45)])->save();

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertNull(CustomerAccount::withTrashed()->find($account->id));
        $this->assertNotNull(Tenant::find($tenant->id));
        $this->assertSame(0, DB::table('customer_account_user')->count());
        $this->assertSame(0, DB::table('customer_invitations')->count());
        $this->assertNull($customer->fresh()->current_customer_account_id);
    }

    public function test_superseded_domain_claims_are_only_purged_on_demand(): void
    {
        $previous = $this->createUser('previous@acme.com');
        $current = $this->createUser('current@acme.com');

        $historic = DomainClaim::query()->forceCreate([
            'user_id' => $previous->id,
            'domain' => 'acme.com',
            'token' => 'historic-token',
            'verified_at' => now()->subYear(),
            'superseded_at' => now()->subMonth(),
        ]);

        $active = DomainClaim::query()->forceCreate([
            'user_id' => $current->id,
            'domain' => 'acme.com',
            'token' => 'active-token',
            'verified_at' => now()->subMonth(),
        ]);

        $historic->recordActivity($previous, 'member:blocked');
        $active->recordActivity($current, 'domain:verified');

        // A plain purge keeps the historic tree...
        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertNotNull($historic->fresh());
        $this->assertSame(1, $historic->activities()->count());

        // The system administrator explicitly purges the history...
        $this->artisan('jetstream:purge', ['--domain-history' => true])->assertSuccessful();

        $this->assertNull($historic->fresh());
        $this->assertSame(0, DB::table('domain_activities')->where('domain_claim_id', $historic->id)->count());

        // The active tree is untouched...
        $this->assertNotNull($active->fresh());
        $this->assertSame(1, $active->activities()->count());
    }

    public function test_purging_a_user_erases_their_domain_claims_and_anonymizes_their_subject_activity(): void
    {
        $user = $this->createUser('leaving@acme.com');
        $admin = $this->createUser('admin@acme.com');

        $userClaim = DomainClaim::query()->forceCreate([
            'user_id' => $user->id,
            'domain' => 'acme.com',
            'token' => 'leaving-token',
            'verified_at' => now()->subYear(),
            'superseded_at' => now()->subMonth(),
        ]);

        $userClaim->recordActivity($user, 'domain:verified');

        $adminClaim = DomainClaim::query()->forceCreate([
            'user_id' => $admin->id,
            'domain' => 'acme.com',
            'token' => 'admin-token',
            'verified_at' => now()->subMonth(),
        ]);

        $activityAboutUser = $adminClaim->recordActivity($admin, 'member:blocked', $user);

        $user->delete();
        $user->forceFill(['deleted_at' => now()->subDays(45)])->save();

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertNull(DomainClaim::query()->find($userClaim->id));
        $this->assertSame(0, DB::table('domain_activities')->where('domain_claim_id', $userClaim->id)->count());

        $this->assertNotNull($adminClaim->fresh());
        $this->assertNull($activityAboutUser->fresh()->subject_id);
    }

    public function test_audit_logs_past_retention_are_pruned(): void
    {
        config(['jetstream.audit.retention_days' => 30]);

        $user = $this->createUser();

        AuditLog::query()->forceCreate([
            'event' => 'ancient',
            'created_at' => now()->subDays(60),
        ]);

        $this->artisan('jetstream:purge')->assertSuccessful();

        $this->assertFalse(AuditLog::query()->where('event', 'ancient')->exists());
        $this->assertTrue(AuditLog::query()->where('auditable_id', $user->id)->exists());
    }

    public function test_the_retention_period_can_be_overridden(): void
    {
        $user = $this->createUser();

        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Fresh', 'personal_team' => false]);

        $team->delete();

        $this->artisan('jetstream:purge', ['--days' => '0'])->assertSuccessful();

        $this->assertNull(Team::withTrashed()->find($team->id));
        $this->assertSame(0, DB::table('team_user')->count());
    }
}
