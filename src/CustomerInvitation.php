<?php

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Tenancy\BelongsToTenant;

abstract class CustomerInvitation extends Model
{
    use BelongsToTenant;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'email',
    ];

    /**
     * Get the customer account that the invitation belongs to, if any.
     *
     * Invitations without a customer account invite the recipient to create
     * a fresh account of their own within the tenant.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function customerAccount()
    {
        return $this->belongsTo(Jetstream::customerAccountModel());
    }
}
