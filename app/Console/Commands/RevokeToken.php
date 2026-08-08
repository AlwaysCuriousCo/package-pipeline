<?php

namespace App\Console\Commands;

use App\Models\Token;
use Illuminate\Console\Command;

/**
 * Revokes an access token by its prefix — the identifier every listing
 * shows, and all anyone still has once the plain text is out in the wild.
 */
class RevokeToken extends Command
{
    protected $signature = 'token:revoke
        {prefix : The token prefix shown in listings (e.g. pp_ab1cd)}';

    protected $description = 'Revoke a Composer access token by its prefix';

    public function handle(): int
    {
        $tokens = Token::query()->where('token_prefix', $this->argument('prefix'))->get();

        if ($tokens->isEmpty()) {
            $this->components->error('No live token has that prefix.');

            return self::FAILURE;
        }

        foreach ($tokens as $token) {
            $token->delete();

            $this->components->twoColumnDetail(
                "{$token->token_prefix}… ({$token->name})",
                'revoked',
            );
        }

        return self::SUCCESS;
    }
}
