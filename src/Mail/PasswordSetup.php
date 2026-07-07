<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class PasswordSetup extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The newly created user.
     *
     * @var \App\Models\User
     */
    public $user;

    /**
     * The password reset token.
     *
     * @var string
     */
    public $token;

    /**
     * Create a new message instance.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function __construct($user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $resetUrl = URL::route('password.reset', [
            'token' => $this->token,
            'email' => $this->user->email,
        ]);

        return $this->markdown('emails.password-setup', ['resetUrl' => $resetUrl])
            ->subject(__('Set Your Password'));
    }
}
