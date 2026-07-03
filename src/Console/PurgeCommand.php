<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Laravel\Jetstream\DataRequest;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tenancy\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jetstream:purge')]
class PurgeCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jetstream:purge
                            {--days= : Override the configured retention period in days}
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process due data deletion requests, permanently purge soft-deleted records past retention, and prune expired audit logs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $cutoff = now()->subDays($this->retentionDays());

        app(TenantContext::class)->bypass(function () use ($cutoff): void {
            $this->processDataDeletionRequests();
            $this->purgeTrashedUsers($cutoff);
            $this->purgeTrashedTenants($cutoff);
            $this->purgeTrashedTeams($cutoff);
            $this->purgeTrashedCustomerAccounts($cutoff);
            $this->pruneAuditLogs();
        });

        return self::SUCCESS;
    }

    /**
     * Get the number of days that soft-deleted records are retained.
     */
    protected function retentionDays(): int
    {
        $days = $this->option('days');

        if (is_string($days) && $days !== '') {
            return max(0, (int) $days);
        }

        $configured = config('jetstream.purge.retention_days', 30);

        return is_int($configured) ? max(0, $configured) : 30;
    }

    /**
     * Soft delete the users behind due data deletion requests.
     *
     * The user then follows the normal soft-delete retention window before
     * being permanently erased.
     */
    protected function processDataDeletionRequests(): void
    {
        if (! Schema::hasTable('data_requests') || ! app()->bound(DeletesUsers::class)) {
            return;
        }

        $requests = Jetstream::newDataRequestModel()->newQuery()->dueForDeletion()->get();

        foreach ($requests as $request) {
            $user = Jetstream::newUserModel()->newQuery()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->find($request->user_id);

            if ($user !== null && $user->getAttribute('deleted_at') === null) {
                app(DeletesUsers::class)->delete($user);
            }

            $request->markCompleted();
        }

        $this->components->info(sprintf('Processed %d due data deletion request(s).', $requests->count()));
    }

    /**
     * Permanently erase users that were soft deleted before the cutoff.
     */
    protected function purgeTrashedUsers(Carbon $cutoff): void
    {
        $users = Jetstream::newUserModel()->newQuery()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        foreach ($users as $user) {
            if ($user instanceof \App\Models\User) {
                $this->purgeUser($user);
            }
        }

        $this->components->info(sprintf('Purged %d user(s).', $users->count()));
    }

    /**
     * Permanently erase the given user and every resource they own.
     */
    protected function purgeUser(\App\Models\User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->deleteProfilePhoto();

            $user->tokens()->delete();

            $user->passkeys()->delete();

            $user->teams()->detach();

            if (Jetstream::hasTenantFeatures()) {
                $user->tenants()->detach();
                $user->customerAccounts()->detach();
                $user->ownedTenants()->withTrashed()->get()->each->purge();
                $user->ownedCustomerAccounts()->withTrashed()->get()->each->purge();
            }

            $user->ownedTeams()->withTrashed()->get()->each->purge();

            if (Schema::hasTable('data_requests')) {
                Jetstream::newDataRequestModel()->newQuery()
                    ->where('user_id', $user->id)
                    ->where('status', DataRequest::STATUS_PENDING)
                    ->update(['status' => DataRequest::STATUS_COMPLETED, 'completed_at' => now()]);
            }

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            $user->forceDelete();

            if (Schema::hasTable('audit_logs')) {
                // After the force delete has been recorded, erase every log entry
                // about the user and anonymize the entries they authored.
                Jetstream::newAuditLogModel()->newQuery()
                    ->where('auditable_type', $user->getMorphClass())
                    ->where('auditable_id', $user->id)
                    ->delete();

                Jetstream::newAuditLogModel()->newQuery()
                    ->where('user_id', $user->id)
                    ->update(['user_id' => null]);
            }
        });
    }

    /**
     * Permanently purge tenants that were soft deleted before the cutoff.
     */
    protected function purgeTrashedTenants(Carbon $cutoff): void
    {
        if (! Jetstream::hasTenantFeatures()) {
            return;
        }

        $tenants = Jetstream::newTenantModel()->newQuery()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        $tenants->each->purge();

        $this->components->info(sprintf('Purged %d tenant(s).', $tenants->count()));
    }

    /**
     * Permanently purge teams that were soft deleted before the cutoff.
     */
    protected function purgeTrashedTeams(Carbon $cutoff): void
    {
        if (! Jetstream::hasTeamFeatures()) {
            return;
        }

        $teams = Jetstream::newTeamModel()->newQuery()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        $teams->each->purge();

        $this->components->info(sprintf('Purged %d team(s).', $teams->count()));
    }

    /**
     * Permanently purge customer accounts that were soft deleted before the cutoff.
     */
    protected function purgeTrashedCustomerAccounts(Carbon $cutoff): void
    {
        if (! Jetstream::hasTenantFeatures()) {
            return;
        }

        $accounts = Jetstream::newCustomerAccountModel()->newQuery()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        $accounts->each->purge();

        $this->components->info(sprintf('Purged %d customer account(s).', $accounts->count()));
    }

    /**
     * Prune audit log entries that are past the configured retention period.
     */
    protected function pruneAuditLogs(): void
    {
        $days = config('jetstream.audit.retention_days');

        if (! is_int($days) || $days <= 0 || ! Schema::hasTable('audit_logs')) {
            return;
        }

        $pruned = Jetstream::newAuditLogModel()->newQuery()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->components->info(sprintf('Pruned %d audit log entry(ies).', is_int($pruned) ? $pruned : 0));
    }
}
