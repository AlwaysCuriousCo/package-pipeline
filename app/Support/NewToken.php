<?php

namespace App\Support;

use App\Models\Token;

/**
 * A freshly issued access token, carrying the one and only copy of its
 * plain text. The caller shows it once; only the hash survives.
 */
final readonly class NewToken
{
    public function __construct(
        public Token $token,
        public string $plainText,
    ) {}
}
