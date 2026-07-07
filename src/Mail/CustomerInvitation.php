<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Laravel\Jetstream\CustomerInvitation as CustomerInvitationModel;

class CustomerInvitation extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The customer invitation instance.
     *
     * @var \Laravel\Jetstream\CustomerInvitation
     */
    public $invitation;

    /**
     * Create a new message instance.
     *
     * @param  \Laravel\Jetstream\CustomerInvitation  $invitation
     * @return void
     */
    public function __construct(CustomerInvitationModel $invitation)
    {
        $this->invitation = $invitation;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->markdown('emails.customer-invitation', ['acceptUrl' => URL::signedRoute('customer-invitations.accept', [
            'invitation' => $this->invitation,
        ])])->subject(__('Customer Invitation'));
    }
}
