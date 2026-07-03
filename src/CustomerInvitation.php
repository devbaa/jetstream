<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $customer_account_id
 * @property string $email
 */
abstract class CustomerInvitation extends Model
{
    use HasUuids;

    use BelongsToTenant;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Laravel\Jetstream\CustomerAccount, $this>
     */
    public function customerAccount()
    {
        return $this->belongsTo(Jetstream::customerAccountModel());
    }
}
