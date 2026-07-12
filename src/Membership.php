<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string|null $role
 */
abstract class Membership extends Pivot
{
    /**
     * The table associated with the pivot model.
     *
     * @var string|null
     */
    protected $table = 'team_user';
}
