<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Actions\VerifyDomainClaim;
use Laravel\Jetstream\Events\UserBlocked;
use Laravel\Jetstream\Events\UserUnblocked;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\Concerns\WithRateLimiting;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;

/**
 * Domain administration: claim email domains by publishing a verification
 * token (DNS TXT record or home page meta tag) and manage the verified
 * users of the domains you currently hold the admin flag for.
 *
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Jetstream\DomainClaim> $claims
 * @property-read \Laravel\Jetstream\DomainClaim|null $managedClaim
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $members
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Jetstream\DomainActivity> $activities
 */
class DomainAdminManager extends Component
{
    use WithRateLimiting;

    /**
     * The "claim domain" form state.
     *
     * @var array{domain: string}
     */
    public $domainForm = [
        'domain' => '',
    ];

    /**
     * The ID of the active claim whose domain members are being managed.
     *
     * @var string|null
     */
    public $managingClaimId = null;

    /**
     * Indicates if a member block is being confirmed.
     *
     * @var bool
     */
    public $confirmingMemberBlock = false;

    /**
     * The ID of the member being blocked.
     *
     * @var string|null
     */
    public $memberIdBeingBlocked = null;

    /**
     * The reason the member is being blocked.
     *
     * @var string
     */
    public $blockReason = '';

    /**
     * Start a claim for a domain, generating a unique verification token.
     *
     * In single domain mode the claim is always made for the domain part
     * of the user's own email address; in multi domain mode any domain
     * may be entered.
     *
     * @return void
     */
    public function startClaim()
    {
        $this->resetErrorBag();

        abort_unless($this->user->hasVerifiedEmail(), 403);

        $domain = Features::allowsMultipleDomains()
            ? strtolower(trim($this->domainForm['domain']))
            : ($this->user->emailDomain() ?? '');

        Validator::make(['domain' => $domain], [
            'domain' => ['required', 'string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
        ], [
            'domain.regex' => __('This is not a valid domain name.'),
        ])->validateWithBag('claimDomain');

        $claim = $this->user->domainClaims()->firstOrNew(['domain' => $domain]);

        if (! $claim->exists) {
            $claim->token = $claim::generateToken();

            $claim->save();
        }

        $this->domainForm = ['domain' => ''];

        $this->dispatch('saved');
    }

    /**
     * Check the given claim's verification token on its domain.
     *
     * A successful check hands this claim the domain admin flag and
     * supersedes any other verified claim for the same domain.
     *
     * @param  string  $claimId
     * @return void
     */
    public function checkClaim($claimId)
    {
        $this->resetErrorBag();

        abort_unless($this->user->hasVerifiedEmail(), 403);

        $claim = $this->user->domainClaims()->whereKey($claimId)->first();

        abort_if($claim === null, 404);

        $this->rateLimit('domain-check:'.$claim->id, maxAttempts: 10, decaySeconds: 60, errorBag: 'verification');

        if (! app(VerifyDomainClaim::class)->verify($claim)) {
            $this->addError('verification', __('We could not find the verification token for :domain. Publish the DNS TXT record or the meta tag shown below, then check again.', ['domain' => $claim->domain]));

            return;
        }

        $this->dispatch('saved');
    }

    /**
     * Start managing the members of the given active claim's domain.
     *
     * @param  string  $claimId
     * @return void
     */
    public function manageClaim($claimId)
    {
        $claim = $this->user->domainClaims()->whereKey($claimId)->first();

        abort_if($claim === null, 404);
        abort_unless($claim->isActive(), 403);

        $this->managingClaimId = $claim->id;
    }

    /**
     * Stop managing domain members.
     *
     * @return void
     */
    public function stopManaging()
    {
        $this->managingClaimId = null;
    }

    /**
     * Confirm that the given domain member should be blocked.
     *
     * @param  string  $userId
     * @return void
     */
    public function confirmMemberBlock($userId)
    {
        $this->resetErrorBag();

        $this->blockReason = '';

        $this->memberIdBeingBlocked = $userId;

        $this->confirmingMemberBlock = true;
    }

    /**
     * Block the domain member that is being confirmed.
     *
     * @return void
     */
    public function blockMember()
    {
        Validator::make(['reason' => $this->blockReason], [
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validateWithBag('blockMember');

        $claim = $this->managedClaimOrFail();

        $subject = Jetstream::findUserByIdOrFail($this->memberIdBeingBlocked ?? '');

        abort_unless($subject->emailDomain() === $claim->domain, 403);
        abort_unless($this->user->managesDomainUser($subject), 403);

        $subject->forceFill([
            'blocked_at' => now(),
            'blocked_reason' => $this->blockReason !== '' ? $this->blockReason : null,
        ])->save();

        UserBlocked::dispatch($subject);

        $claim->recordActivity($this->user, 'member:blocked', $subject, array_filter([
            'reason' => $this->blockReason !== '' ? $this->blockReason : null,
        ]));

        $this->confirmingMemberBlock = false;

        $this->memberIdBeingBlocked = null;

        $this->dispatch('saved');
    }

    /**
     * Unblock the given domain member.
     *
     * @param  string  $userId
     * @return void
     */
    public function unblockMember($userId)
    {
        $claim = $this->managedClaimOrFail();

        $subject = Jetstream::findUserByIdOrFail($userId);

        abort_unless($subject->emailDomain() === $claim->domain, 403);
        abort_unless($this->user->managesDomainUser($subject), 403);

        $subject->forceFill([
            'blocked_at' => null,
            'blocked_reason' => null,
        ])->save();

        UserUnblocked::dispatch($subject);

        $claim->recordActivity($this->user, 'member:unblocked', $subject);

        $this->dispatch('saved');
    }

    /**
     * Get the claim being managed, aborting unless it still holds the flag.
     *
     * @return \Laravel\Jetstream\DomainClaim
     */
    protected function managedClaimOrFail()
    {
        $claim = $this->user->domainClaims()->whereKey($this->managingClaimId ?? '')->first();

        abort_if($claim === null || ! $claim->isActive(), 403);

        return $claim;
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
     * Get all of the user's domain claims, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Jetstream\DomainClaim>
     */
    public function getClaimsProperty()
    {
        return $this->user->domainClaims()->latest()->get();
    }

    /**
     * Get the active claim whose members are being managed, if any.
     *
     * @return \Laravel\Jetstream\DomainClaim|null
     */
    public function getManagedClaimProperty()
    {
        if ($this->managingClaimId === null) {
            return null;
        }

        $claim = $this->user->domainClaims()->whereKey($this->managingClaimId)->first();

        return $claim !== null && $claim->isActive() ? $claim : null;
    }

    /**
     * Get the verified members of the managed domain.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    public function getMembersProperty()
    {
        $claim = $this->managedClaim;

        if ($claim === null) {
            return Jetstream::newUserModel()->newCollection();
        }

        return Jetstream::newUserModel()->newQuery()
            ->whereKeyNot($this->user->getKey())
            ->whereNotNull('email_verified_at')
            ->where('is_system_admin', false)
            ->where('email', 'like', '%@'.$claim->domain)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the recent activity recorded under the managed claim.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Jetstream\DomainActivity>
     */
    public function getActivitiesProperty()
    {
        $claim = $this->managedClaim;

        if ($claim === null) {
            return Jetstream::newDomainActivityModel()->newCollection();
        }

        return $claim->activities()->with(['user', 'subject'])->latest('created_at')->limit(25)->get();
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('domains.domain-admin-manager');
    }
}
