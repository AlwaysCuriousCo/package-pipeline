<?php

namespace App\Console\Commands;

use App\Enums\TokenAbility;
use App\Models\DeployToken;
use App\Models\Token;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Issues a Composer access token from the console — provisioning a CI system
 * or a teammate's machine without a trip through the panel.
 */
class AddToken extends Command
{
    protected $signature = 'token:add
        {name : What the token is for; shown in listings}
        {--user= : Issue a personal token for the user with this email}
        {--deploy= : Issue for the deploy token with this name, creating it if missing}
        {--ability=* : read, write, or any ability name (api:read, api:write, api:delete); read when omitted}
        {--expires-days= : Expire after this many days; never when omitted}
        {--username= : The HTTP Basic username to configure; the owner\'s email or deploy token name when omitted}';

    protected $description = 'Issue an access token for a user or a deploy token';

    public function handle(): int
    {
        $owner = $this->resolveOwner();

        if ($owner === null) {
            return self::FAILURE;
        }

        $abilities = $this->resolveAbilities();

        if ($abilities === null) {
            return self::FAILURE;
        }

        $days = $this->option('expires-days');

        $new = Token::issue(
            $owner,
            (string) $this->argument('name'),
            $abilities,
            filled($days) ? now()->addDays((int) $days)->endOfDay() : null,
            username: filled($this->option('username')) ? (string) $this->option('username') : null,
        );

        // No request to read a host from out here, so the configured app URL
        // is the registry's name.
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $line = fn (bool $global): string => 'composer config '.($global ? '--global ' : '')
            ."http-basic.{$host} {$new->token->composerUsername()} {$new->plainText}";

        $this->components->info('Token created. Copy it now — it will not be shown again.');
        $this->line($new->plainText);
        $this->newLine();
        $this->components->bulletList([
            $line(false),
            $line(true).'  (this machine, every project)',
        ]);

        return self::SUCCESS;
    }

    /**
     * The principal the token belongs to: exactly one of --user / --deploy.
     */
    private function resolveOwner(): ?Model
    {
        $email = $this->option('user');
        $deploy = $this->option('deploy');

        if (filled($email) === filled($deploy)) {
            $this->components->error('Pass exactly one of --user=<email> or --deploy=<name>.');

            return null;
        }

        if (filled($email)) {
            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User) {
                $this->components->error("No user has the email address {$email}.");

                return null;
            }

            return $user;
        }

        // A deploy token is only a name and its grants; naming a new one here
        // creates an unscoped principal, exactly like the panel's create form
        // before any grants are picked.
        return DeployToken::query()->firstOrCreate(['name' => $deploy]);
    }

    /**
     * @return list<TokenAbility>|null
     */
    private function resolveAbilities(): ?array
    {
        $options = (array) $this->option('ability') ?: ['read'];

        $abilities = [];

        foreach ($options as $option) {
            // `read` and `write` are the Composer abilities' shorthands, from
            // when they were the only two. The management API's are named in
            // full, because there is no shorthand that would not read as one
            // of those.
            $ability = match ($option) {
                'read' => TokenAbility::RepositoryRead,
                'write' => TokenAbility::RepositoryWrite,
                default => TokenAbility::tryFrom($option),
            };

            if ($ability === null) {
                $this->components->error(sprintf(
                    'Unknown ability "%s". Use read, write, or one of: %s.',
                    $option,
                    implode(', ', array_column(TokenAbility::cases(), 'value')),
                ));

                return null;
            }

            $abilities[$ability->value] = $ability;
        }

        return array_values($abilities);
    }
}
