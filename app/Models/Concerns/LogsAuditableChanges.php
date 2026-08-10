<?php

namespace App\Models\Concerns;

use App\Models\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Records who changed a record, and what about it changed.
 *
 * This is a registry for supply-chain artifacts: a rolled token, a widened
 * grant and a deleted package are all things somebody will need to attribute
 * months later, and none of them left a trace before.
 *
 * The trait exists rather than each model configuring LogsActivity itself
 * because of one rule that must hold everywhere: **a secret must never reach
 * the activity log**. Encrypted casts (`token`, `client_secret`,
 * `webhook_secret`), the hashed password, and the sha256 an access token is
 * stored as are all ordinary attributes as far as Eloquent — and therefore
 * Spatie — is concerned, and an attribute diff is written to a table whose
 * whole purpose is to be read later, by more people, for longer.
 *
 * So each model states an allowlist (`auditedAttributes()`), and this trait
 * then filters that allowlist a second time against everything that looks
 * like a secret. Two independent gates, because one of them is a hand-written
 * list and hand-written lists go stale the day somebody adds a column.
 *
 * Both of those gates judge the *attribute*, and a third is needed because
 * some attributes carry a secret in their value while being entirely innocent
 * by name and cast. Every one of them here is a URL — an incoming webhook
 * endpoint, whose path *is* the credential for Slack, Teams and Discord; an
 * upstream's `https://user:pass@nexus.internal`; a package's
 * `https://x-access-token:ghp_…@github.com/acme/widgets`, which is a URL an
 * operator can legitimately paste into the form. So the third gate reads
 * values rather than names, and strips credentials out of anything shaped like
 * a URL on its way to the table.
 */
trait LogsAuditableChanges
{
    use LogsActivity;

    /**
     * Attribute names never written to the log, whatever a model asks for.
     *
     * Matched exactly, on the attribute name. The cast check below catches
     * the encrypted and hashed columns; this catches the ones stored already
     * digested — `access_tokens.token` holds a sha256 and carries no cast at
     * all, so nothing else would have stopped it.
     */
    private const NEVER_LOGGED = [
        'token', 'password', 'secret', 'client_secret', 'webhook_secret',
        'remember_token', 'api_key', 'private_key',
    ];

    /**
     * Query parameters whose value is a credential, matched case-insensitively
     * on the parameter name.
     *
     * A presigned S3 or Azure URL is the whole of the authorisation to fetch
     * the object, and a GitLab `private_token` is a session in a link. None of
     * these belongs in a table read months later by more people than saw the
     * change.
     */
    private const SECRET_QUERY_PARAMETERS = [
        'token', 'access_token', 'private_token', 'auth', 'api_key', 'apikey',
        'key', 'secret', 'client_secret', 'password', 'signature', 'sig', 'sas',
        'x-amz-signature', 'x-amz-credential', 'x-amz-security-token',
    ];

    /**
     * What replaces a credential, rather than dropping it silently: a reader
     * has to be able to tell "no password in this URL" from "a password was
     * here and the log declined to keep it".
     */
    private const REDACTED = '[redacted]';

    /**
     * The attributes this model considers worth attributing a change to.
     *
     * Stated per model rather than derived from `$fillable`, because the two
     * questions are different: fillable is "what may a form write", audited is
     * "what would somebody investigating an incident need to see". Sync
     * bookkeeping — `last_synced_at`, `sync_error`, `total_downloads` — is
     * deliberately absent: it changes hourly, was written by a worker rather
     * than a person, and would bury the changes that matter.
     *
     * @return list<string>
     */
    abstract protected function auditedAttributes(): array;

    /**
     * Audited attributes whose URL *path* is a credential in itself.
     *
     * Almost no URL is like this, which is why it is opt-in rather than the
     * default: a package's `repository` and an upstream's `url` are addresses
     * of things, authenticated by a token beside them, and a log that reduced
     * `https://github.com/acme/widgets` to `https://github.com` would have
     * thrown away the field an investigator came for. A chat provider's
     * incoming-webhook endpoint is the other kind — anyone holding the path can
     * post as the integration, and there is no second credential — so only the
     * origin of one is ever written down.
     *
     * @return list<string>
     */
    protected function opaqueUrlAttributes(): array
    {
        return [];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('audit')
            ->logOnly($this->loggableAttributes())
            // A save that touched nothing audited writes nothing. This is what
            // keeps the hourly sync from filing a row per package per hour.
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The last gate, run on the values themselves once the diff is built.
     *
     * Called by Spatie on the subject of every entry, so it covers the
     * attribute diff and the events recorded by hand — role grants, access
     * grants — without either having to remember.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->map(
            // `attributes` and `old` each hold one attribute-name => value map;
            // an event recorded on its own — a role, a grant — carries scalars
            // at this level instead, and has no attribute name to match.
            fn (mixed $values): mixed => is_array($values)
                ? $this->withoutCredentialsIn($values)
                : self::withoutCredentials($values, false),
        );
    }

    /**
     * One attribute-name => value map with its URLs cleaned up.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function withoutCredentialsIn(array $values): array
    {
        $opaque = $this->opaqueUrlAttributes();

        foreach ($values as $attribute => $value) {
            $values[$attribute] = self::withoutCredentials($value, in_array($attribute, $opaque, true));
        }

        return $values;
    }

    /**
     * A value with any credential its URL carried taken out of it.
     *
     * Anything that is not a URL is returned untouched — parse_url() accepts
     * almost any string, so an absolute scheme and host are what the decision
     * is made on. That leaves scp-style git remotes (`git@host:acme/app.git`)
     * alone, which carry no secret to take.
     */
    private static function withoutCredentials(mixed $value, bool $opaquePath): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $value;
        }

        // The username goes with the password rather than being kept as
        // context: `https://ghp_1234@github.com/...` is the whole credential in
        // the username field, and telling that apart from an account name is
        // guesswork this has no business doing.
        $userinfo = isset($parts['user']) || isset($parts['pass']) ? self::REDACTED.'@' : '';

        $origin = $parts['scheme'].'://'.$userinfo.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        if ($opaquePath) {
            return $origin.'/'.self::REDACTED;
        }

        return $origin
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.self::withoutSecretParameters($parts['query']) : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * The query string with credential-bearing parameters emptied.
     *
     * Rebuilt by hand rather than through parse_str()/http_build_query(), which
     * between them fold repeated parameters into one, rename keys containing
     * dots, and re-encode everything — turning "what was this URL" into
     * something close but not equal to the answer.
     */
    private static function withoutSecretParameters(string $query): string
    {
        return implode('&', array_map(
            function (string $pair): string {
                $name = explode('=', $pair, 2)[0];

                return in_array(mb_strtolower(urldecode($name)), self::SECRET_QUERY_PARAMETERS, true)
                    ? $name.'='.self::REDACTED
                    : $pair;
            },
            explode('&', $query),
        ));
    }

    /**
     * The model's allowlist, minus anything that looks like a secret.
     *
     * @return list<string>
     */
    protected function loggableAttributes(): array
    {
        $casts = $this->getCasts();

        return array_values(array_filter(
            $this->auditedAttributes(),
            function (string $attribute) use ($casts): bool {
                if (in_array($attribute, self::NEVER_LOGGED, true)) {
                    return false;
                }

                $cast = $casts[$attribute] ?? '';

                // `encrypted`, `encrypted:array`, `encrypted:collection` — and
                // `hashed`, which is not reversible but is still a credential
                // whose value has no business being copied around.
                return ! str_starts_with($cast, 'encrypted') && $cast !== 'hashed';
            },
        ));
    }
}
