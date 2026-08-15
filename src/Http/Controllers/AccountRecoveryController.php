<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\AccountRecovery;

class AccountRecoveryController extends Controller
{
    /**
     * Show the account recovery form.
     *
     * @return \Illuminate\View\View
     */
    public function show()
    {
        return view('auth.recover-account');
    }

    /**
     * Send a password reset link to the user's verified recovery email.
     *
     * The response is identical whether or not a matching account exists so
     * that the endpoint cannot be used to enumerate recovery addresses, and
     * the mail is queued rather than delivered inline so that a matching
     * address does not make the request measurably slower.
     *
     * The submitted address is reduced to the same canonical form recovery
     * addresses are stored in, so the lookup succeeds whatever casing (or
     * surrounding whitespace) the user typed — including on databases that
     * compare strings case-sensitively.
     *
     * Nothing constrains a recovery address to a single account, so the
     * address may identify more than one. Rather than let the database's row
     * order decide which account a recovery link unlocks, an ambiguous
     * address issues no token at all and is reported to the operators.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $recoveryEmail = Jetstream::normalizeEmail($request->string('email')->toString());

        $user = $recoveryEmail === null ? null : $this->userForRecovery($recoveryEmail);

        $broker = Password::broker();

        if ($user instanceof \App\Models\User && $broker instanceof \Illuminate\Auth\Passwords\PasswordBroker) {
            $token = $broker->createToken($user);

            Mail::to($user->recovery_email)->queue(new AccountRecovery($user, $token));
        }

        return back()->with('status', __('If that address is registered as a verified recovery email, we have sent it a password reset link.'));
    }

    /**
     * Find the one account the given recovery address unambiguously identifies.
     *
     * Recovery addresses carry no uniqueness constraint, so two accounts can
     * hold the same verified address — either because both were entered that
     * way, or because canonicalization collapsed two spellings of one
     * address into the same value.
     *
     * Recovering "whichever row the database returned first" would make the
     * outcome depend on query order and could leave the other account
     * permanently unable to recover, so an ambiguous address recovers
     * nothing. The requester cannot tell: the response is the same generic
     * one every submission receives.
     *
     * @return \App\Models\User|null
     */
    protected function userForRecovery(string $recoveryEmail)
    {
        $users = Jetstream::newUserModel()->newQuery()
            ->where('recovery_email', $recoveryEmail)
            ->whereNotNull('recovery_email_verified_at')
            ->get();

        if ($users->count() > 1) {
            // The address itself is left out of the log: the user ids are
            // enough to resolve the conflict and do not put a recovery
            // address into the application's log files.
            Log::warning('Account recovery was requested for a recovery email address that is verified on more than one account. No recovery link was sent. Resolve the duplicate addresses so these accounts can be recovered.', [
                'user_ids' => $users->modelKeys(),
            ]);

            return null;
        }

        $user = $users->first();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
