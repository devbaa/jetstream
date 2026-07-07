<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Laravel\Jetstream\ConfirmsPasswords;
use Laravel\Jetstream\DataRequest;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lets a user exercise their data rights (GDPR / CCPA / KVKK): download a
 * copy of their personal data and request the deletion of their account.
 *
 * @property-read \App\Models\User $user
 * @property-read \Laravel\Jetstream\DataRequest|null $pendingDeletionRequest
 */
class DataPrivacyForm extends Component
{
    use ConfirmsPasswords;

    /**
     * Indicates if the user is confirming an account deletion request.
     *
     * @var bool
     */
    public $confirmingDeletionRequest = false;

    /**
     * The reason the user gave for requesting deletion, if any.
     *
     * @var string
     */
    public $reason = '';

    /**
     * Start confirming the account deletion request.
     *
     * @return void
     */
    public function confirmDeletionRequest()
    {
        $this->resetErrorBag();

        $this->confirmingDeletionRequest = true;
    }

    /**
     * Request the deletion of the user's account.
     *
     * The account is soft deleted by the jetstream:purge command once the
     * configured grace period has elapsed, and permanently erased after the
     * purge retention period.
     *
     * @return void
     */
    public function requestAccountDeletion()
    {
        $this->resetErrorBag();

        $this->ensurePasswordIsConfirmed();

        $this->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($this->pendingDeletionRequest !== null) {
            $this->addError('reason', __('You already have a pending deletion request.'));

            return;
        }

        $request = Jetstream::newDataRequestModel();

        $request->forceFill([
            'user_id' => $this->user->id,
            'type' => DataRequest::TYPE_DELETION,
            'status' => DataRequest::STATUS_PENDING,
            'reason' => $this->reason !== '' ? $this->reason : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'process_after' => now()->addDays($this->gracePeriodDays()),
        ])->save();

        $this->confirmingDeletionRequest = false;

        $this->reason = '';

        $this->dispatch('saved');
    }

    /**
     * Cancel the user's pending account deletion request.
     *
     * @return void
     */
    public function cancelAccountDeletion()
    {
        $this->pendingDeletionRequest?->markCancelled();

        $this->dispatch('saved');
    }

    /**
     * Download a copy of the user's personal data.
     */
    public function downloadPersonalData(): StreamedResponse
    {
        $user = $this->user;

        $request = Jetstream::newDataRequestModel();

        $request->forceFill([
            'user_id' => $user->id,
            'type' => DataRequest::TYPE_EXPORT,
            'status' => DataRequest::STATUS_COMPLETED,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'completed_at' => now(),
        ])->save();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => $user->withoutRelations()->attributesToArray(),
            'teams' => $user->allTeams()->map(fn ($team): array => [
                'id' => $team->id,
                'name' => $team->name,
            ])->values()->all(),
            'tenants' => Jetstream::userHasTenantFeatures($user)
                ? $user->allTenants()->map(fn ($tenant): array => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ])->values()->all()
                : [],
            'customer_accounts' => Jetstream::userHasTenantFeatures($user)
                ? $user->allCustomerAccounts()->map(fn ($account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                ])->values()->all()
                : [],
            'domain_claims' => Jetstream::hasDomainAdminFeatures()
                ? $user->domainClaims()->get()->map(fn ($claim): array => [
                    'domain' => $claim->domain,
                    'method' => $claim->method,
                    'verified_at' => $claim->verified_at?->toIso8601String(),
                    'superseded_at' => $claim->superseded_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'data_requests' => Jetstream::newDataRequestModel()->newQuery()
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (DataRequest $dataRequest): array => $dataRequest->withoutRelations()->attributesToArray())
                ->values()
                ->all(),
            'activity' => Jetstream::newAuditLogModel()->newQuery()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(1000)
                ->get()
                ->map(fn ($log): array => [
                    'event' => $log->event,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];

        return response()->streamDownload(function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, 'personal-data.json', ['Content-Type' => 'application/json']);
    }

    /**
     * Get the number of days before a deletion request is processed.
     */
    protected function gracePeriodDays(): int
    {
        $days = config('jetstream.privacy.grace_period_days', 30);

        return is_int($days) ? max(0, $days) : 30;
    }

    /**
     * Get the user's pending deletion request, if any.
     *
     * @return \Laravel\Jetstream\DataRequest|null
     */
    public function getPendingDeletionRequestProperty()
    {
        return Jetstream::newDataRequestModel()->newQuery()
            ->where('user_id', $this->user->id)
            ->where('type', DataRequest::TYPE_DELETION)
            ->where('status', DataRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * Get the current user of the application.
     *
     * @return mixed
     */
    public function getUserProperty()
    {
        return Jetstream::currentUser();
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('profile.data-privacy-form');
    }
}
