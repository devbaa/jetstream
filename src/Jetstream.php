<?php

namespace Laravel\Jetstream;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use Laravel\Jetstream\Contracts\AddsTenantStaff;
use Laravel\Jetstream\Contracts\CreatesCustomerAccounts;
use Laravel\Jetstream\Contracts\CreatesTeams;
use Laravel\Jetstream\Contracts\CreatesTenants;
use Laravel\Jetstream\Contracts\DeletesCustomerAccounts;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesTenants;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Laravel\Jetstream\Contracts\InvitesCustomers;
use Laravel\Jetstream\Contracts\InvitesTeamMembers;
use Laravel\Jetstream\Contracts\RemovesCustomerAccountMembers;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Contracts\RemovesTenantStaff;
use Laravel\Jetstream\Contracts\UpdatesTeamNames;
use Laravel\Jetstream\Contracts\UpdatesTenantNames;
use Laravel\Jetstream\RoleRegistry;
use Laravel\Jetstream\Tenancy\TenantContext;

class Jetstream
{
    /**
     * Indicates if Jetstream routes will be registered.
     *
     * @var bool
     */
    public static $registersRoutes = true;

    /**
     * The roles that are available to assign to users.
     *
     * @var array
     */
    public static $roles = [];

    /**
     * The permissions that exist within the application.
     *
     * @var array
     */
    public static $permissions = [];

    /**
     * The default permissions that should be available to new entities.
     *
     * @var array
     */
    public static $defaultPermissions = [];

    /**
     * The user model that should be used by Jetstream.
     *
     * @var string
     */
    public static $userModel = 'App\\Models\\User';

    /**
     * The team model that should be used by Jetstream.
     *
     * @var string
     */
    public static $teamModel = 'App\\Models\\Team';

    /**
     * The membership model that should be used by Jetstream.
     *
     * @var string
     */
    public static $membershipModel = 'App\\Models\\Membership';

    /**
     * The team invitation model that should be used by Jetstream.
     *
     * @var string
     */
    public static $teamInvitationModel = 'App\\Models\\TeamInvitation';

    /**
     * The tenant model that should be used by Jetstream.
     *
     * @var string
     */
    public static $tenantModel = 'App\\Models\\Tenant';

    /**
     * The tenant membership model that should be used by Jetstream.
     *
     * @var string
     */
    public static $tenantMembershipModel = 'App\\Models\\TenantMembership';

    /**
     * The database role model that should be used by Jetstream.
     *
     * @var string
     */
    public static $roleModel = 'App\\Models\\Role';

    /**
     * The customer account model that should be used by Jetstream.
     *
     * @var string
     */
    public static $customerAccountModel = 'App\\Models\\CustomerAccount';

    /**
     * The customer invitation model that should be used by Jetstream.
     *
     * @var string
     */
    public static $customerInvitationModel = 'App\\Models\\CustomerInvitation';

    /**
     * The Inertia manager instance.
     *
     * @var \Laravel\Jetstream\InertiaManager
     */
    public static $inertiaManager;

    /**
     * Determine if Jetstream has registered roles.
     *
     * @return bool
     */
    public static function hasRoles()
    {
        return count(static::$roles) > 0;
    }

    /**
     * Find the role with the given key.
     *
     * When tenant features are enabled, roles are resolved from the database
     * for the given tenant (or the tenant currently in context), falling back
     * to the statically registered roles.
     *
     * @param  string  $key
     * @param  mixed  $tenant
     * @return \Laravel\Jetstream\Role|null
     */
    public static function findRole(string $key, $tenant = null)
    {
        if (Features::hasTenantFeatures()) {
            return app(RoleRegistry::class)->find(
                $key, $tenant->id ?? app(TenantContext::class)->currentId()
            );
        }

        return static::$roles[$key] ?? null;
    }

    /**
     * Define a role.
     *
     * @param  string  $key
     * @param  string  $name
     * @param  array  $permissions
     * @return \Laravel\Jetstream\Role
     */
    public static function role(string $key, string $name, array $permissions)
    {
        static::$permissions = collect(array_merge(static::$permissions, $permissions))
                                    ->unique()
                                    ->sort()
                                    ->values()
                                    ->all();

        return tap(new Role($key, $name, $permissions), function ($role) use ($key) {
            static::$roles[$key] = $role;
        });
    }

