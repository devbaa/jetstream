<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use App\Models\User as BaseUser;

class User extends BaseUser
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];
}
