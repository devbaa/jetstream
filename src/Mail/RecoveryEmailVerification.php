<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class RecoveryEmailVerification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The user whose recovery email should be verified.
     *
     * @var \App\Models\User
     */
    public $user;

    /**
     * The address this message verifies.
     *
     * @var string
     */
    public $recoveryEmail;

    /**
     * Create a new message instance.
     *
     * The address is captured here rather than read back off the user when
     * the message is built, because the message is delivered by a queue
     * worker: reading it later would pick up whatever address the user
     * happens to have on record by then, so a message already on its way to
     * the previous address could carry a link verifying a newer one.
     *
     * The link itself is still signed at build time. Signing it here instead
     * would put a working credential in the queue payload and start its
     * expiry when the message was enqueued rather than when it was sent.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function __construct($user)
    {
        $this->user = $user;
        $this->recoveryEmail = (string) $user->recovery_email;
    }

    /**
     * Build the message.
     *
     * The hash binds the link to the address that was current when the
     * message was created. Verification compares it against the address on
     * record at the time the link is followed, so a link for a superseded
     * address is inert.
     *
     * @return $this
     */
    public function build()
    {
        $verifyUrl = URL::temporarySignedRoute('recovery-email.verify', now()->addMinutes(60), [
            'user' => $this->user->id,
            'hash' => sha1($this->recoveryEmail),
        ]);

        return $this->markdown('emails.recovery-email-verification', ['verifyUrl' => $verifyUrl])
            ->subject(__('Verify Recovery Email'));
    }
}
