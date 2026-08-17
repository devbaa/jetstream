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
     * The signed link that verifies the address this message is sent to.
     *
     * @var string
     */
    public $verifyUrl;

    /**
     * Create a new message instance.
     *
     * The link is signed here rather than while the message is being built,
     * because the message is delivered by a queue worker: building it later
     * would sign whatever address the user happens to have on record by
     * then, so a message already on its way to the previous address could
     * carry a link verifying a newer one.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function __construct($user)
    {
        $this->user = $user;

        $this->verifyUrl = URL::temporarySignedRoute('recovery-email.verify', now()->addMinutes(60), [
            'user' => $user->id,
            'hash' => sha1((string) $user->recovery_email),
        ]);
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown('emails.recovery-email-verification', ['verifyUrl' => $this->verifyUrl])
            ->subject(__('Verify Recovery Email'));
    }
}
