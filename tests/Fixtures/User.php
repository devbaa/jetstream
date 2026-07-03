<?php

namespace Laravel\Jetstream\Tests\Fixtures;

use App\Models\User as BaseUser;
use Laravel\Jetstream\HasCustomerAccounts;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Jetstream\HasTenants;
use Laravel\Sanctum\HasApiTokens;

class User extends BaseUser
{
    use HasApiTokens, HasCustomerAccounts, HasTeams, HasTenants, HasProfilePhoto;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];
}
