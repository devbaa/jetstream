<p align="center"><img src="/art/logo.svg" alt="Logo Laravel Jetstream"></p>

# Jetstream SaaS

An opinionated, Livewire-only fork of [Laravel Jetstream](https://jetstream.laravel.com) that ships a complete, production-shaped multi-tenant SaaS out of the box. One install command scaffolds three actors, database-backed roles, a customer portal, compliance tooling (audit log, GDPR flows, soft-delete + purge), account security (passkeys, 2FA, recovery channels), moderation (blocking & freezing), and an in-app help center — all on **Laravel 13+ / PHP 8.4+** with UUID v7 keys, `declare(strict_types=1)` everywhere, and Larastan at level max.

This fork does **not** track upstream Jetstream releases; it is a self-contained starter.

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Architecture at a glance](#architecture-at-a-glance)
- [The three actors](#the-three-actors)
- [Tenancy & scoping](#tenancy--scoping)
- [Roles & permissions](#roles--permissions)
- [Customer portal](#customer-portal)
- [Account security](#account-security)
  - [Passwords & two-factor authentication](#passwords--two-factor-authentication)
  - [Passkeys](#passkeys)
  - [Account recovery — recovery email & phone](#account-recovery--recovery-email--phone)
- [Blocking & freezing](#blocking--freezing)
- [Domain admin mode](#domain-admin-mode)
- [Compliance & operations](#compliance--operations)
  - [Universal audit log](#universal-audit-log)
  - [Soft deletes & the purge command](#soft-deletes--the-purge-command)
  - [Data rights (GDPR / CCPA / KVKK)](#data-rights-gdpr--ccpa--kvkk)
  - [Throttling with bypass](#throttling-with-bypass)
- [Names](#names)
- [UUID v7 primary keys](#uuid-v7-primary-keys)
- [Registration honeypot](#registration-honeypot)
- [In-app help center](#in-app-help-center)
- [Configuration reference](#configuration-reference)
- [Extension points](#extension-points)
- [Database schema](#database-schema)
- [Testing & quality gates](#testing--quality-gates)
- [Upgrade notes](#upgrade-notes)
- [License](#license)

---

## Requirements

| Dependency | Version |
| --- | --- |
| PHP | ^8.4 |
| Laravel | ^13.0 |
| Livewire | ^3.6 |
| Fortify | ^1.37 (passkeys) |
| Stack | Livewire only (Inertia is not supported) |

---

## Installation

```bash
laravel new my-saas
cd my-saas

# Point Composer at this fork and require it.
composer config repositories.jetstream vcs https://github.com/devbaa/jetstream
composer require laravel/jetstream:"dev-<branch> as 6.0"

# Scaffold everything (Livewire is the only supported stack).
php artisan jetstream:install livewire

# Run migrations and seed default roles + the system administrator.
php artisan migrate --seed
```

Flag your own user as the system administrator by setting `JETSTREAM_ADMIN_EMAIL` in `.env`, then (after registering that user) run:

```bash
php artisan db:seed --class=SystemAdminSeeder
```

Schedule the purge command so soft-deleted records and due deletion requests are processed automatically (in `routes/console.php` or your scheduler):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('jetstream:purge')->daily();
```

### Install flags

`php artisan jetstream:install livewire` accepts:

| Flag | Effect |
| --- | --- |
| `--api` | Enables Sanctum API token management. |
| `--verification` | Enables email verification. |
| `--pest` | Generates Pest tests instead of PHPUnit. |
| `--dark` | Keeps the dark-mode Tailwind classes. |

The SaaS architecture (tenants, roles, customer portal, compliance, security) is always installed — there is a single install path.

---

## Architecture at a glance

```
System owner (is_system_admin)
│   /admin/tenants · /admin/users · /admin/audit
│
├── Tenant (organization)                       ← paying customer of the SaaS
│   ├── Owner (protected role)
│   ├── Staff (tenant_user, DB-backed roles, freezable)
│   ├── Teams (Jetstream teams, nested via teams.tenant_id)
│   └── Customer accounts (freezable)
│       └── Members (customer_account_user)
│
└── Users table (single, shared)
    A person can be staff of tenant A AND a customer of tenant B at once.
```

Everything lives in **one database** with `tenant_id` scoping. Two persistent context columns on `users` — `current_tenant_id` (staff context) and `current_customer_account_id` (customer context) — mirror Jetstream's `current_team_id`. The active context is chosen by URL space: app routes run `tenant.context`; `/portal/*` runs `customer.context`.

---

## The three actors

| Actor | What they are | Entry points |
| --- | --- | --- |
| **System owner** | The SaaS operator. `users.is_system_admin`. | `system.admin` middleware, `/admin/tenants`, `/admin/users`, `/admin/audit`. |
| **Tenant** | An organization with an owner, staff, roles, and sub-teams. | Tenant switcher in nav, `/tenants/{tenant}` settings. |
| **Customer** | Mostly a single user, sometimes a small shared account, belonging to a tenant. | `/portal` with an account switcher. |

Because there is one `users` table, "being a customer" is a *relationship*, not an identity — the same person can own tenant A, work as staff for tenant B, and be a customer of tenant C simultaneously.

---

## Tenancy & scoping

Single-database tenancy is enforced by a small, explicit toolkit:

- **`TenantContext`** — a request-scoped (Octane/queue-safe) singleton holding the active tenant. Resolved by the `tenant.context` middleware, which self-heals stale state (revoked access, cross-tenant `current_team_id`).
- **`BelongsToTenant`** — a trait for your own domain models. It adds a global scope (only rows for the current tenant) and auto-fills `tenant_id` on create. This is the main reusability payoff — drop it on any model:

  ```php
  use Laravel\Jetstream\Tenancy\BelongsToTenant;

  class Invoice extends Model
  {
      use BelongsToTenant;
  }
  ```

- **Explicit escape hatches** — nothing is magic:

  ```php
  // Run a closure with the tenant scope disabled (admin screens).
  app(TenantContext::class)->bypass(fn () => Tenant::all());

  // Run a queued job in a known tenant's context.
  app(TenantContext::class)->runFor($tenant, fn () => /* ... */);

  // One-off unscoped query.
  Invoice::withoutTenancy()->get();
  ```

> **Queued jobs run with an empty context** (the scope no-ops). Always wrap tenant-specific job work in `TenantContext::runFor($tenant, …)`.

Teams are deliberately **not** globally scoped (that would break personal teams and `currentTeam()`); team access stays relation- and policy-constrained.

---

## Roles & permissions

Roles are **database-backed** in Jetstream's `{key, name, permissions[]}` shape:

- The `roles` table holds application defaults where `tenant_id IS NULL` (seeded from your code catalog by `DefaultRolesSeeder`). A tenant may **override** a default key or **add** custom roles; resolution is `tenant row → default row → static Jetstream::$roles` via a request-memoized `RoleRegistry`.
- Tenant owners manage roles through a Livewire `RoleManager` on the organization settings screen: create custom roles, edit defaults (which transparently creates a per-tenant copy), tick permissions.
- The `owner` key is **reserved** and always has full access (a synthetic `OwnerRole`). A role that is still assigned to staff cannot be deleted.

The default catalog (customizable in your published `App\Providers\JetstreamServiceProvider`):

```php
Jetstream::permissions([
    'create', 'read', 'update', 'delete',
    'tenant:update', 'staff:manage', 'roles:manage', 'customers:manage',
]);

Jetstream::role('admin', 'Administrator', [/* all */]);
Jetstream::role('staff', 'Staff', ['create', 'read', 'update']);
```

Permission checks:

```php
$user->hasTenantPermission($tenant, 'staff:manage');   // owner ⇒ always true
$user->tenantRole($tenant);                            // Role value object or OwnerRole
```

Frozen tenants and frozen memberships deny **every** permission (see [Blocking & freezing](#blocking--freezing)).

---

## Customer portal

When the `portal` option is enabled, tenants get a full customer side:

- **Customer accounts** (`customer_accounts`): a single user or a small shared group. No "type" column — a solo account is simply one with no extra members.
- **Invitations**: staff invite customers by email (signed accept links); members are invited into an existing account. A `NULL` `customer_account_id` on an invitation means "new customer" — accepting creates an account owned by the acceptor.
- **Self-registration**: when `customer-registration` is enabled *and* the tenant toggles it on, guests can self-register at `/portal/register/{tenant:slug}` (throttled, honeypot-protected).
- **The portal** (`/portal`): an account switcher, member management, and account settings. The `customer.context` middleware auto-selects the only account, validates membership, and derives the tenant context from the account.

---

## Account security

Everything below is managed from the user's **profile page**, and documented for end users in the [in-app help center](#in-app-help-center).

### Passwords & two-factor authentication

Standard Fortify password update and reset. Two-factor authentication uses an authenticator app; enabling it issues **eight single-use recovery codes** (shown after password confirmation, regenerable). The 2FA challenge accepts a recovery code in place of a TOTP code.

**Recovery ladder if the second factor is lost:**
1. Use a saved recovery code at the challenge.
2. Lost the codes too? Sign in with a **passkey**.
3. Lost everything? A system administrator resets 2FA (and/or clears passkeys) from `/admin/users`.

### Passkeys

Passkeys (WebAuthn, via Fortify 1.37 + the [`@laravel/passkeys`](https://www.npmjs.com/package/@laravel/passkeys) browser client, exposed as `window.Passkeys`) are wired into the UI for registered users:

- A `PasskeyManager` profile section: register a named passkey, see its authenticator label and last-used time, delete it (password-confirmed, ownership-checked).
- A **"Sign in with a passkey"** login flow with native browser autofill (`autocomplete="username webauthn"`).
- **Reset**: users can delete/re-register their own passkeys anytime; admins can clear a user's passkeys from `/admin/users` if every device is lost.

### Account recovery — recovery email & phone

Two recovery channels, on top of the standard password reset:

**Secondary (recovery) email**
- Entered on the profile (must differ from the primary email). We send a signed verification link; **only verified recovery emails are usable**.
- If locked out of the primary inbox, the guest `/account-recovery` page (enumeration-safe, throttled) emails a password reset link to the verified recovery address.

**Phone number**
- Entered with a **country selector** (full dial-code catalog in `Laravel\Jetstream\PhoneCountry`) and normalized to **E.164**.
- Verification is pluggable. Register an SMS sender to enable a hashed, expiring 6-digit code flow:

  ```php
  // In a service provider:
  Jetstream::verifyPhonesUsing(App\Sms\TwilioPhoneVerifier::class);
  ```

  ```php
  // Your sender implements the contract:
  use Laravel\Jetstream\Contracts\SendsPhoneVerifications;

  class TwilioPhoneVerifier implements SendsPhoneVerifications
  {
      public function send(\App\Models\User $user, string $code): void
      {
          // ... send $code to $user->phone via your provider
      }
  }
  ```

- With **no** sender registered, users can still store a number (marked unverified) and the UI states that "phone verification is not active right now." SMS delivery is intentionally left to your provider of choice.

---

## Blocking & freezing

Two independent moderation levers:

| | **Block** | **Freeze** |
| --- | --- | --- |
| Scope | The whole application, every organization. | One organization, membership, or customer account. |
| Who | System administrators (`/admin/users`). | Admins (tenant), tenant staff (membership / customer account). |
| Effect | User is signed out everywhere and cannot sign in. | Target loses access & permissions where frozen. |
| Reversible | Yes (unblock). | Yes (unfreeze) — nothing is lost. |
| Storage | `users.blocked_at` / `blocked_reason`. | `tenants.frozen_at`, `tenant_user.frozen_at`, `customer_accounts.frozen_at`. |

- **Blocking** is enforced by the `account.active` middleware on every authenticated request; it logs the user out and redirects with a clear message. `/admin/users` also lets admins reset lost 2FA and clear lost passkeys.
- **Freezing** has three granularities: a whole **tenant** (system admin — all staff and customers lose access; `TenantFrozen`/`TenantUnfrozen` events), a **staff membership** (tenant staff with `staff:manage` — the member keeps their seat but loses all access; owners cannot be frozen), and a **customer account** (tenant staff — its members are locked out of the portal). Context middleware self-heals frozen selections, and `switchTenant`/`switchCustomerAccount` refuse frozen targets.

---

## Domain admin mode

An opt-in feature (`Features::domainAdmin()`) that lets a user prove authority over an email domain and manage the application's **verified** users whose email addresses belong to it — useful when a company wants to police its own `@company.com` accounts without involving your system administrators.

**Claiming a domain.** Any user with a verified email may start a claim from `/user/domains`. Each claim gets its own **globally unique verification token**, published either as a DNS TXT record (`jetstream-domain-verification=<token>`) or as a `<meta name="jetstream-domain-verification">` tag on the domain's home page. "Check verification" looks the token up (DNS first, then meta) via the pluggable `VerifiesDomains` service.

**Single vs. multi domain.** In single mode (the default) a user may only claim the domain part of their own email address. With `Features::domainAdmin(['multi-domain' => true])` they may claim additional domains too.

**The flag moves — history stays.** Any number of users can hold claims for the same domain, but only the claim whose verification succeeded **most recently** holds the domain admin flag; verifying supersedes every other verified claim (`DomainClaimVerified` / `DomainClaimSuperseded` events). Every action a domain admin takes is recorded as domain activity **under the claim it happened under**, so when the flag moves the previous admin's activity survives as a separate, historic tree. A system administrator can erase those historic trees on demand with `php artisan jetstream:purge --domain-history`.

**What a domain admin can do.** List the verified users of their domain and block/unblock them (same `blocked_at` mechanics as `/admin/users`). Only verified accounts participate on both sides: unverified users are invisible to domain admins, and system administrators, the admin themselves, and users of other domains can never be managed.

**Automatic team enrollment.** When the teams feature is enabled, every verified user of a mastered domain is added directly into the domain master's personal team — existing users are swept in the moment a claim is verified, and future users are enrolled as soon as they verify their email. Enrollments are recorded as domain activity under the claim; system administrators and the master themselves are never auto-enrolled, and existing memberships are left untouched.

**Creating users (system admin or CLI).** System administrators can create accounts from `/admin/users` ("New User"), and the CLI ships:

```bash
php artisan jetstream:create-user jane@acme.com \
    --name="Jane Doe" \
    --password=secret        # optional: omit to email a password setup link
    --master                 # domain master of her own email domain
    --master-domain=acme.dev # extra domains (multi-domain mode only)
    --skip-reset-mail        # don't send the setup link when no password is given
```

Created accounts are **pre-verified**, get a personal team, and are enrolled into their domain master's team like any other verified user. With `--master` (or the admin-screen checkbox) the account is granted the domain admin flag directly — method `admin`, no DNS/meta check — superseding earlier claims just like a normal verification. If a password is set it is used; otherwise a password setup (reset) link is emailed unless `--skip-reset-mail` is passed.

---

## Compliance & operations

### Universal audit log

Drop `Laravel\Jetstream\Audit\Auditable` onto **any** Eloquent model to record a full change log:

```php
use Laravel\Jetstream\Audit\Auditable;

class Invoice extends Model
{
    use Auditable;

    // Optional: exclude extra attributes from the log.
    public function auditExcludedAttributes(): array
    {
        return ['internal_notes'];
    }
}
```

Every `created` / `updated` / `deleted` / `restored` / `force_deleted` event writes an `audit_logs` row with the acting user, tenant, **IP address**, **user agent**, and old/new values. Hidden attributes (passwords, tokens, 2FA secrets) are never recorded. Authentication activity — logins, logouts, failed attempts (email only, never the password), password resets, registrations — is logged automatically.

Viewers: a per-tenant change log on the organization settings screen, and an application-wide `/admin/audit` for system administrators. Toggle logging and set retention via `jetstream.audit`.

### Soft deletes & the purge command

Users, tenants, teams, and customer accounts are **soft-deleted** by the delete actions (which release `current_*` pointers). Permanent erasure is deferred to `jetstream:purge`:

```bash
php artisan jetstream:purge            # honors jetstream.purge.retention_days (default 30)
php artisan jetstream:purge --days=7   # override retention
php artisan jetstream:purge --force    # run in production without prompt
```

It (1) processes due data deletion requests, (2) permanently erases records trashed past retention — for a user, that means everything they own, plus tokens, passkeys, sessions, audit entries about them, and anonymization of entries they authored — and (3) prunes audit logs past `jetstream.audit.retention_days`.

### Data rights (GDPR / CCPA / KVKK)

A **"Data & Privacy"** profile section lets users:

- **Export** their personal data as JSON (profile, teams, organizations, customer accounts, recent activity).
- **Request account deletion** (password-confirmed, optional reason) with a **cancellable grace period** (`jetstream.privacy.grace_period_days`, default 30). Requests are tracked in `data_requests` with IP/user-agent provenance and dispatch `DataRequestCreated` / `DataRequestCompleted` / `DataRequestCancelled` events. When the grace period elapses, `jetstream:purge` soft-deletes the account, and permanent erasure follows the purge retention window.

### Throttling with bypass

All package routes run behind named limiters: `jetstream` (per user, default 60/min) and `jetstream-guest` (per IP, default 6/min), configured via `jetstream.throttle`. Requests are **never** throttled when:

- the user is a system administrator,
- the IP is in `jetstream.throttle.bypass_ips`, or
- a `Jetstream::bypassThrottlingUsing(fn ($request) => bool)` callback approves it:

  ```php
  Jetstream::bypassThrottlingUsing(fn ($request) => $request->hasHeader('X-Internal-Job'));
  ```

---

## Names

`name` stays the general-purpose display name. Optional `middle_name` and `last_name` columns are added, plus a composer:

```php
$user->fullName(); // "Taylor James Otwell", skipping any blank parts
```

Both extra fields are editable on the profile form.

---

## UUID v7 primary keys

Every entity — users, tenants, teams, roles, customer accounts, team/customer invitations, audit logs, data requests — uses **time-ordered UUID v7** primary keys (Laravel's `HasUuids`) instead of auto-incrementing integers. IDs cannot be enumerated or guessed (`/tenants/2`, `/user/123`-style probing yields nothing) while staying index-friendly. Pivot rows keep an internal auto-increment id (never exposed).

> **If you enable the API feature:** publish Sanctum's migration and switch `personal_access_tokens` to `$table->uuidMorphs('tokenable')` so token ownership matches the UUID user keys.

---

## Registration honeypot

Both the sign-up form and the customer portal self-registration form carry a visually hidden `website` field (bots fill it, humans never see it). Submissions carrying a value are rejected by the `prohibited` validation rule in `CreateNewUser` and the portal registration controller. Combined with the per-IP guest rate limiter, this blocks the bulk of automated sign-ups without CAPTCHAs.

---

## In-app help center

Two end-user help pages are scaffolded and linked from the UI (no CAPTCHA, no external docs needed):

- **Account Help** (`/help/account`, linked from the profile page and the account menu) — plain-language, step-by-step guidance for signing in, two-factor authentication, passkeys, recovery email & phone, email verification, your data & privacy (GDPR export), and the account-deletion steps.
- **Organization Help** (`/help/tenant`, linked from organization settings) — how organizations, staff, roles, sub-teams, and customers work, plus freezing staff/customer accounts. Administrators additionally see sections on freezing whole organizations, blocking users, and the audit log.

Both are published as editable Blade views (`resources/views/help/*.blade.php`) using a reusable `<x-help-topic>` component — tailor the copy to your product.

---

## Configuration reference

`config/jetstream.php` (published to your app):

```php
'features' => [
    // Features::termsAndPrivacyPolicy(),
    // Features::profilePhotos(),
    // Features::api(),
    // Features::teams(['invitations' => true]),
    // Features::tenants(['portal' => true, 'customer-registration' => true]),
    // Features::domainAdmin(['multi-domain' => true]),
    Features::accountDeletion(),
    Features::dataPrivacy(),      // Data & Privacy profile section
    Features::accountRecovery(),  // recovery email + phone
],

'tenants' => [
    'self_service_creation' => true,   // any user may create a tenant, vs. admin-only
],

'audit' => [
    'enabled' => true,
    'retention_days' => null,          // null = keep forever; N = pruned by jetstream:purge
],

'purge' => [
    'retention_days' => 30,            // soft-deleted records erased after N days
],

'privacy' => [
    'grace_period_days' => 30,         // cancellable window before a deletion request runs
],

'throttle' => [
    'attempts' => 60,                  // per-user, per-minute
    'guest_attempts' => 6,             // per-IP, per-minute
    'bypass_ips' => [],
],

'admin_email' => env('JETSTREAM_ADMIN_EMAIL'),
```

---

## Extension points

Every model and action is swappable, and all swap points are typed (`class-string<…>`):

```php
Jetstream::useTenantModel(App\Models\Tenant::class);
Jetstream::useCustomerAccountModel(App\Models\CustomerAccount::class);
Jetstream::useRoleModel(App\Models\Role::class);
Jetstream::useAuditLogModel(App\Models\AuditLog::class);
Jetstream::useDataRequestModel(App\Models\DataRequest::class);

Jetstream::createTenantsUsing(App\Actions\Jetstream\CreateTenant::class);
Jetstream::inviteCustomersUsing(App\Actions\Jetstream\InviteCustomer::class);
// ... full create/update/add/remove/delete registrars for tenants & customers

Jetstream::useDomainClaimModel(App\Models\DomainClaim::class);
Jetstream::useDomainActivityModel(App\Models\DomainActivity::class);

Jetstream::verifyPhonesUsing(App\Sms\YourPhoneVerifier::class);
Jetstream::verifyDomainsUsing(App\Domains\YourDomainVerifier::class);
Jetstream::bypassThrottlingUsing(fn ($request) => /* bool */);
```

Business actions are published into `app/Actions/Jetstream/` (edit them freely); package plumbing lives in `src/`.

---

## Database schema

Key tables (all UUID v7 keys, all foreign keys indexed):

| Table | Notable columns |
| --- | --- |
| `users` | `name`, `middle_name`, `last_name`, `email`, `phone` + `phone_country` + `phone_verified_at`, `recovery_email` (+ verified), `current_team_id`/`current_tenant_id`/`current_customer_account_id`, `is_system_admin`, `blocked_at`/`blocked_reason`, soft deletes |
| `tenants` | `user_id` (owner), `slug` (unique), `allow_customer_registration`, `frozen_at`, soft deletes |
| `tenant_user` | `role`, `frozen_at`, unique `(tenant_id, user_id)` |
| `roles` | `tenant_id` (nullable = default), `key`, `permissions` (json), unique `(tenant_id, key)` |
| `customer_accounts` | `tenant_id`, `user_id` (owner), `frozen_at`, soft deletes |
| `customer_invitations` | `tenant_id`, `customer_account_id` (nullable), `email` |
| `audit_logs` | `tenant_id`, `user_id`, `event`, `auditable` (uuid morph), `old_values`/`new_values`, `ip_address`, `user_agent` |
| `data_requests` | `user_id`, `type`, `status`, `process_after`, provenance columns |
| `domain_claims` | `user_id`, `domain`, `token` (unique), `method`, `verified_at`, `superseded_at`, unique `(domain, user_id)` |
| `domain_activities` | `domain_claim_id`, `user_id` (actor), `subject_id`, `action`, `details` (json) |

Migrations are published under the `jetstream-tenant-migrations`, `jetstream-compliance-migrations`, and `jetstream-domain-migrations` tags.

---

## Testing & quality gates

- `declare(strict_types=1)` across the entire codebase.
- **[Larastan](https://github.com/larastan/larastan) at level max** with zero errors and no baseline — `vendor/bin/phpstan analyse`.
- Full package test suite on Orchestra Testbench — `vendor/bin/phpunit`.

---

## Upgrade notes

This fork intentionally diverges from upstream Jetstream (Inertia removed, single install path, UUID keys, Laravel 13/PHP 8.4 floor) and does not track upstream releases. Treat it as a standalone starter.

---

## License

Open-sourced software licensed under the [MIT license](LICENSE.md), derived from Laravel Jetstream by Taylor Otwell.
