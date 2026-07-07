<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\DeleteUser;
use App\Models\AuditLog;
use App\Models\DataRequest;
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
