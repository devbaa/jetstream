<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Rules;

use Illuminate\Contracts\Validation\Rule;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\RoleRegistry;
use Laravel\Jetstream\Tenancy\TenantContext;

class Role implements Rule
{
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
            return ! is_null(app(RoleRegistry::class)->find(
                $value, app(TenantContext::class)->currentId()
            ));
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
