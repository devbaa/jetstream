<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class RecoveryEmailVerification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The user whose recovery email should be verified.
     *
     * @var \App\Models\User
     */
    public $user;

    /**
     * Create a new message instance.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $verifyUrl = URL::temporarySignedRoute('recovery-email.verify', now()->addMinutes(60), [
            'user' => $this->user->id,
            'hash' => sha1((string) $this->user->recovery_email),
        ]);

        return $this->markdown('emails.recovery-email-verification', ['verifyUrl' => $verifyUrl])
            ->subject(__('Verify Recovery Email'));
    }
}
