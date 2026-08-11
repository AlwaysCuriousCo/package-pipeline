<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A provider declining to serve this registry for a while, and when it says it
 * will start again.
 *
 * Told apart from every other failure on purpose. A rate limit is the one
 * error a sync should wait out rather than retry into: the jobs' backoffs are
 * measured in seconds and minutes, while GitHub's primary budget resets on the
 * hour, so retrying blind spends all three attempts against a wall and fails
 * the sync. It also has to be told apart in the *reporting*: GitHub answers a
 * secondary limit with 403, which is byte for byte the status it uses for bad
 * credentials, and a sync_error reading like an auth failure sends an admin to
 * rotate a perfectly good token.
 */
class RateLimited extends RuntimeException
{
    /**
     * The longest wait this will ask a job to sit out.
     *
     * GitHub's primary budget resets at most an hour away and its secondary
     * limits in seconds, so anything beyond this is a provider being strange
     * — and a job released for longer would outlive the two-hour window after
     * which SyncPackageJob presumes a batch lost.
     */
    private const MAX_WAIT = 3600;

    public function __construct(string $provider, public readonly CarbonImmutable $retryAt)
    {
        parent::__construct(
            "{$provider} rate limited this registry; the sync will retry at "
            .$retryAt->utc()->toDateTimeString().' UTC.'
        );
    }

    /**
     * The rate limit this response reports, or null when it reports none.
     *
     * The order of the tests is the whole of the logic. GitHub attaches
     * `x-ratelimit-*` headers to nearly every response it sends, a 403 for a
     * repository the token cannot see included, so the reset time is no
     * evidence of anything on its own — only a provider saying `Retry-After`
     * outright, or a budget with nothing left in it, distinguishes being
     * throttled from being refused.
     */
    public static function from(Response $response, string $provider): ?self
    {
        if ($response->status() !== 403 && $response->status() !== 429) {
            return null;
        }

        // How GitHub answers a secondary limit and GitLab a throttled one.
        if (is_numeric($after = $response->header('Retry-After'))) {
            return new self($provider, self::wait((int) $after));
        }

        // The primary budget, spent. GitHub spells the pair one way and
        // GitLab the other; both mean the next request is refused until the
        // reset they name.
        foreach (['X-RateLimit', 'RateLimit'] as $prefix) {
            if ($response->header("{$prefix}-Remaining") === '0') {
                return new self($provider, self::resetAt($response->header("{$prefix}-Reset")));
            }
        }

        // A bare 429 explains nothing but still means "not right now"; a 403
        // that got this far is an ordinary refusal and belongs to whoever
        // reads the response next.
        return $response->status() === 429 ? new self($provider, self::wait(60)) : null;
    }

    /**
     * The moment a `*-Reset` header names, which providers give as a unix
     * timestamp. A missing or nonsensical one falls back to a minute, which
     * is long enough not to hammer and short enough not to strand a sync.
     */
    private static function resetAt(string $reset): CarbonImmutable
    {
        return is_numeric($reset)
            ? self::wait((int) $reset - CarbonImmutable::now()->getTimestamp())
            : self::wait(60);
    }

    /**
     * A wait of the given seconds, bounded at both ends: a reset already in
     * the past would release a job straight back into the limit, and one far
     * in the future would park it for longer than anything here should wait.
     */
    private static function wait(int $seconds): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds(max(1, min($seconds, self::MAX_WAIT)));
    }
}
