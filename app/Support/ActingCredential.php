<?php

namespace App\Support;

use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\AuthenticateComposer;
use App\Models\Activity;
use App\Models\DeployToken;
use App\Models\Token;
use App\Models\User;
use Spatie\Activitylog\Facades\CauserResolver;

/**
 * Who a token-authenticated request acts as, and which credential it presented.
 *
 * Spatie attributes a change to `auth()->user()`, which is null on every
 * surface that does not use the session — so before this, everything published,
 * synced or deleted through `/api/v1` or a Composer upload was filed with no
 * causer at all and read as "System" in the panel. A registry's most sensitive
 * writes arrive over exactly those two surfaces.
 *
 * Two separate facts get recorded, because they answer different questions.
 *
 * The **causer** is the token's principal: the User for a personal access
 * token, the DeployToken itself for a machine credential. A deploy token has no
 * person behind it and inventing one would be worse than naming nobody — it is
 * its own principal everywhere else in the app (it holds grants, it is what
 * `mayWriteTo()` asks about), so it is its own causer here.
 *
 * The **credential** is the token that was presented, kept as a property on
 * every entry the request writes. "This user" is not "this user's CI token":
 * when a leaked token is found in a build log, the question is what *that
 * token* did, and a causer alone cannot answer it. The name and prefix are what
 * the token listing shows, so the entry and the listing name the same thing.
 *
 * Request-scoped: bound with `scoped()` in AppServiceProvider, set by the
 * authenticating middleware, and gone with the container at the end of the
 * request. Queued work dispatched from that request runs in another process and
 * is unattributed, as it was before.
 *
 * @see AuthenticateApi
 * @see AuthenticateComposer
 * @see Activity where the credential is stamped onto each entry
 */
class ActingCredential
{
    private ?Token $token = null;

    /**
     * Attribute everything this request records to the presented token.
     */
    public function actAs(Token $token): void
    {
        $this->token = $token;

        // The principal, not the token: a token is a credential the principal
        // holds, and rolling it must not make last month's changes look as
        // though somebody else made them.
        CauserResolver::setCauser($token->tokenable instanceof User || $token->tokenable instanceof DeployToken
            ? $token->tokenable
            : null);
    }

    /**
     * How the log names the credential — `production-deploys (pp_a1b2c3d4)` —
     * or null when the request presented none.
     *
     * The prefix as well as the name, because names are neither unique nor
     * stable: a rolled token is a new row, and CI conventions give it the same
     * name as the one it replaced.
     */
    public function describe(): ?string
    {
        if (! $this->token instanceof Token) {
            return null;
        }

        return "{$this->token->name} ({$this->token->token_prefix})";
    }
}
