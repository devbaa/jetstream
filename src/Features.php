<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

class Features
{
    /**
     * Determine if the given feature is enabled.
     *
     * @param  string  $feature
     * @return bool
     */
    public static function enabled(string $feature)
    {
        $features = config('jetstream.features', []);

        return is_array($features) && in_array($feature, $features, true);
    }

    /**
     * Determine if the feature is enabled and has a given option enabled.
     *
     * @param  string  $feature
     * @param  string  $option
     * @return bool
     */
    public static function optionEnabled(string $feature, string $option)
    {
        return static::enabled($feature) &&
               config("jetstream-options.{$feature}.{$option}") === true;
    }

    /**
     * Determine if the application is allowing profile photo uploads.
     *
     * @return bool
     */
    public static function managesProfilePhotos()
    {
        return static::enabled(static::profilePhotos());
    }

    /**
     * Determine if the application is using any API features.
     *
     * @return bool
     */
    public static function hasApiFeatures()
    {
        return static::enabled(static::api());
    }

    /**
     * Determine if the application is using any team features.
     *
     * @return bool
     */
    public static function hasTeamFeatures()
    {
        return static::enabled(static::teams());
    }

    /**
     * Determine if invitations are sent to team members.
     *
     * @return bool
     */
    public static function sendsTeamInvitations()
    {
        return static::optionEnabled(static::teams(), 'invitations');
    }

    /**
     * Determine if the application is using any tenant features.
     *
     * @return bool
     */
    public static function hasTenantFeatures()
    {
        return static::enabled(static::tenants());
    }

    /**
     * Determine if the application is serving a customer portal.
     *
     * @return bool
     */
    public static function hasCustomerPortalFeatures()
    {
        return static::optionEnabled(static::tenants(), 'portal');
    }

    /**
     * Determine if tenants may allow customers to self-register.
     *
     * @return bool
     */
    public static function allowsCustomerRegistration()
    {
        return static::optionEnabled(static::tenants(), 'customer-registration');
    }

    /**
     * Determine if the application lets users exercise their data rights.
     *
     * @return bool
     */
    public static function hasDataPrivacyFeatures()
    {
        return static::enabled(static::dataPrivacy());
    }

    /**
     * Determine if the application supports account recovery channels.
     *
     * @return bool
     */
    public static function hasAccountRecoveryFeatures()
    {
        return static::enabled(static::accountRecovery());
    }

    /**
     * Determine if the application has terms of service / privacy policy confirmation enabled.
     *
     * @return bool
     */
    public static function hasTermsAndPrivacyPolicyFeature()
    {
        return static::enabled(static::termsAndPrivacyPolicy());
    }

    /**
     * Determine if the application is using any account deletion features.
     *
     * @return bool
     */
    public static function hasAccountDeletionFeatures()
    {
        return static::enabled(static::accountDeletion());
    }

    /**
     * Enable the profile photo upload feature.
     *
     * @return string
     */
    public static function profilePhotos()
    {
        return 'profile-photos';
    }

    /**
     * Enable the API feature.
     *
     * @return string
     */
    public static function api()
    {
        return 'api';
    }

    /**
     * Enable the teams feature.
     *
     * @param  array<string, bool>  $options
     * @return string
     */
    public static function teams(array $options = [])
    {
        if ($options !== []) {
            config(['jetstream-options.teams' => $options]);
        }

        return 'teams';
    }

    /**
     * Enable the multi-tenant SaaS feature.
     *
     * @param  array<string, bool>  $options
     * @return string
     */
    public static function tenants(array $options = [])
    {
        if ($options !== []) {
            config(['jetstream-options.tenants' => $options]);
        }

        return 'tenants';
    }

    /**
     * Enable the terms of service and privacy policy feature.
     *
     * @return string
     */
    public static function termsAndPrivacyPolicy()
    {
        return 'terms';
    }

    /**
     * Enable the account deletion feature.
     *
     * @return string
     */
    public static function accountDeletion()
    {
        return 'account-deletion';
    }

    /**
     * Enable the data privacy feature (data export and deletion requests).
     *
     * @return string
     */
    public static function dataPrivacy()
    {
        return 'data-privacy';
    }

    /**
     * Enable the account recovery feature (phone and recovery email).
     *
     * @return string
     */
    public static function accountRecovery()
    {
        return 'account-recovery';
    }

    /**
     * Determine if the application is using the domain admin feature.
     *
     * @return bool
     */
    public static function hasDomainAdminFeatures()
    {
        return static::enabled(static::domainAdmin());
    }

    /**
     * Determine if domain admins may claim domains beyond their own email domain.
     *
     * @return bool
     */
    public static function allowsMultipleDomains()
    {
        return static::optionEnabled(static::domainAdmin(), 'multi-domain');
    }

    /**
     * Enable the domain admin feature.
     *
     * @param  array<string, bool>  $options
     * @return string
     */
    public static function domainAdmin(array $options = [])
    {
        if ($options !== []) {
            config(['jetstream-options.domain-admin' => $options]);
        }

        return 'domain-admin';
    }
}
