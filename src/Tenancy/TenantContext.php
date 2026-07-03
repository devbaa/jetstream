<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tenancy;

use Closure;
use Illuminate\Database\Eloquent\Model;

class TenantContext
{
    /**
     * The tenant that is currently in context.
     *
     * @var \Illuminate\Database\Eloquent\Model|null
     */
    protected $tenant;

    /**
     * Indicates if tenant scoping is currently bypassed.
     *
     * @var bool
     */
    protected $bypassed = false;

    /**
     * Set the tenant that is currently in context.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $tenant
     * @return void
     */
    public function set(?Model $tenant)
    {
        $this->tenant = $tenant;
    }

    /**
     * Get the tenant that is currently in context.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function current()
    {
        return $this->tenant;
    }

    /**
     * Get the primary key of the tenant that is currently in context.
     *
     * @return int|string|null
     */
    public function currentId()
    {
        $key = $this->tenant?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }

    /**
     * Forget the tenant that is currently in context.
     *
     * @return void
     */
    public function forget()
    {
        $this->tenant = null;
    }

    /**
     * Execute the given callback with tenant scoping bypassed.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public function bypass(Closure $callback)
    {
        $previous = $this->bypassed;

        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->bypassed = $previous;
        }
    }

    /**
     * Determine if tenant scoping is currently bypassed.
     *
     * @return bool
     */
    public function shouldBypass()
    {
        return $this->bypassed;
    }

    /**
     * Execute the given callback within the context of the given tenant.
     *
     * Queued jobs and other code running outside an HTTP request have no
     * tenant context and therefore run unscoped unless wrapped in this method.
     *
     * @template TReturn
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $tenant
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(?Model $tenant, Closure $callback)
    {
        $previous = $this->tenant;

        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
