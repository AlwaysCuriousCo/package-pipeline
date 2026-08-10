<?php

namespace App\Support;

/**
 * How long an outbound request may take, by what the request is for.
 *
 * Laravel's HTTP client applies one 30-second budget to everything, which is
 * simultaneously too short for the only request here that legitimately takes
 * minutes and too long for every request that does not. Left at the default, a
 * version import of a large archive over a slow link can never succeed: the
 * download is cut at 30 seconds inside a job allowed 300, fails its retries,
 * and comes back on every sync afterwards.
 */
final class HttpTimeouts
{
    /**
     * Establishing the connection, which is a handshake against an endpoint
     * that is either there or is not. A provider that has not answered in ten
     * seconds is down rather than slow, whatever the request was going to be,
     * so this is the same for all of them.
     */
    public const CONNECT = 10;

    /**
     * Provider API reads and writes: listing refs, a composer.json, a commit
     * date, a token exchange, a webhook. All small responses, all made either
     * inside a job whose budget the rest of the sync also needs or inside a
     * page an admin is looking at. Generous against a provider having a bad
     * minute, short enough that a hung one fails rather than hangs with it.
     */
    public const API = 20;

    /**
     * Streaming a repository archive, the one request whose duration is a
     * function of the repository rather than of the provider's health. Sized
     * to fit inside ImportVersion's 300-second job timeout with room left for
     * the zip to be unpacked and stored afterwards.
     */
    public const ARCHIVE = 240;

    /**
     * Calls made while a person waits mid-login — the issuer's discovery
     * document and userinfo endpoint both land in the middle of the redirect
     * round trip. Half the API budget because a stalled login is a user
     * staring at a blank tab, and because discovery is retried: two attempts
     * still cost less than the client's own default allowed for one.
     */
    public const LOGIN = 10;

    /**
     * Asking an upstream about vulnerabilities, which happens inside somebody
     * else's budget.
     *
     * Composer allows this app's `/security-advisories` ten seconds and no
     * more — `getSecurityAdvisories` sets that on its own request — so a
     * mirroring registry that spent the API budget on the upstream would
     * exceed the caller's before it could answer, and fail their audit rather
     * than merely answering it late. Applied to the connection *and* the read,
     * so the worst case is twice this and still inside what the caller allows.
     *
     * An audit is also the one outbound call here whose answer is optional:
     * `composer audit` must not fail an install, so a budget too short for a
     * struggling upstream costs a passthrough that was best-effort anyway.
     */
    public const ADVISORY = 4;

    /**
     * The login budget spelled as Guzzle options, for Socialite: it exchanges
     * the authorization code over a client of its own rather than through the
     * HTTP facade, and that client has no timeout at all until it is given
     * one — an issuer that accepts the connection and then says nothing would
     * otherwise hold a PHP worker open indefinitely.
     *
     * @return array{timeout: int, connect_timeout: int}
     */
    public static function guzzle(): array
    {
        return ['timeout' => self::LOGIN, 'connect_timeout' => self::CONNECT];
    }
}
