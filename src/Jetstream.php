<?php

declare(strict_types=1);

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
     * @var array<string, \Laravel\Jetstream\Role>
     */
    public static $roles = [];

    /**
     * The permissions that exist within the application.
     *
     * @var list<string>
     */
    public static $permissions = [];

    /**
     * The default permissions that should be available to new entities.
     *
     * @var list<string>
     */
    public static $defaultPermissions = [];

    /**
     * The user model that should be used by Jetstream.
     *
     * @var class-string<\Illuminate\Foundation\Auth\User>
     */
    public static $userModel = 'App\\Models\\User';

    /**
     * The team model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\Team>
     */
    public static $teamModel = 'App\\Models\\Team';

    /**
     * The membership model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\Membership>
     */
    public static $membershipModel = 'App\\Models\\Membership';

    /**
     * The team invitation model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\TeamInvitation>
     */
    public static $teamInvitationModel = 'App\\Models\\TeamInvitation';

    /**
     * The tenant model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\Tenant>
     */
    public static $tenantModel = 'App\\Models\\Tenant';

    /**
     * The tenant membership model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\TenantMembership>
     */
    public static $tenantMembershipModel = 'App\\Models\\TenantMembership';

    /**
     * The database role model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\DatabaseRole>
     */
    public static $roleModel = 'App\\Models\\Role';

    /**
     * The customer account model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\CustomerAccount>
     */
    public static $customerAccountModel = 'App\\Models\\CustomerAccount';

    /**
     * The customer invitation model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\CustomerInvitation>
     */
    public static $customerInvitationModel = 'App\\Models\\CustomerInvitation';

    /**
     * The audit log model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\AuditLog>
     */
    public static $auditLogModel = 'App\\Models\\AuditLog';

    /**
     * The data request model that should be used by Jetstream.
     *
     * @var class-string<\Laravel\Jetstream\DataRequest>
     */
    public static $dataRequestModel = 'App\\Models\\DataRequest';

    /**
     * The callback that determines if the current request may bypass rate limiting.
     *
     * @var (\Closure(\Illuminate\Http\Request): bool)|null
     */
    public static $bypassesThrottlingUsing = null;

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
     * @param  \Laravel\Jetstream\Tenant|null  $tenant
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
     * @param  list<string>  $permissions
     * @return \Laravel\Jetstream\Role
     */
    public static function role(string $key, string $name, array $permissions)
    {
        static::$permissions = array_values(collect(array_merge(static::$permissions, $permissions))
                                    ->unique()
                                    ->sort()
                                    ->all());

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
     * @param  list<string>  $permissions
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
     * @param  list<string>  $permissions
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
     * @param  array<int, string>  $permissions
     * @return list<string>
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
     * @param  \Illuminate\Database\Eloquent\Model  $user
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
     * @param  \Illuminate\Database\Eloquent\Model  $user
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
     * Determine if the application lets users exercise their data rights.
     *
     * @return bool
     */
    public static function hasDataPrivacyFeatures()
    {
        return Features::hasDataPrivacyFeatures();
    }

    /**
     * Determine if the application supports account recovery channels.
     *
     * @return bool
     */
    public static function hasAccountRecoveryFeatures()
    {
        return Features::hasAccountRecoveryFeatures();
    }

    /**
     * Get the application's post-authentication home path.
     */
    public static function homePath(): string
    {
        $home = config('fortify.home');

        return is_string($home) ? $home : '/dashboard';
    }

    /**
     * Get the currently authenticated user or abort the request.
     *
     * @return \App\Models\User
     */
    public static function currentUser()
    {
        $user = auth()->user();

        if (! $user instanceof \App\Models\User) {
            abort(401);
        }

        return $user;
    }

    /**
     * Find a user instance by the given ID.
     *
     * @param  string  $id
     * @return \App\Models\User
     */
    public static function findUserByIdOrFail($id)
    {
        $user = static::newUserModel()->newQuery()->where('id', $id)->firstOrFail();

        abort_unless($user instanceof \App\Models\User, 500);

        return $user;
    }

    /**
     * Find a user instance by the given email address or fail.
     *
     * @param  string  $email
     * @return \App\Models\User
     */
    public static function findUserByEmailOrFail(string $email)
    {
        $user = static::newUserModel()->newQuery()->where('email', $email)->firstOrFail();

        abort_unless($user instanceof \App\Models\User, 500);

        return $user;
    }

    /**
     * Get the name of the user model used by the application.
     *
     * @return class-string<\Illuminate\Foundation\Auth\User>
     */
    public static function userModel()
    {
        return static::$userModel;
    }

    /**
     * Get a new instance of the user model.
     *
     * @return \Illuminate\Foundation\Auth\User
     */
    public static function newUserModel()
    {
        $model = static::userModel();

        return new $model;
    }

    /**
     * Specify the user model that should be used by Jetstream.
     *
     * @param  class-string<\Illuminate\Foundation\Auth\User>  $model
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
     * @return class-string<\Laravel\Jetstream\Team>
     */
    public static function teamModel()
    {
        return static::$teamModel;
    }

    /**
     * Get a new instance of the team model.
     *
     * @return \Laravel\Jetstream\Team
     */
    public static function newTeamModel()
    {
        $model = static::teamModel();

        return new $model;
    }

    /**
     * Specify the team model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\Team>  $model
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
     * @return class-string<\Laravel\Jetstream\Membership>
     */
    public static function membershipModel()
    {
        return static::$membershipModel;
    }

    /**
     * Specify the membership model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\Membership>  $model
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
     * @return class-string<\Laravel\Jetstream\TeamInvitation>
     */
    public static function teamInvitationModel()
    {
        return static::$teamInvitationModel;
    }

    /**
     * Specify the team invitation model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\TeamInvitation>  $model
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
     * @return class-string<\Laravel\Jetstream\Tenant>
     */
    public static function tenantModel()
    {
        return static::$tenantModel;
    }

    /**
     * Get a new instance of the tenant model.
     *
     * @return \Laravel\Jetstream\Tenant
     */
    public static function newTenantModel()
    {
        $model = static::tenantModel();

        return new $model;
    }

    /**
     * Specify the tenant model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\Tenant>  $model
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
     * @return class-string<\Laravel\Jetstream\TenantMembership>
     */
    public static function tenantMembershipModel()
    {
        return static::$tenantMembershipModel;
    }

    /**
     * Specify the tenant membership model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\TenantMembership>  $model
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
     * @return class-string<\Laravel\Jetstream\DatabaseRole>
     */
    public static function roleModel()
    {
        return static::$roleModel;
    }

    /**
     * Get a new instance of the database role model.
     *
     * @return \Laravel\Jetstream\DatabaseRole
     */
    public static function newRoleModel()
    {
        $model = static::roleModel();

        return new $model;
    }

    /**
     * Specify the database role model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\DatabaseRole>  $model
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
     * @return class-string<\Laravel\Jetstream\CustomerAccount>
     */
    public static function customerAccountModel()
    {
        return static::$customerAccountModel;
    }

    /**
     * Get a new instance of the customer account model.
     *
     * @return \Laravel\Jetstream\CustomerAccount
     */
    public static function newCustomerAccountModel()
    {
        $model = static::customerAccountModel();

        return new $model;
    }

    /**
     * Specify the customer account model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\CustomerAccount>  $model
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
     * @return class-string<\Laravel\Jetstream\CustomerInvitation>
     */
    public static function customerInvitationModel()
    {
        return static::$customerInvitationModel;
    }

    /**
     * Specify the customer invitation model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\CustomerInvitation>  $model
     * @return static
     */
    public static function useCustomerInvitationModel(string $model)
    {
        static::$customerInvitationModel = $model;

        return new static;
    }

    /**
     * Get the name of the audit log model used by the application.
     *
     * @return class-string<\Laravel\Jetstream\AuditLog>
     */
    public static function auditLogModel()
    {
        return static::$auditLogModel;
    }

    /**
     * Get a new instance of the audit log model.
     *
     * @return \Laravel\Jetstream\AuditLog
     */
    public static function newAuditLogModel()
    {
        $model = static::auditLogModel();

        return new $model;
    }

    /**
     * Specify the audit log model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\AuditLog>  $model
     * @return static
     */
    public static function useAuditLogModel(string $model)
    {
        static::$auditLogModel = $model;

        return new static;
    }

    /**
     * Get the name of the data request model used by the application.
     *
     * @return class-string<\Laravel\Jetstream\DataRequest>
     */
    public static function dataRequestModel()
    {
        return static::$dataRequestModel;
    }

    /**
     * Get a new instance of the data request model.
     *
     * @return \Laravel\Jetstream\DataRequest
     */
    public static function newDataRequestModel()
    {
        $model = static::dataRequestModel();

        return new $model;
    }

    /**
     * Specify the data request model that should be used by Jetstream.
     *
     * @param  class-string<\Laravel\Jetstream\DataRequest>  $model
     * @return static
     */
    public static function useDataRequestModel(string $model)
    {
        static::$dataRequestModel = $model;

        return new static;
    }

    /**
     * Register a callback that determines if a request may bypass Jetstream's rate limiting.
     *
     * @param  \Closure(\Illuminate\Http\Request): bool  $callback
     * @return static
     */
    public static function bypassThrottlingUsing(\Closure $callback)
    {
        static::$bypassesThrottlingUsing = $callback;

        return new static;
    }

    /**
     * Determine if the given request may bypass Jetstream's rate limiting.
     *
     * System administrators, IP addresses listed in the "jetstream.throttle.bypass_ips"
     * configuration option, and requests approved by the "bypassThrottlingUsing"
     * callback are never throttled.
     */
    public static function bypassesThrottling(\Illuminate\Http\Request $request): bool
    {
        $user = $request->user();

        if ($user instanceof \App\Models\User && $user->isSystemAdmin()) {
            return true;
        }

        $bypassIps = config('jetstream.throttle.bypass_ips', []);

        if (is_array($bypassIps) && in_array($request->ip(), $bypassIps, true)) {
            return true;
        }

        if (static::$bypassesThrottlingUsing !== null) {
            return (static::$bypassesThrottlingUsing)($request) === true;
        }

        return false;
    }

    /**
     * Register a class / callback that should be used to create teams.
     *
     * @param  string  $class
     * @return void
     */
    public static function createTeamsUsing(string $class)
    {
        app()->singleton(CreatesTeams::class, $class);
    }

    /**
     * Register a class / callback that should be used to update team names.
     *
     * @param  string  $class
     * @return void
     */
    public static function updateTeamNamesUsing(string $class)
    {
        app()->singleton(UpdatesTeamNames::class, $class);
    }

    /**
     * Register a class / callback that should be used to add team members.
     *
     * @param  string  $class
     * @return void
     */
    public static function addTeamMembersUsing(string $class)
    {
        app()->singleton(AddsTeamMembers::class, $class);
    }

    /**
     * Register a class / callback that should be used to add team members.
     *
     * @param  string  $class
     * @return void
     */
    public static function inviteTeamMembersUsing(string $class)
    {
        app()->singleton(InvitesTeamMembers::class, $class);
    }

    /**
     * Register a class / callback that should be used to remove team members.
     *
     * @param  string  $class
     * @return void
     */
    public static function removeTeamMembersUsing(string $class)
    {
        app()->singleton(RemovesTeamMembers::class, $class);
    }

    /**
     * Register a class / callback that should be used to delete teams.
     *
     * @param  string  $class
     * @return void
     */
    public static function deleteTeamsUsing(string $class)
    {
        app()->singleton(DeletesTeams::class, $class);
    }

    /**
     * Register a class / callback that should be used to delete users.
     *
     * @param  string  $class
     * @return void
     */
    public static function deleteUsersUsing(string $class)
    {
        app()->singleton(DeletesUsers::class, $class);
    }

    /**
     * Register a class / callback that should be used to create tenants.
     *
     * @param  string  $class
     * @return void
     */
    public static function createTenantsUsing(string $class)
    {
        app()->singleton(CreatesTenants::class, $class);
    }

    /**
     * Register a class / callback that should be used to update tenant names.
     *
     * @param  string  $class
     * @return void
     */
    public static function updateTenantNamesUsing(string $class)
    {
        app()->singleton(UpdatesTenantNames::class, $class);
    }

    /**
     * Register a class / callback that should be used to add tenant staff.
     *
     * @param  string  $class
     * @return void
     */
    public static function addTenantStaffUsing(string $class)
    {
        app()->singleton(AddsTenantStaff::class, $class);
    }

    /**
     * Register a class / callback that should be used to remove tenant staff.
     *
     * @param  string  $class
     * @return void
     */
    public static function removeTenantStaffUsing(string $class)
    {
        app()->singleton(RemovesTenantStaff::class, $class);
    }

    /**
     * Register a class / callback that should be used to delete tenants.
     *
     * @param  string  $class
     * @return void
     */
    public static function deleteTenantsUsing(string $class)
    {
        app()->singleton(DeletesTenants::class, $class);
    }

    /**
     * Register a class / callback that should be used to create customer accounts.
     *
     * @param  string  $class
     * @return void
     */
    public static function createCustomerAccountsUsing(string $class)
    {
        app()->singleton(CreatesCustomerAccounts::class, $class);
    }

    /**
     * Register a class / callback that should be used to invite customers.
     *
     * @param  string  $class
     * @return void
     */
    public static function inviteCustomersUsing(string $class)
    {
        app()->singleton(InvitesCustomers::class, $class);
    }

    /**
     * Register a class / callback that should be used to remove customer account members.
     *
     * @param  string  $class
     * @return void
     */
    public static function removeCustomerAccountMembersUsing(string $class)
    {
        app()->singleton(RemovesCustomerAccountMembers::class, $class);
    }

    /**
     * Register a class / callback that should be used to delete customer accounts.
     *
     * @param  string  $class
     * @return void
     */
    public static function deleteCustomerAccountsUsing(string $class)
    {
        app()->singleton(DeletesCustomerAccounts::class, $class);
    }

    /**
     * Register a class / callback that should be used to send phone verification codes.
     *
     * @param  string  $class
     * @return void
     */
    public static function verifyPhonesUsing(string $class)
    {
        app()->singleton(Contracts\SendsPhoneVerifications::class, $class);
    }

    /**
     * Determine if a phone verification service has been registered.
     *
     * When no service is registered, users may enter a phone number but it
     * cannot be verified.
     */
    public static function phoneVerificationEnabled(): bool
    {
        return app()->bound(Contracts\SendsPhoneVerifications::class);
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
