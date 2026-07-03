<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\AddTenantStaff;
use App\Actions\Jetstream\CreateCustomerAccount;
use App\Actions\Jetstream\CreateTeam;
use App\Actions\Jetstream\CreateTenant;
use App\Actions\Jetstream\DeleteCustomerAccount;
use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteTenant;
use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\InviteCustomer;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveCustomerAccountMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\RemoveTenantStaff;
use App\Actions\Jetstream\UpdateTeamName;
use App\Actions\Jetstream\UpdateTenantName;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Http\Middleware\EnsureCustomerAccountContext;
use Laravel\Jetstream\Http\Middleware\EnsureTenantContext;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();

        Jetstream::createTeamsUsing(CreateTeam::class);
        Jetstream::updateTeamNamesUsing(UpdateTeamName::class);
        Jetstream::addTeamMembersUsing(AddTeamMember::class);
        Jetstream::inviteTeamMembersUsing(InviteTeamMember::class);
        Jetstream::removeTeamMembersUsing(RemoveTeamMember::class);
        Jetstream::deleteTeamsUsing(DeleteTeam::class);
        Jetstream::deleteUsersUsing(DeleteUser::class);

        Jetstream::createTenantsUsing(CreateTenant::class);
        Jetstream::updateTenantNamesUsing(UpdateTenantName::class);
        Jetstream::addTenantStaffUsing(AddTenantStaff::class);
        Jetstream::removeTenantStaffUsing(RemoveTenantStaff::class);
        Jetstream::deleteTenantsUsing(DeleteTenant::class);

        Jetstream::createCustomerAccountsUsing(CreateCustomerAccount::class);
        Jetstream::inviteCustomersUsing(InviteCustomer::class);
        Jetstream::removeCustomerAccountMembersUsing(RemoveCustomerAccountMember::class);
        Jetstream::deleteCustomerAccountsUsing(DeleteCustomerAccount::class);

        // Keep tenant and customer context resolved across Livewire component updates.
        Livewire::addPersistentMiddleware([
            EnsureTenantContext::class,
            EnsureCustomerAccountContext::class,
        ]);
    }

    /**
     * Configure the roles and permissions that are available within the application.
     *
     * These roles are the application's defaults. The DefaultRolesSeeder copies
     * them into the roles table, where each tenant may override them or define
     * additional roles of their own.
     */
    protected function configurePermissions(): void
    {
        Jetstream::defaultApiTokenPermissions(['read']);

        Jetstream::permissions([
            'create',
            'read',
            'update',
            'delete',
            'tenant:update',
            'staff:manage',
            'roles:manage',
            'customers:manage',
        ]);

        Jetstream::role('admin', 'Administrator', [
            'create',
            'read',
            'update',
            'delete',
            'tenant:update',
            'staff:manage',
            'roles:manage',
            'customers:manage',
        ])->description('Administrator users can perform any action.');

        Jetstream::role('staff', 'Staff', [
            'create',
            'read',
            'update',
        ])->description('Staff members have the ability to read, create, and update.');
    }
}
