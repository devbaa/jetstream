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
- [Upstream maintenance](#upstream-maintenance)
- [Upgrade notes](#upgrade-notes)
- [License](#license)

---

## Requirements

| Dependency | Version |
| --- | --- |
| PHP | ^8.4 |
| Laravel | ^13.0 |
| Livewire | ^3.6 or ^4.0 |
| Fortify | ^1.37 (passkeys) |
| Stack | Livewire only (Inertia is not supported) |

---

## Installation

```bash
laravel new my-saas
cd my-saas

# Point Composer at this fork and require it.
# No stable release has been tagged yet, so track the development branch.
composer config repositories.jetstream vcs https://github.com/devbaa/jetstream
composer require devbaa/jetstream:"dev-main"

# Scaffold everything (Livewire is the only supported stack).
php artisan jetstream:install livewire

# Run migrations and seed default roles + the system administrator.
php artisan migrate --seed
```

> **If your application had already migrated before installing** — `laravel new`
> and `composer create-project` both offer to do that — the installer stops before
> changing any application scaffolding or the database, and tells you. (It publishes
> the migrations first, so that the rebuild it points you at has something correct
> to build from.) Jetstream publishes its own version of
> `0001_01_01_000000_create_users_table` over Laravel's, and that migration creates
> `users`, `password_reset_tokens` and `sessions`. Laravel tracks migrations by
> name, so if the name is already recorded the replacement never runs: you keep an
> auto-incrementing `users.id` and an integer `sessions.user_id` while everything
> referencing them expects a UUID — and the installer sets `SESSION_DRIVER=database`,
> so that second one breaks every request. The installer checks Laravel's migration
> ledger against the tables actually present and refuses when they disagree in
> either direction. It never rebuilds the database for you — an empty users table
> is not an empty database. Once you have backed up or discarded whatever this
> database holds, rebuild it yourself:
>
> ```bash
> php artisan migrate:fresh --seed   # drops every table
> ```
>
> The installer stops for the same reason if it cannot read the database at all —
> a connection failure, a permission denial, unreadable table metadata. It runs
> `artisan migrate` itself, so a database it cannot inspect is one it would be
> migrating blind; that is refused rather than assumed to be clean.

Flag your own user as the system administrator by setting `JETSTREAM_ADMIN_EMAIL` in `.env`, then (after registering that user) run:

```bash
php artisan db:seed --class=SystemAdminSeeder
```

Schedule the purge command so soft-deleted records and due deletion requests are processed automatically (in `routes/console.php` or your scheduler):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('jetstream:purge --force')->daily();
```

> The `--force` flag is required for scheduled runs: without it the command
> prompts for confirmation in production, and a non-interactive scheduler
> would abort — silently stopping retention and GDPR deletion.

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
- **`BelongsToTenant`** — a trait for your own domain models. It adds a **fail-closed** global scope (only rows for the current tenant, and an exception when there is no tenant at all) and auto-fills `tenant_id` on create. This is the main reusability payoff — drop it on any model:

  ```php
  use Laravel\Jetstream\Tenancy\BelongsToTenant;

  class Invoice extends Model
  {
      use BelongsToTenant;
  }
  ```

- **Fail-closed scoping** — a tenant-scoped query built while **no** tenant is in context throws `Laravel\Jetstream\Tenancy\MissingTenantContextException` instead of running unscoped:

  ```php
  Invoice::query()->get();   // no tenant context ⇒ MissingTenantContextException
  ```

  Missing context is a configuration bug (forgotten middleware, a job dispatched without its tenant), and the safe response to a configuration bug is a loud failure rather than a query that quietly returns every tenant's rows. The exception names the model and lists the escape hatches.

- **Explicit escape hatches** — nothing is magic, and every cross-tenant operation has to say so:

  ```php
  // Run a closure with the tenant scope disabled (admin screens, purge, seeding).
  app(TenantContext::class)->bypass(fn () => Invoice::all());

  // Run a queued job in a known tenant's context.
  app(TenantContext::class)->runFor($tenant, fn () => /* ... */);

  // One-off unscoped query — the narrowest hatch, affects this query only.
  Invoice::withoutTenancy()->get();
  ```

  `bypass()` and `runFor()` both restore the previous state in a `finally` block, so they nest safely and an exception thrown inside them cannot leak a stale context into the rest of the request.

> **Queued jobs, listeners, and console commands start with an empty context**, so tenant-scoped queries there throw until you establish one. Wrap tenant-specific background work in `TenantContext::runFor($tenant, …)`, and mark genuinely system-wide work with `bypass()` or `withoutTenancy()`. Prefer the narrowest hatch that fits: `withoutTenancy()` on a single query over a `bypass()` around a block of code.

**Tenant-optional models.** A model may declare `public $tenantOptional = true` (as the built-in `roles` model does) to mean "rows with a null `tenant_id` are shared defaults". Within a tenant context such a model resolves `tenant_id = <current> OR tenant_id IS NULL`. It is **not** a weaker form of scoping: with no context it fails closed exactly like every other tenant-scoped model, so the global defaults are reached through `withoutTenancy()` (see `RoleRegistry`) rather than by omitting the context.

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
- Addresses are stored **canonically** — trimmed and lower-cased via `Jetstream::normalizeEmail()` — and the recovery lookup normalizes its input the same way, so recovery works whatever casing was typed. This matters on PostgreSQL, whose `=` is case-sensitive: without it, an address saved as `User@Example.com` could never be matched by someone typing `user@example.com`. Write `recovery_email` through `Jetstream::normalizeEmail()` if your own code sets it directly.
- Both recovery messages are **queued**, never delivered inline, so a request for a registered address is not measurably slower than one for an unknown address.
- Nothing constrains a recovery address to one account. If an address is verified on **more than one**, recovery deliberately sends nothing rather than letting row order decide which account a reset link unlocks — the requester sees the same generic response, and the ambiguity is logged (user ids only) for operators to resolve.

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
| Effect | Existing credentials are revoked and the user is turned away. | Target loses access & permissions where frozen. |
| Reversible | Yes (unblock). | Yes (unfreeze) — nothing is lost. |
| Storage | `users.blocked_at` / `blocked_reason`. | `tenants.frozen_at`, `tenant_user.frozen_at`, `customer_accounts.frozen_at`. |

- **Blocking** happens in two parts. At block time the `BlockUser` action revokes what the user already holds, in one transaction with the block itself: every Sanctum personal access token is deleted, and so is every database session row belonging to them. Deletion rather than a flag is what makes unblocking safe — there is nothing left that could become valid again, so lifting a block never resurrects an old credential. Then the `account.active` middleware turns a blocked user away from Jetstream's own route group (and from Livewire component updates, where it is registered as persistent middleware), logging them out and redirecting with a clear message. The first part is what covers an application's own `auth:sanctum` API routes, which never pass through the middleware at all. `/admin/users` also lets admins reset lost 2FA and clear lost passkeys.
- Blocking does **not** currently reject the login attempt itself: a blocked user can still authenticate, and is then turned away by the middleware on the first Jetstream route they reach. Routes of your own that carry only `auth` should add the `account.active` middleware.
- **Freezing** has three granularities: a whole **tenant** (system admin — all staff and customers lose access; `TenantFrozen`/`TenantUnfrozen` events), a **staff membership** (tenant staff with `staff:manage` — the member keeps their seat but loses all access; owners cannot be frozen), and a **customer account** (tenant staff — its members are locked out of the portal). Context middleware self-heals frozen selections, and `switchTenant`/`switchCustomerAccount` refuse frozen targets.

---

## Domain admin mode

An opt-in feature (`Features::domainAdmin()`) that lets a user prove authority over an email domain and manage the application's **verified** users whose email addresses belong to it — useful when a company wants to police its own `@company.com` accounts without involving your system administrators.

**Claiming a domain.** Any user with a verified email may start a claim from `/user/domains`. Each claim gets its own **globally unique verification token**, published either as a DNS TXT record (`jetstream-domain-verification=<token>`) or as a `<meta name="jetstream-domain-verification">` tag on the domain's home page. "Check verification" looks the token up (DNS first, then meta) via the pluggable `VerifiesDomains` service.

**Single vs. multi domain.** In single mode (the default) a user may only claim the domain part of their own email address. With `Features::domainAdmin(['multi-domain' => true])` they may claim additional domains too.

**The flag moves — history stays.** Any number of users can hold claims for the same domain, but only the claim whose verification succeeded **most recently** holds the domain admin flag; verifying supersedes every other verified claim (`DomainClaimVerified` / `DomainClaimSuperseded` events). "At most one active claim per domain" is enforced by the database, not just by the activation code: `domain_claims.active_domain` is a generated column holding the domain exactly while the claim is active and `NULL` otherwise, under a unique index. Activation locks every claim for the domain — including its own row, so the set is never empty — which is what makes two people verifying the same domain at the same moment serialize instead of both taking the flag. Every action a domain admin takes is recorded as domain activity **under the claim it happened under**, so when the flag moves the previous admin's activity survives as a separate, historic tree. A system administrator can erase those historic trees on demand with `php artisan jetstream:purge --domain-history`.

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

> **API tokens.** Sanctum's own migration types `personal_access_tokens.tokenable_id` as an auto-incrementing integer, which cannot hold a UUID user key. The installer corrects the migration it publishes, so a new application creates the right column; nothing is left for you to edit. It is a **string**, not a UUID column — `tokenable` is polymorphic, and an application is free to issue tokens to a model of its own with an integer key.

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
| `customer_invitations` | `tenant_id`, `customer_account_id` (nullable), `email`, `account_key` (generated), unique `(tenant_id, account_key, email)` |
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

## Upstream maintenance

This package has its own release cycle and is **not** release-compatible with upstream [Laravel Jetstream](https://github.com/laravel/jetstream):

- It uses **its own semantic versions**. A version number here has no relationship to an upstream Jetstream version.
- Selected upstream maintenance and security changes may be reviewed by hand and incorporated. Upstream releases are **never** merged automatically and are not drop-in upgrades — the architecture has diverged (Inertia removed, single install path, UUID v7 keys, multi-tenancy, Laravel 13 / PHP 8.4 floor), so every upstream change has to be re-read against this code.
- The PHP classes intentionally stay in the `Laravel\Jetstream\` namespace, so **`laravel/jetstream` must not be installed alongside this package**. `composer.json` declares `"conflict": {"laravel/jetstream": "*"}` and Composer will refuse the combination.
- Applications should depend on **`devbaa/jetstream`** directly. This package does not `replace` upstream Jetstream, so a third-party package that requires `laravel/jetstream` is not satisfied by it — that is deliberate, since the two are no longer interchangeable.

### Releases

No stable version has been tagged yet, so the documented install constraint is `dev-main` (aliased to `6.x-dev`). Once a stable tag exists, depend on the matching `^x.y` constraint instead.

---

## Upgrade notes

**Tenant scoping now fails closed.** A query against a `BelongsToTenant` model built with **no** active `TenantContext` previously ran unscoped, returning every tenant's rows; it now throws `Laravel\Jetstream\Tenancy\MissingTenantContextException`. This is a deliberate, security-motivated breaking change.

Code that runs outside a tenant-context request — queued jobs, listeners, console commands, schedulers, seeders, system administration screens — must state its intent:

```php
// Tenant-specific background work.
app(TenantContext::class)->runFor($tenant, fn () => /* ... */);

// Deliberately system-wide work.
app(TenantContext::class)->bypass(fn () => /* ... */);

// A single deliberately unscoped query.
Invoice::withoutTenancy()->get();
```

Nothing else about the API changed: `bypass()`, `runFor()`, `withoutTenancy()` and `$tenantOptional` behave exactly as before when a tenant *is* in context.

**Sanctum's token column is no longer an integer.** `personal_access_tokens.tokenable_id` came from Sanctum's own migration as an auto-incrementing integer, which no UUID user key fits into: on PostgreSQL the first token an application issued was rejected outright. sqlite accepted it — its typing is dynamic, so an integer-affinity column simply keeps a value that is not a well-formed integer as text, and the UUID round trips intact. Nothing was corrupted; sqlite was permitting a schema contract that stricter engines reject, which is why the mismatch went unnoticed. Fresh installs now get the right column because the installer corrects the migration before it is run. An application already installed needs the widening migration published and run:

```bash
php artisan vendor:publish --tag=jetstream-migrations
php artisan migrate
```

Again without `--force`. Existing token rows are preserved. If you previously followed the old advice in this README and changed the column to `uuidMorphs` by hand, that also works for this package's users but will reject tokens issued to any model of yours with an integer key; a string column takes both.

**The audit log now stores any model's key.** `audit_logs.auditable_id` was declared with `nullableUuidMorphs`, so it held UUIDs only — while `Auditable` is documented for *any* Eloquent model, and the example in this README is an `Invoice extends Model` with a stock auto-incrementing key. On PostgreSQL, auditing such a model failed outright with `invalid input syntax for type uuid`; sqlite stored the integer without complaint, which is why it went unnoticed. The column is now a string, so it takes UUIDs, ULIDs and integers alike — `auditable_type` is what tells them apart, as it always was.

No application code changes are required, but **the new migration does not arrive on its own**. This package does not `loadMigrationsFrom()`; migrations reach your application only by being published, and that happens during `jetstream:install`. After upgrading:

```bash
php artisan vendor:publish --tag=jetstream-compliance-migrations
php artisan migrate
```

Without `--force`: the migrations you already have stay exactly as they are, and only the new file is copied in. Existing UUID history is converted in place and keeps its values, and the morph index survives.

**A person can hold only one pending customer invitation per destination.** `customer_invitations` was unique over `(tenant_id, customer_account_id, email)`. `customer_account_id` is `NULL` for the commonest invitation of all — "join this tenant as a new customer" — and NULL is distinct from NULL in a unique index on PostgreSQL, MySQL and sqlite, so that case was never constrained. The only thing standing in for the rule was an `exists()` check in `InviteCustomer`, which two overlapping requests both pass before either writes. Accepting the resulting pair gave one person two customer accounts in one tenant.

A generated `account_key` column now holds the customer account id, or the empty string when there is none, and the unique index spans `(tenant_id, account_key, email)`. It is generated rather than written, so it cannot drift from the account it stands for, and it replaces the old index rather than joining it. Inviting the same person to two different accounts, or in two different tenants, or again after an invitation is accepted or cancelled, is unaffected.

Invitation addresses are **not** normalized by this change, so what counts as the same address is the column and index collation rather than anything the package decides. Laravel's default MySQL and MariaDB collation is case-insensitive, so `Jane@example.com` and `jane@example.com` collide there; PostgreSQL and sqlite compare them as distinct and admit both. If you need one answer on every engine, canonicalize the address before inviting — `Jetstream::normalizeEmail()` is what the recovery-email flow uses for the same reason.

The migration is published under the tenant tag:

```bash
php artisan vendor:publish --tag=jetstream-tenant-migrations
php artisan migrate
```

Without `--force`. **If your database already holds duplicate pending invitations, the migration deletes the extra rows, keeping the oldest of each set** — the index cannot be created over them, and deleting the row is what the application already does to an invitation when it is accepted or cancelled. The rows are duplicates of each other: each set names one tenant, one person and one destination, and one row of it survives to stand for all of them. What is deleted is real, though — the extra rows and the signed links carrying their ids. An invitee holding one of those links sees the invitation as no longer available and needs to be invited again. Only rows with no customer account can be affected, since the index being replaced already separated every other pair. Run `select tenant_id, email, count(*) from customer_invitations where customer_account_id is null group by 1, 2 having count(*) > 1;` first if you want to see what will be collapsed.

`app/Actions/Jetstream/InviteCustomer.php` **lives in your application and is not replaced by upgrading this package**. The new stub reports a lost race as the same `inviteCustomer` validation error the pre-insert check produces, instead of letting the constraint violation surface as a 500; compare against `vendor/devbaa/jetstream/stubs/app/Actions/Jetstream/InviteCustomer.php`. Leaving your copy as it is keeps the invariant — the database is what enforces it — and only affects what a caller sees on the rare losing request.

**Accepting a customer invitation is now one transaction, and the invitation is the lock.** `CustomerInvitationController::accept()` read the invitation, resolved the invitee, created or joined a customer account, switched the invitee to it, deleted the invitation and announced the result — six writes with no transaction around them and nothing taken as a lock. Two requests carrying the same signed link both read a row that is still there and both act on it. Because the account is created before the invitation is deleted, the request that loses has already made a customer account nobody asked for; and deleting a row another transaction has already deleted is not an error, so it never learns it lost. On the branch that creates a fresh account, that meant one person could end up with two.

The invitation row is now taken with `lockForUpdate()` and held for the whole acceptance. Exactly one request consumes it and performs the account and membership changes; a second waits, then finds it consumed and gets the same 404 a spent invitation has always given, having written nothing.

`CustomerInvitationAccepted` now implements `ShouldDispatchAfterCommit` and is raised from *inside* the acceptance transaction, so it is announced only once that connection commits at its outermost level, and dropped if it rolls back. Neither half works alone. Laravel transactions nest, so where an application already has one open — transaction middleware, a larger workflow, a job that wraps its work — the acceptance's own transaction is a savepoint whose commit settles nothing; and the transaction manager that holds a deferred event keeps one pending list for every connection at once, attaching the callback to whichever transaction was begun most recently rather than to the one the event belongs to. Raised after its own transaction returns, the event would be carried by whatever else happened to be open, and an unrelated connection's commit would announce an acceptance that is not durable.

**If you listen for this event, it now fires later than it did** — at the end of the enclosing transaction rather than the middle of it. That is the point: a listener firing earlier reads an account no other connection can see, and may be told about an acceptance a later rollback takes away.

**No action is needed on upgrade**: the controller is package code, reached through the published routes file, so it arrives with the package. The transaction runs on the invitation's own connection, which covers every write here for a stock installation; an application that puts invitations, customer accounts and users on different connections keeps the lock and the deletion atomic but not the rest.

**A role update now requires a membership to update.** `UpdateTenantStaffRole` and `UpdateTeamMemberRole` handed the given id straight to `updateExistingPivot()`, which writes nothing when there is no such membership and reports no error — so the caller was told the change succeeded and `TenantStaffUpdated` / `TeamMemberUpdated` were announced for a role that does not exist. A user who is staff of a *different* tenant looked exactly the same from there as one who is staff nowhere. The screens reach this by user id and never check membership themselves, so any administrator with permission over the tenant or team could name anyone.

Both actions now take the membership row with `lockForUpdate()` and update it in the same transaction, so a membership revoked by a second administrator mid-update cannot be announced either. A missing membership raises `ModelNotFoundException` — a 404, the same answer the screen already gives for a staff member it cannot find.

**Setting the role a member already holds still succeeds and is still announced**: the membership exists and ends in the state that was asked for. The result of the write could not have decided this instead. `tenant_user` and `team_user` are mapped to pivot models, so Laravel calls `using()` for these relations and the update takes its custom-class path — load the pivot, fill it, report whether anything became dirty — which reports the same thing for a membership that does not exist as for one that already holds the role being set. Membership existence has to be established separately, which is what the locked read does.

`TenantStaffUpdated` and `TeamMemberUpdated` now implement `ShouldDispatchAfterCommit` and are raised from inside that transaction, so **if you listen for either, it now fires at the end of the enclosing transaction rather than the middle of it**. The other events sharing their base classes are unchanged — `AddingTeamMember` and `RemovingTeamMember` are announced deliberately before their work happens.

No action is needed on upgrade: both actions and both events are package code.

**Role validation now targets the resource being changed.** `Laravel\Jetstream\Rules\Role` used to resolve the valid role keys from the *current* tenant. Where the ambient tenant and the tenant being modified differ — a user who belongs to two tenants, acting on an explicit tenant or team — that rejected roles the target really defines and accepted roles it has never heard of, writing an unresolvable key into the membership pivot. The rule now takes its target: `Role::for($tenant)`, or `Role::for($team->tenant_id)` for a team, whose tenant may be `null` for a personal team.

`new Role` still works and still reads the ambient tenant, so upgrading breaks nothing — but **the action stubs live in your application and are not replaced by upgrading this package**. If your app was installed before this change, these three copies still validate against the ambient tenant and should be updated by hand:

```php
// app/Actions/Jetstream/AddTenantStaff.php
'role' => ['required', 'string', Role::for($tenant)],

// app/Actions/Jetstream/AddTeamMember.php
'role' => ['required', 'string', Role::for($team->tenant_id)],

// app/Actions/Jetstream/InviteTeamMember.php
'role' => ['required', 'string', Role::for($team->tenant_id)],
```

`rules()` in the first two takes the target as an argument in the new stubs; compare against `vendor/devbaa/jetstream/stubs/app/Actions/Jetstream/`. The no-argument form is a compatibility path only, and only has any effect at all when tenant features are enabled — with tenancy off the rule answers from the statically registered roles and never asks which tenant is current.

This fork intentionally diverges from upstream Jetstream (Inertia removed, single install path, UUID keys, Laravel 13/PHP 8.4 floor). Treat it as a standalone starter.

---

## License

Open-sourced software licensed under the [MIT license](LICENSE.md), derived from Laravel Jetstream by Taylor Otwell.
