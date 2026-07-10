<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Mail\PasswordSetup;

class CreateUser
{
    /**
     * Create a user on behalf of a system administrator or the CLI.
     *
     * The account is created with a verified email so it immediately takes
     * part in domain administration: it is enrolled into its domain
     * master's team, and any requested master domains are granted directly
     * (method "admin"), superseding earlier claims. When no password is
     * given, a password setup (reset) link is emailed unless $sendResetLink
     * is false.
     *
     * @param  array{name: string, email: string, password?: string|null, master_domains?: array<int, string>}  $input
     * @return \App\Models\User
     */
    public function create(array $input, bool $sendResetLink = true)
    {
        $input['email'] = strtolower(trim($input['email']));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', PasswordRule::default()],
            'master_domains' => ['nullable', 'array'],
            'master_domains.*' => ['string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
        ], [
            'master_domains.*.regex' => __('This is not a valid domain name.'),
        ])->validateWithBag('createUser');

        $email = $input['email'];

        $password = $input['password'] ?? null;

        $masterDomains = array_values(array_unique(array_map(
            static fn (string $domain): string => strtolower($domain),
            $input['master_domains'] ?? []
        )));

        $this->validateMasterDomains($masterDomains, $email);

        $claims = [];

        $user = DB::transaction(function () use ($input, $email, $password, $masterDomains, &$claims) {
            $user = Jetstream::newUserModel();

            $user->forceFill([
                'name' => $input['name'],
                'email' => $email,
                'password' => Hash::make($password ?? Str::random(40)),
                'email_verified_at' => now(),
            ])->save();

            if (Features::hasTeamFeatures()) {
                $user->ownedTeams()->save(Jetstream::newTeamModel()->forceFill([
                    'user_id' => $user->id,
                    'name' => explode(' ', $input['name'], 2)[0]."'s Team",
                    'personal_team' => true,
                ]));
            }

            foreach ($masterDomains as $domain) {
                $claim = $user->domainClaims()->firstOrNew(['domain' => $domain]);

                if (! $claim->exists) {
                    $claim->token = $claim::generateToken();

                    $claim->save();
                }

                $claims[] = $claim;
            }

            return $user;
        });

        // Activate the master domains after the account is committed so the
        // supersede/verify events (and the resulting team enrollments) are
        // only announced for state that has actually been persisted...
        foreach ($claims as $claim) {
            app(VerifyDomainClaim::class)->activate($claim, 'admin');
        }

        // Enroll the user into their own domain master's team, if any...
        app(AddUserToDomainTeams::class)->add($user);

        if ($password === null && $sendResetLink) {
            $this->sendPasswordSetupLink($user);
        }

        return $user;
    }

    /**
     * Ensure the requested master domains are allowed.
     *
     * Master domains require the domain admin feature. In single domain
     * mode a user may only master the domain of their own email address;
     * multi domain mode lifts that restriction.
     *
     * @param  array<int, string>  $masterDomains
     */
    protected function validateMasterDomains(array $masterDomains, string $email): void
    {
        if ($masterDomains === []) {
            return;
        }

        if (! Features::hasDomainAdminFeatures()) {
            throw ValidationException::withMessages([
                'master_domains' => [__('The domain admin feature is not enabled.')],
            ])->errorBag('createUser');
        }

        if (Features::allowsMultipleDomains()) {
            return;
        }

        $emailDomain = strtolower(substr((string) strrchr($email, '@'), 1));

        foreach ($masterDomains as $domain) {
            if ($domain !== $emailDomain) {
                throw ValidationException::withMessages([
                    'master_domains' => [__('In single domain mode a user may only master the domain of their own email address.')],
                ])->errorBag('createUser');
            }
        }
    }

    /**
     * Email the user a link to choose their password.
     *
     * @param  \App\Models\User  $user
     */
    protected function sendPasswordSetupLink($user): void
    {
        $broker = Password::broker();

        if ($broker instanceof \Illuminate\Auth\Passwords\PasswordBroker) {
            $token = $broker->createToken($user);

            Mail::to($user->email)->send(new PasswordSetup($user, $token));
        }
    }
}