    /**
     * Determine if any permissions have been registered with Jetstream.
     *
     * @return bool
     */
    public static function hasPermissions()
    {
        return count(static::$permissions) > 0;
    }

    /**
     * Define the available API token permissions.
     *
     * @param  array  $permissions
     * @return static
     */
    public static function permissions(array $permissions)
    {
        static::$permissions = $permissions;

        return new static;
    }

    /**
     * Define the default permissions that should be available to new API tokens.
     *
     * @param  array  $permissions
     * @return static
     */
    public static function defaultApiTokenPermissions(array $permissions)
    {
        static::$defaultPermissions = $permissions;

        return new static;
    }

    /**
     * Return the permissions in the given list that are actually defined permissions for the application.
     *
     * @param  array  $permissions
     * @return array
     */
    public static function validPermissions(array $permissions)
    {
        return array_values(array_intersect($permissions, static::$permissions));
    }

    /**
     * Determine if Jetstream is managing profile photos.
     *
     * @return bool
     */
    public static function managesProfilePhotos()
    {
        return Features::managesProfilePhotos();
    }

    /**
     * Determine if Jetstream is supporting API features.
     *
     * @return bool
     */
    public static function hasApiFeatures()
    {
        return Features::hasApiFeatures();
    }

    /**
     * Determine if Jetstream is supporting team features.
     *
     * @return bool
     */
    public static function hasTeamFeatures()
    {
        return Features::hasTeamFeatures();
    }

    /**
     * Determine if a given user model utilizes the "HasTeams" trait.
     *
     * @param  \Illuminate\Database\Eloquent\Model
     * @return bool
     */
    public static function userHasTeamFeatures($user)
    {
        return (array_key_exists(HasTeams::class, class_uses_recursive($user)) ||
                method_exists($user, 'currentTeam')) &&
                static::hasTeamFeatures();
    }

    /**
     * Determine if Jetstream is supporting tenant features.
     *
     * @return bool
     */
    public static function hasTenantFeatures()
    {
        return Features::hasTenantFeatures();
    }

    /**
     * Determine if Jetstream is serving a customer portal.
     *
     * @return bool
     */
    public static function hasCustomerPortalFeatures()
    {
        return Features::hasCustomerPortalFeatures();
    }

    /**
     * Determine if a given user model utilizes the "HasTenants" trait.
     *
     * @param  \Illuminate\Database\Eloquent\Model
     * @return bool
     */
    public static function userHasTenantFeatures($user)
    {
        return (array_key_exists(HasTenants::class, class_uses_recursive($user)) ||
                method_exists($user, 'currentTenant')) &&
                static::hasTenantFeatures();
    }

    /**
     * Determine if the application is using the terms confirmation feature.
     *
     * @return bool
     */
    public static function hasTermsAndPrivacyPolicyFeature()
    {
        return Features::hasTermsAndPrivacyPolicyFeature();
    }

    /**
     * Determine if the application is using any account deletion features.
     *
     * @return bool
     */
    public static function hasAccountDeletionFeatures()
    {
        return Features::hasAccountDeletionFeatures();
    }

    /**
     * Find a user instance by the given ID.
     *
     * @param  int  $id
     * @return mixed
     */
    public static function findUserByIdOrFail($id)
    {
        return static::newUserModel()->where('id', $id)->firstOrFail();
    }

    /**
     * Find a user instance by the given email address or fail.
     *
     * @param  string  $email
     * @return mixed
     */
    public static function findUserByEmailOrFail(string $email)
    {
        return static::newUserModel()->where('email', $email)->firstOrFail();
    }

    /**
     * Get the name of the user model used by the application.
     *
     * @return string
     */
    public static function userModel()
    {
        return static::$userModel;
    }

    /**
     * Get a new instance of the user model.
     *
     * @return mixed
     */
    public static function newUserModel()
    {
        $model = static::userModel();

        return new $model;
    }

    /**
     * Specify the user model that should be used by Jetstream.
     *
     * @param  string  $model
     * @return static
     */
    public static function useUserModel(string $model)
    {
        static::$userModel = $model;

        return new static;
    }

    /**
     * Get the name of the team model used by the application.
     *
     * @return string
     */
    public static function teamModel()
    {
        return static::$teamModel;
    }

