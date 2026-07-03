<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Jetstream\Jetstream;

class RecoveryEmailVerificationController extends Controller
{
    /**
     * Mark the user's recovery email address as verified.
     *
     * The route is protected by a temporary signed URL that binds the user
     * and a hash of the address that was current when the link was sent.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function verify(Request $request, int $userId)
    {
        $user = Jetstream::findUserByIdOrFail($userId);

        $hash = $request->query('hash');

        abort_unless(
            is_string($user->recovery_email) &&
            is_string($hash) &&
            hash_equals(sha1($user->recovery_email), $hash),
            403
        );

        if ($user->recovery_email_verified_at === null) {
            $user->forceFill(['recovery_email_verified_at' => now()])->save();
        }

        return redirect(Jetstream::homePath())->banner(
            __('Your recovery email address has been verified.'),
        );
    }
}
