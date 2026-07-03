<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire\Admin;

use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\Events\UserBlocked;
use Laravel\Jetstream\Events\UserUnblocked;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;

/**
 * System administrator user management: block or unblock users system-wide
 * and reset lost second factors (two-factor authentication, passkeys).
 *
 * @property-read \App\Models\User $user
 */
class UserManager extends Component
{
    /**
     * The user search query.
     *
     * @var string
     */
    public $search = '';

    /**
     * Indicates if a user block is being confirmed.
     *
     * @var bool
     */
    public $confirmingUserBlock = false;

    /**
     * The ID of the user being blocked.
     *
     * @var int|null
     */
    public $userIdBeingBlocked = null;

    /**
     * The reason the user is being blocked.
     *
     * @var string
     */
    public $blockReason = '';

    /**
     * Confirm that the given user should be blocked.
     *
     * @param  int  $userId
     * @return void
     */
    public function confirmUserBlock($userId)
    {
        $this->resetErrorBag();

        $this->blockReason = '';

        $this->userIdBeingBlocked = $userId;

        $this->confirmingUserBlock = true;
    }

    /**
     * Block the user that is being confirmed from the entire application.
     *
     * @return void
     */
    public function blockUser()
    {
        Validator::make(['reason' => $this->blockReason], [
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validateWithBag('blockUser');

        $subject = Jetstream::findUserByIdOrFail($this->userIdBeingBlocked ?? 0);

        if ($subject->id === $this->user->id) {
            $this->addError('reason', __('You may not block your own account.'));

            return;
        }

        $subject->forceFill([
            'blocked_at' => now(),
            'blocked_reason' => $this->blockReason !== '' ? $this->blockReason : null,
        ])->save();

        UserBlocked::dispatch($subject);

        $this->confirmingUserBlock = false;

        $this->userIdBeingBlocked = null;

        $this->dispatch('saved');
    }

    /**
     * Unblock the given user.
     *
     * @param  int  $userId
     * @return void
     */
    public function unblockUser($userId)
    {
        $subject = Jetstream::findUserByIdOrFail($userId);

        $subject->forceFill([
            'blocked_at' => null,
            'blocked_reason' => null,
        ])->save();

        UserUnblocked::dispatch($subject);

        $this->dispatch('saved');
    }

    /**
     * Reset the given user's two-factor authentication.
     *
     * Use this to restore access for a user who lost both their
     * authenticator device and their recovery codes.
     *
     * @param  int  $userId
     * @return void
     */
    public function resetTwoFactorAuthentication($userId)
    {
        $subject = Jetstream::findUserByIdOrFail($userId);

        $subject->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->dispatch('saved');
    }

    /**
     * Delete all of the given user's passkeys.
     *
     * Use this to restore access for a user who lost the devices that hold
     * their passkeys.
     *
     * @param  int  $userId
     * @return void
     */
    public function resetPasskeys($userId)
    {
        $subject = Jetstream::findUserByIdOrFail($userId);

        $subject->passkeys()->delete();

        $this->dispatch('saved');
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
     * Get the users matching the current search query.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Foundation\Auth\User>
     */
    public function getUsersProperty()
    {
        return Jetstream::newUserModel()
            ->newQuery()
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', '%'.$this->search.'%')
                          ->orWhere('last_name', 'like', '%'.$this->search.'%')
                          ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('admin.user-manager');
    }
}
