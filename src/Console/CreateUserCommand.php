<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Actions\CreateUser;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jetstream:create-user')]
class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jetstream:create-user
                            {email : The email address for the new user}
                            {--name= : The user\'s display name (defaults to the email local part)}
                            {--password= : Set an initial password; omit to email a password setup link}
                            {--master : Make the user the domain master of their own email domain}
                            {--master-domain=* : Domain(s) the user should master (additional domains require multi-domain mode)}
                            {--skip-reset-mail : Do not send the password setup link when no password is given}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a verified user, optionally granting domain mastership; without a password a setup link is emailed unless skipped';

    /**
     * Execute the console command.
     */
    public function handle(CreateUser $creator): int
    {
        $email = $this->argument('email');

        if ($email === '') {
            $this->components->error('An email address is required.');

            return self::FAILURE;
        }

        $name = $this->option('name');

        if (! is_string($name) || $name === '') {
            $localPart = strstr($email, '@', true);

            $name = ucfirst($localPart !== false && $localPart !== '' ? $localPart : $email);
        }

        $password = $this->option('password');

        $masterDomains = array_values(array_filter(
            $this->option('master-domain'),
            static fn ($domain): bool => is_string($domain) && $domain !== ''
        ));

        if ($this->option('master')) {
            $emailDomain = substr((string) strrchr($email, '@'), 1);

            if ($emailDomain !== '') {
                $masterDomains[] = $emailDomain;
            }
        }

        try {
            $user = $creator->create([
                'name' => $name,
                'email' => $email,
                'password' => is_string($password) && $password !== '' ? $password : null,
                'master_domains' => $masterDomains,
            ], ! $this->option('skip-reset-mail'));
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf('User %s created.', $user->email));

        foreach ($user->activeDomainClaims()->get() as $claim) {
            $this->components->twoColumnDetail('Domain master', $claim->domain);
        }

        if (is_string($password) && $password !== '') {
            $this->components->twoColumnDetail('Password', 'set from --password');
        } elseif ($this->option('skip-reset-mail')) {
            $this->components->twoColumnDetail('Password', 'random; no setup link sent (use password reset to sign in)');
        } else {
            $this->components->twoColumnDetail('Password', 'setup link emailed');
        }

        return self::SUCCESS;
    }
}
