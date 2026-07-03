<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Http\Controllers\AccountRecoveryController;
use Laravel\Jetstream\Http\Controllers\CurrentCustomerAccountController;
use Laravel\Jetstream\Http\Controllers\CurrentTeamController;
use Laravel\Jetstream\Http\Controllers\CurrentTenantController;
use Laravel\Jetstream\Http\Controllers\CustomerInvitationController;
use Laravel\Jetstream\Http\Controllers\Livewire\AdminAuditController;
use Laravel\Jetstream\Http\Controllers\Livewire\AdminTenantController;
use Laravel\Jetstream\Http\Controllers\Livewire\AdminUserController;
use Laravel\Jetstream\Http\Controllers\Livewire\ApiTokenController;
use Laravel\Jetstream\Http\Controllers\Livewire\CustomerRegistrationController;
use Laravel\Jetstream\Http\Controllers\Livewire\PortalController;
use Laravel\Jetstream\Http\Controllers\Livewire\PrivacyPolicyController;
use Laravel\Jetstream\Http\Controllers\Livewire\TeamController;
use Laravel\Jetstream\Http\Controllers\Livewire\TenantController;
use Laravel\Jetstream\Http\Controllers\Livewire\TenantCustomerController;
use Laravel\Jetstream\Http\Controllers\Livewire\TermsOfServiceController;
use Laravel\Jetstream\Http\Controllers\Livewire\UserProfileController;
use Laravel\Jetstream\Http\Controllers\RecoveryEmailVerificationController;
use Laravel\Jetstream\Http\Controllers\TeamInvitationController;
use Laravel\Jetstream\Jetstream;

Route::group(['middleware' => config('jetstream.middleware', ['web'])], function () {
    if (Jetstream::hasTermsAndPrivacyPolicyFeature()) {
        Route::get('/terms-of-service', [TermsOfServiceController::class, 'show'])->name('terms.show');
        Route::get('/privacy-policy', [PrivacyPolicyController::class, 'show'])->name('policy.show');
    }

    $guard = config('jetstream.guard');

    $authMiddleware = is_string($guard) && $guard !== ''
        ? 'auth:'.$guard
        : 'auth';

    $authSessionMiddleware = config('jetstream.auth_session', false)
        ? config('jetstream.auth_session')
        : null;

    Route::group(['middleware' => array_filter([$authMiddleware, $authSessionMiddleware, 'account.active', 'throttle:jetstream'])], function () {
        // User & Profile...
        Route::get('/user/profile', [UserProfileController::class, 'show'])->name('profile.show');

        Route::group(['middleware' => 'verified'], function () {
            // API...
            if (Jetstream::hasApiFeatures()) {
                Route::get('/user/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
            }

            // Teams...
            if (Jetstream::hasTeamFeatures()) {
                Route::group(['middleware' => Jetstream::hasTenantFeatures() ? ['tenant.context'] : []], function () {
                    Route::get('/teams/create', [TeamController::class, 'create'])->name('teams.create');
                    Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
                    Route::put('/current-team', [CurrentTeamController::class, 'update'])->name('current-team.update');
                });

                Route::get('/team-invitations/{invitation}', [TeamInvitationController::class, 'accept'])
                    ->middleware(['signed'])
                    ->name('team-invitations.accept');
            }

            // Tenants...
            if (Jetstream::hasTenantFeatures()) {
                Route::group(['middleware' => ['tenant.context']], function () {
                    Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
                    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
                    Route::put('/current-tenant', [CurrentTenantController::class, 'update'])->name('current-tenant.update');

                    Route::get('/tenants/{tenant}/customers', [TenantCustomerController::class, 'index'])->name('tenant-customers.index');
                });

                // System Administration...
                Route::group(['middleware' => ['system.admin']], function () {
                    Route::get('/admin/tenants', [AdminTenantController::class, 'index'])->name('admin.tenants.index');
                    Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
                    Route::get('/admin/audit', [AdminAuditController::class, 'index'])->name('admin.audit.index');
                });

                // Customer Portal...
                if (Jetstream::hasCustomerPortalFeatures()) {
                    Route::group(['middleware' => ['customer.context']], function () {
                        Route::get('/portal', [PortalController::class, 'show'])->name('portal.show');
                        Route::get('/portal/account', [PortalController::class, 'account'])->name('portal.account.show');
                        Route::put('/portal/current-account', [CurrentCustomerAccountController::class, 'update'])->name('current-customer-account.update');
                    });

                    Route::get('/customer-invitations/{invitation}', [CustomerInvitationController::class, 'accept'])
                        ->middleware(['signed'])
                        ->name('customer-invitations.accept');

                    Route::delete('/customer-invitations/{invitation}', [CustomerInvitationController::class, 'destroy'])
                        ->name('customer-invitations.destroy');
                }
            }
        });
    });

    // Customer Self-Registration...
    if (Jetstream::hasTenantFeatures() && Jetstream::hasCustomerPortalFeatures()) {
        Route::group(['middleware' => ['throttle:jetstream-guest']], function () {
            Route::get('/portal/register/{slug}', [CustomerRegistrationController::class, 'show'])->name('portal.register');
            Route::post('/portal/register/{slug}', [CustomerRegistrationController::class, 'store'])->name('portal.register.store');
        });
    }

    // Account Recovery...
    if (Jetstream::hasAccountRecoveryFeatures()) {
        Route::group(['middleware' => ['guest', 'throttle:jetstream-guest']], function () {
            Route::get('/account-recovery', [AccountRecoveryController::class, 'show'])->name('account-recovery.show');
            Route::post('/account-recovery', [AccountRecoveryController::class, 'store'])->name('account-recovery.store');
        });

        Route::get('/user/recovery-email/verify/{user}', [RecoveryEmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:jetstream-guest'])
            ->name('recovery-email.verify');
    }
});
