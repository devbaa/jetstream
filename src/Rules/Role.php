<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;
use Laravel\Jetstream\Tenancy\TenantContext;

class Role implements Rule
{
    /**
     * The tenant whose roles the value is checked against.
     *
     * @var \Illuminate\Database\Eloquent\Model|int|string|null
     */
    protected $tenant;

    /**
     * Indicates that a tenant was named rather than left to the context.
     *
     * @var bool
     */
    protected $targeted = false;

    /**
     * Check the role against the roles of the thing being changed.
     *
     * Roles are per-tenant: each tenant may override the application's
     * defaults and define keys of its own. Which tenant's roles apply is
     * therefore a property of the record being written, not of whoever is
     * writing it — a user who belongs to two tenants has one ambient
     * "current" tenant and can still be handed an explicit tenant or team to
     * act on, and when those disagree the ambient one is wrong in both
     * directions: it turns away roles the target really has and accepts roles
     * the target has never heard of.
     *
     * The tenant is named, not guessed from the argument's type: a team is not
     * a tenant and carries one, so a caller holding a team passes
     * $team->tenant_id. That may be null for a personal team, which is a
     * target and not an absence of one — it resolves to the roles every tenant
     * shares rather than to whatever tenant happens to be in context.
     *
     * @param  \Illuminate\Database\Eloquent\Model|int|string|null  $tenant
     * @return static
     */
    public static function for($tenant)
    {
        $rule = new static;

        $rule->tenant = $tenant;
        $rule->targeted = true;

        return $rule;
    }

    /**
     * The key of the tenant this rule checks against.
     *
     * Without an explicit target the request's current tenant stands in. That
     * is a legacy compatibility path and not a safe default: it is only ever
     * consulted when tenant features are enabled — passes() answers from the
     * static roles otherwise and never asks — which is exactly the
     * configuration where the ambient tenant and the record being changed can
     * disagree.
     *
     * It is kept because the action stubs are copied into the application at
     * install time and are never replaced by upgrading this package, so an
     * application published before Role::for() exists still constructs the
     * rule with no argument. Failing closed there would turn this into a
     * breaking upgrade. Every call site inside this package, and every newly
     * published stub, names its target.
     *
     * @return int|string|null
     */
    public function tenantId()
    {
        if (! $this->targeted) {
            return app(TenantContext::class)->currentId();
        }

        $tenant = $this->tenant;

        if ($tenant instanceof Model) {
            $key = $tenant->getKey();

            return is_int($key) || is_string($key) ? $key : null;
        }

        return is_int($tenant) || is_string($tenant) ? $tenant : null;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if (! is_string($value)) {
            return false;
        }

        if (Features::hasTenantFeatures()) {
            return ! is_null(app(RoleRegistry::class)->find($value, $this->tenantId()));
        }

        return in_array($value, array_keys(Jetstream::$roles), true);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return __('The :attribute must be a valid role.');
    }
}
