<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
     * that the endpoint cannot be used to enumerate recovery addresses.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = Jetstream::newUserModel()->newQuery()
            ->where('recovery_email', $request->string('email')->toString())
            ->whereNotNull('recovery_email_verified_at')
            ->first();

        $broker = Password::broker();

        if ($user instanceof \App\Models\User && $broker instanceof \Illuminate\Auth\Passwords\PasswordBroker) {
            $token = $broker->createToken($user);

            Mail::to($user->recovery_email)->send(new AccountRecovery($user, $token));
        }

        return back()->with('status', __('If that address is registered as a verified recovery email, we have sent it a password reset link.'));
    }
}
