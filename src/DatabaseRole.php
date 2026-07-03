<?php

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Tenancy\BelongsToTenant;

abstract class DatabaseRole extends Model
{
    use BelongsToTenant;

    /**
     * Indicates that rows without a tenant remain visible in tenant context.
     *
     * Roles with a null tenant are the application's default roles and act
     * as a base set that every tenant may override or extend.
     *
     * @var bool
     */
    public $tenantOptional = true;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'roles';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'description',
        'permissions',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'permissions' => 'array',
    ];

    /**
     * Convert the database role to the role value object used throughout Jetstream.
     *
     * @return \Laravel\Jetstream\Role
     */
    public function toRole()
    {
        return tap(new Role($this->key, $this->name, (array) $this->permissions), function ($role) {
            $role->description((string) $this->description);
        });
    }
}
