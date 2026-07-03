<p align="center"><img src="/art/logo.svg" alt="Logo Laravel Jetstream"></p>

# Jetstream SaaS

An opinionated, Livewire-only fork of [Laravel Jetstream](https://jetstream.laravel.com) that ships a complete multi-tenant SaaS architecture out of the box. It targets **Laravel 13+ / PHP 8.4+** only and does not track upstream Jetstream releases.

## What you get

One install command scaffolds three actors on a single shared `users` table:

- **System owner** — `is_system_admin` flag, `system.admin` middleware, and an `/admin/tenants` screen that operates across tenants through an explicit tenant-scope bypass.
- **Tenants** — organizations with an owner, staff memberships, database-backed roles (application defaults that each tenant may override or extend), and Jetstream teams nested beneath them via `teams.tenant_id`.
- **Customers of tenants** — customer accounts (single user or small shared teams) with invitation emails, per-tenant self-registration, and a `/portal` context with an account switcher. The same user can be staff of one tenant and a customer of another.

Single-database tenancy is enforced by a request-scoped `TenantContext`, a `BelongsToTenant` trait (global scope + automatic `tenant_id` on create) that you can drop onto your own domain models, and context middleware that self-heals stale state. Escape hatches are explicit: `TenantContext::bypass()`, `TenantContext::runFor()` for queued jobs, and a `withoutTenancy()` query scope.

Passkey authentication (Laravel Fortify) is wired into the UI: profile passkey management and a "Sign in with a passkey" login flow via the [`@laravel/passkeys`](https://www.npmjs.com/package/@laravel/passkeys) browser client.

## Compliance & operations

- **Universal audit log** — drop the `Laravel\Jetstream\Audit\Auditable` trait onto *any* Eloquent model to record a full change log (created / updated / deleted / restored / force deleted) with the acting user, tenant, IP address, and user agent. Hidden attributes are never recorded. Authentication activity (logins, logouts, failed attempts, password resets, registrations) is logged automatically. An `audit-log-viewer` Livewire component powers a per-tenant change log on the tenant settings screen and an application-wide `/admin/audit` screen for system administrators.
- **Soft deletes + purge command** — deleting a user, tenant, team, or customer account only soft deletes it. `php artisan jetstream:purge` permanently erases records past the configured retention (`jetstream.purge.retention_days`, default 30), processes due data deletion requests, and prunes expired audit logs. Schedule it daily: `Schedule::command('jetstream:purge')->daily()`. Purging a user erases everything they own, scrubs their audit trail, and anonymizes log entries they authored.
- **Data rights (GDPR / CCPA / KVKK)** — a "Data & Privacy" profile section lets users download their personal data as JSON and file an account deletion request with a cancellable grace period (`jetstream.privacy.grace_period_days`, default 30). Requests are tracked in a `data_requests` table with IP/user-agent provenance and dispatch `DataRequestCreated` / `DataRequestCompleted` / `DataRequestCancelled` events.
- **Throttling with bypass** — all Jetstream routes run behind named rate limiters (`jetstream` per user, `jetstream-guest` per IP) configured via `jetstream.throttle`. System administrators, IPs in `throttle.bypass_ips`, and requests approved by a `Jetstream::bypassThrottlingUsing(fn ($request) => ...)` callback are never throttled.
- **Account recovery** — users may store a phone number and a secondary recovery email. The recovery email is verified through a signed link, after which the guest `/account-recovery` flow can send password reset links to it — useful when the primary inbox is lost. (SMS delivery for the phone channel is intentionally left to your provider of choice.)

## Installation

```bash
laravel new my-saas
cd my-saas

composer config repositories.jetstream vcs https://github.com/devbaa/jetstream
composer require laravel/jetstream:"dev-<branch> as 6.0"

php artisan jetstream:install livewire
php artisan migrate --seed
```

Set `JETSTREAM_ADMIN_EMAIL` in your `.env` to flag your own user as the system administrator, then re-run `php artisan db:seed --class=SystemAdminSeeder` after registering.

## Quality gates

- `declare(strict_types=1)` across the entire codebase.
- [Larastan](https://github.com/larastan/larastan) at **level max** with typed model swap points (`class-string<...>` contracts) — run `vendor/bin/phpstan analyse`.
- Full package test suite on Orchestra Testbench — run `vendor/bin/phpunit`.

## License

Open-sourced software licensed under the [MIT license](LICENSE.md), derived from Laravel Jetstream by Taylor Otwell.