    /**
     * Get a new instance of the team model.
     *
     * @return mixed
     */
    public static function newTeamModel()
    {
        $model = static::teamModel();

        return new $model;
    }

    /**
     * Specify the team model that should be used by Jetstream.
     *
     * @param  string  $model
     * @return static
     */
    public static function useTeamModel(string $model)
    {
        static::$teamModel = $model;

        return new static;
    }

    /**
     * Get the name of the membership model used by the application.
     *
     * @return string
     */
    public static function membershipModel()
    {
        return static::$membershipModel;
    }

    /**
     * Specify the membership model that should be used by Jetstream.
     *
     * @param  string  $model
     * @return static
     */
    public static function useMembershipModel(string $model)
    {
        static::$membershipModel = $model;

        return new static;
    }

    /**
     * Get the name of the team invitation model used by the application.
     *
     * @return string
     */
    public static function teamInvitationModel()
    {
        return static::$teamInvitationModel;
    }

    /**
     * Specify the team invitation model that should be used by Jetstream.
     *
     * @param  string  $model
     * @return static
     */
    public static function useTeamInvitationModel(string $model)
    {
        static::$teamInvitationModel = $model;

        return new static;
    }

    /**
     * Get the name of the tenant model used by the application.
     *
     * @return string
     */
    public static function tenantModel()
    {
        return static::$tenantModel;
    }

    /**
     * Get a new instance of the tenant model.
     *
     * @return mixed
     */
    public static function newTenantModel()
    {
        $model = static::tenantModel();

        return new $model;
    }

    /**
     * Specify the tenant model that should be used by Jetstream.
     *
     * @param  string  $model
     * @return static
     */
    public static function useTenantModel(string $model)
    {
        static::$tenantModel = $model;

        return new static;
    }

    /**
     * Get the name of the tenant membership model used by the application.
     *
     * @return string
     */
    public static function tenantMembershipModel()
    {
        return static::$tenantMembershipModel;
    }

    /**
     * Specify the tenant membership model that should be used by Jetstream.
     *
     * @param  string  $model
     * @return static
     */
    public static function useTenantMembershipModel(string $model)
    {
        static::$tenantMembershipModel = $model;

        return new static;
    }

    /**
     * Get the name of the database role model used by the application.
     *
     * @return string
     */
    public static function roleModel()
    {
        return static::$roleModel;
    }

    /**
     * Get a new instance of the database role model.
     *
     * @return mixed
     */
    public static function newRoleModel()
    {
        $model = static::roleModel();

        return new $model;
    }

    /**
     * Specify the database role model that should be used by Jetstream.
     *
     * @param  string  $model
     * @return static
     */
    public static function useRoleModel(string $model)
    {
        static::$roleModel = $model;

        return new static;
    }

    /**
     * Get the name of the customer account model used by the application.
     *
     * @return string
     */
    public static function customerAccountModel()
    {
        return static::$customerAccountModel;
    }

    /**
     * Get a new instance of the customer account model.
     *
     * @return mixed
     */
    public static function newCustomerAccountModel()
    {
        $model = static::customerAccountModel();

        return new $model;
    }

    /**
     * Specify the customer account model that should be used by Jetstream.
     *
     * @param  string  $model
     * @return static
     */
    public static function useCustomerAccountModel(string $model)
    {
        static::$customerAccountModel = $model;

        return new static;
    }

    /**
     * Get the name of the customer invitation model used by the application.
     *
     * @return string
     */
    public static function customerInvitationModel()
    {
        return static::$customerInvitationModel;
    }

    /**
     * Specify the customer invitation model that should be used by Jetstream.
     *
     * @param  string  $model
     * @return static
     */
    public static function useCustomerInvitationModel(string $model)
    {
        static::$customerInvitationModel = $model;

        return new static;
    }

    /**
     * Register a class / callback that should be used to create teams.
     *
     * @param  string  $class
     * @return void
     */
    public static function createTeamsUsing(string $class)
    {
        return app()->singleton(CreatesTeams::class, $class);
    }

    /**
     * Register a class / callback that should be used to update team names.
     *
     * @param  string  $class
     * @return void
     */
    public static function updateTeamNamesUsing(string $class)
    {
        return app()->singleton(UpdatesTeamNames::class, $class);
    }

