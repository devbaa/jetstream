<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Audit\Auditable;

/**
 * A conventional Eloquent model, exactly as the README's example writes it.
 *
 * Nothing about it is Jetstream's: an auto-incrementing key, no UUID trait,
 * no tenancy. It is the shape "drop Auditable onto any Eloquent model" is a
 * promise about.
 */
class Invoice extends Model
{
    use Auditable;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /** {@inheritdoc} */
    public $timestamps = false;
}
