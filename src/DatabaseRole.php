<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Tenancy\BelongsToTenant;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property array<int, string> $permissions
 */
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
     * @var list<string>
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
     * @var array<string, string>
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