    /**
     * Register a class / callback that should be used to add team members.
     *
     * @param  string  $class
     * @return void
     */
    public static function addTeamMembersUsing(string $class)
    {
        return app()->singleton(AddsTeamMembers::class, $class);
    }

    /**
     * Register a class / callback that should be used to add team members.
     *
     * @param  string  $class
     * @return void
     */
    public static function inviteTeamMembersUsing(string $class)
    {
        return app()->singleton(InvitesTeamMembers::class, $class);
    }

    /**
     * Register a class / callback that should be used to remove team members.
     *
     * @param  string  $class
     * @return void
     */
    public static function removeTeamMembersUsing(string $class)
    {
        return app()->singleton(RemovesTeamMembers::class, $class);
    }

    /**
     * Register a class / callback that should be used to delete teams.
     *
     * @param  string  $class
     * @return void
     */
    public static function deleteTeamsUsing(string $class)
    {
        return app()->singleton(DeletesTeams::class, $class);
    }

    /**
     * Register a class / callback that should be used to delete users.
     *
     * @param  string  $class
     * @return void
     */
    public static function deleteUsersUsing(string $class)
    {
        return app()->singleton(DeletesUsers::class, $class);
    }

    /**
     * Register a class / callback that should be used to create tenants.
     *
     * @param  string  $class
     * @return void
     */
    public static function createTenantsUsing(string $class)
    {
        return app()->singleton(CreatesTenants::class, $class);
    }

    /**
     * Register a class / callback that should be used to update tenant names.
     *
     * @param  string  $class
     * @return void
     */
    public static function updateTenantNamesUsing(string $class)
    {
        return app()->singleton(UpdatesTenantNames::class, $class);
    }

    /**
     * Register a class / callback that should be used to add tenant staff.
     *
     * @param  string  $class
     * @return void
     */
    public static function addTenantStaffUsing(string $class)
    {
        return app()->singleton(AddsTenantStaff::class, $class);
    }

    /**
     * Register a class / callback that should be used to remove tenant staff.
     *
     * @param  string  $class
     * @return void
     */
    public static function removeTenantStaffUsing(string $class)
    {
        return app()->singleton(RemovesTenantStaff::class, $class);
    }

    /**
     * Register a class / callback that should be used to delete tenants.
     *
     * @param  string  $class
     * @return void
     */
    public static function deleteTenantsUsing(string $class)
    {
        return app()->singleton(DeletesTenants::class, $class);
    }

    /**
     * Register a class / callback that should be used to create customer accounts.
     *
     * @param  string  $class
     * @return void
     */
    public static function createCustomerAccountsUsing(string $class)
    {
        return app()->singleton(CreatesCustomerAccounts::class, $class);
    }

    /**
     * Register a class / callback that should be used to invite customers.
     *
     * @param  string  $class
     * @return void
     */
    public static function inviteCustomersUsing(string $class)
    {
        return app()->singleton(InvitesCustomers::class, $class);
    }

    /**
     * Register a class / callback that should be used to remove customer account members.
     *
     * @param  string  $class
     * @return void
     */
    public static function removeCustomerAccountMembersUsing(string $class)
    {
        return app()->singleton(RemovesCustomerAccountMembers::class, $class);
    }

    /**
     * Register a class / callback that should be used to delete customer accounts.
     *
     * @param  string  $class
     * @return void
     */
    public static function deleteCustomerAccountsUsing(string $class)
    {
        return app()->singleton(DeletesCustomerAccounts::class, $class);
    }

    /**
     * Manage Jetstream's Inertia settings.
     *
     * @return \Laravel\Jetstream\InertiaManager
     */
    public static function inertia()
    {
        if (is_null(static::$inertiaManager)) {
            static::$inertiaManager = new InertiaManager;
        }

        return static::$inertiaManager;
    }

    /**
     * Find the path to a localized Markdown resource.
     *
     * @param  string  $name
     * @return string|null
     */
    public static function localizedMarkdownPath($name)
    {
        $localName = preg_replace('#(\.md)$#i', '.'.app()->getLocale().'$1', $name);

        return Arr::first([
            resource_path('markdown/'.$localName),
            resource_path('markdown/'.$name),
        ], function ($path) {
            return file_exists($path);
        });
    }

    /**
     * Configure Jetstream to not register its routes.
     *
     * @return static
     */
    public static function ignoreRoutes()
    {
        static::$registersRoutes = false;

        return new static;
    }
}
