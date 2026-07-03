<p align="center"><img src="/art/logo.svg" alt="Logo Laravel Jetstream"></p>

# Jetstream SaaS

An opinionated, Livewire-only fork of [Laravel Jetstream](https://jetstream.laravel.com) that ships a complete multi-tenant SaaS architecture out of the box. It targets **Laravel 13+ / PHP 8.3+** only and does not track upstream Jetstream releases.

## What you get

One install command scaffolds three actors on a single shared `users` table:

- **System owner** — `is_system_admin` flag, `system.admin` middleware, and an `/admin/tenants` screen that operates across tenants through an explicit tenant-scope bypass.
- **Tenants** — organizations with an owner, staff memberships, database-backed roles (application defaults that each tenant may override or extend), and Jetstream teams nested beneath them via `teams.tenant_id`.
- **Customers of tenants** — customer accounts (single user or small shared teams) with invitation emails, per-tenant self-registration, and a `/portal` context with an account switcher. The same user can be staff of one tenant and a customer of another.

Single-database tenancy is enforced by a request-scoped `TenantContext`, a `BelongsToTenant` trait (global scope + automatic `tenant_id` on create) that you can drop onto your own domain models, and context middleware that self-heals stale state. Escape hatches are explicit: `TenantContext::bypass()`, `TenantContext::runFor()` for queued jobs, and a `withoutTenancy()` query scope.

Passkey authentication (Laravel Fortify) is wired into the UI: profile passkey management and a "Sign in with a passkey" login flow via the [`@laravel/passkeys`](https://www.npmjs.com/package/@laravel/passkeys) browser client.

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
- [Larastan](https://github.com/larastan/larastan) level 5 with typed model swap points (`class-string<...>` contracts) — run `vendor/bin/phpstan analyse`.
- Full package test suite on Orchestra Testbench — run `vendor/bin/phpunit`.

## License

Open-sourced software licensed under the [MIT license](LICENSE.md), derived from Laravel Jetstream by Taylor Otwell.
