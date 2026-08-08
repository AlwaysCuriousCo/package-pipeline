<?php

namespace App\Services\GitHub;

use App\Enums\WebhookState;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The registered GitHub App, which is how this registry authenticates against
 * repositories without anyone pasting a long-lived token.
 *
 * An admin installs the app onto an organisation; GitHub hands back an
 * installation id, and from there every API call uses an installation token
 * minted on demand. Those tokens expire after an hour and are scoped to the
 * repositories chosen at install time, so nothing durable and over-broad is
 * ever stored.
 */
class GitHubApp
{
    /**
     * GitHub rejects app JWTs valid for more than ten minutes. Nine leaves
     * room for the backdated `iat` below without crossing that line.
     */
    private const JWT_LIFETIME = 540;

    /**
     * Clock skew allowance. GitHub rejects a JWT whose `iat` is in the future
     * relative to its own clock, so it is issued a minute in the past.
     */
    private const JWT_BACKDATE = 60;

    /**
     * Margin subtracted from an installation token's expiry before caching, so
     * a token is never handed out with only seconds of life left.
     */
    private const TOKEN_EXPIRY_MARGIN = 300;

    /**
     * Whether an app has been registered and configured on this instance.
     * Sources fall back to their stored token when it has not.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.github.app.id'))
            && filled(config('services.github.app.private_key'));
    }

    /**
     * Whether the app's own webhook actually delivers to this registry.
     *
     * A secret in the environment is necessary — it is what proves a delivery
     * came from GitHub — but it is not evidence of anything on GitHub's side.
     * An app whose webhook was never switched on, or whose payload URL points
     * at another environment, will happily let a registry believe it is
     * covered while nothing is ever delivered, which is the one failure this
     * feature must not have. So GitHub is asked.
     *
     * A false answer is not a fault: it means packages fall back to a webhook
     * on their own repository, which is the general case anyway.
     */
    public function hasWebhook(): bool
    {
        return match ($this->webhookState()) {
            WebhookState::Delivering => true,
            // Nothing to ask GitHub with, or GitHub could not be reached. The
            // configured secret keeps its benefit of the doubt: an outage is
            // not evidence the webhook is gone, and treating it as such would
            // grow a repository hook on every package created during one.
            WebhookState::Unverifiable => true,
            WebhookState::NoSecret, WebhookState::Absent, WebhookState::Elsewhere => false,
        };
    }

    /**
     * What GitHub says about the app's webhook, which is not always what the
     * environment implies.
     */
    public function webhookState(): WebhookState
    {
        if (blank(config('services.github.app.webhook_secret'))) {
            return WebhookState::NoSecret;
        }

        if (! $this->isConfigured()) {
            return WebhookState::Unverifiable;
        }

        try {
            $configured = $this->webhookConfiguration();
        } catch (Throwable) {
            // GitHub being unreachable is not evidence that the webhook is
            // gone, and treating it as such would have every package created
            // during an outage grow a repository hook it does not need. The
            // configured secret keeps its benefit of the doubt.
            return WebhookState::Unverifiable;
        }

        return match (true) {
            $configured === null => WebhookState::Absent,
            ($configured['url'] ?? null) === $this->webhookUrl() => WebhookState::Delivering,
            default => WebhookState::Elsewhere,
        };
    }

    /**
     * Where the app's webhook actually posts, when that is knowable and is
     * somewhere other than here — the detail that turns "pointing elsewhere"
     * into something an admin can act on.
     */
    public function deliveringTo(): ?string
    {
        try {
            $url = $this->isConfigured() ? ($this->webhookConfiguration()['url'] ?? null) : null;
        } catch (Throwable) {
            return null;
        }

        return is_string($url) ? $url : null;
    }

    /**
     * The app's webhook configuration as GitHub holds it, or null when the app
     * has no webhook — which is what a 404 on this endpoint means.
     *
     * Cached briefly: it is read on every page that reports coverage, and it
     * changes only when someone edits the app's settings.
     *
     * @return array<string, mixed>|null
     */
    public function webhookConfiguration(): ?array
    {
        $this->ensureConfigured();

        // "No webhook" is cached as false rather than null, because a null is
        // indistinguishable from a cache miss and would put this request back
        // on GitHub for every page that asks.
        $configured = Cache::remember(
            'github-app.webhook-config.'.config('services.github.app.id'),
            now()->addMinutes(5),
            function (): array|false {
                $response = $this->request()->withToken($this->jwt())->get('/app/hook/config');

                // GitHub answers 404 here when the app has no webhook at all,
                // which is what an app registered with "Active" unchecked has.
                if ($response->status() === 404) {
                    return false;
                }

                return $response->throw()->json();
            },
        );

        return $configured === false ? null : $configured;
    }

    /**
     * Drop the cached answer, so a webhook just switched on is seen now rather
     * than in five minutes.
     */
    public function forgetWebhookConfiguration(): void
    {
        Cache::forget('github-app.webhook-config.'.config('services.github.app.id'));
    }

    /**
     * The payload URL to set on the app's webhook. One URL for every
     * repository in every installation.
     */
    public function webhookUrl(): string
    {
        return route('webhooks.github');
    }

    /**
     * A secret to offer an admin who has not set one, so that configuring the
     * webhook is copying two values rather than inventing one.
     *
     * It is remembered for a day because it is shown in two places that have
     * to agree — GitHub's settings page and an `.env` file — and a suggestion
     * that changed on every page load could not be used for either.
     */
    public function suggestedWebhookSecret(): string
    {
        return Cache::remember(
            'github-app.suggested-webhook-secret',
            now()->addDay(),
            fn (): string => Str::random(40),
        );
    }

    /**
     * Where to send an admin to install the app. GitHub returns them to the
     * app's configured setup URL with `installation_id` and this `state`.
     */
    public function installationUrl(string $state): string
    {
        $this->ensureConfigured();

        return "https://github.com/apps/{$this->slug()}/installations/new?"
            .http_build_query(['state' => $state]);
    }

    /**
     * The app's public name, which is the only part of the install URL that
     * is not fixed.
     *
     * GitHub reports it against the app's own credentials, so it does not need
     * configuring; it is cached because it only changes if the app is renamed,
     * and an override exists for that case and for saving the round trip.
     */
    private function slug(): string
    {
        if (filled($configured = config('services.github.app.slug'))) {
            return $configured;
        }

        return Cache::remember(
            'github-app.slug.'.config('services.github.app.id'),
            now()->addDay(),
            function (): string {
                $slug = $this->request()->withToken($this->jwt())->get('/app')->throw()->json('slug');

                throw_unless(is_string($slug), new RuntimeException(
                    'GitHub did not report a slug for this app; check GITHUB_APP_ID matches the private key.'
                ));

                return $slug;
            },
        );
    }

    /**
     * The installation record, which tells us which account the app was
     * installed onto.
     *
     * @return array<string, mixed>
     */
    public function installation(int $installationId): array
    {
        return $this->request()
            ->withToken($this->jwt())
            ->get("/app/installations/{$installationId}")
            ->throw()
            ->json();
    }

    /**
     * A token for acting on the installation's repositories.
     *
     * Tokens are cached until shortly before GitHub expires them, so a sync
     * touching many repositories mints one rather than one per call. The cache
     * holds them encrypted because it is a shared store that outlives the
     * request.
     */
    public function installationToken(int $installationId): string
    {
        $key = "github-app.installation-token.{$installationId}";

        if (is_string($cached = Cache::get($key))) {
            return Crypt::decryptString($cached);
        }

        $response = $this->request()
            ->withToken($this->jwt())
            ->post("/app/installations/{$installationId}/access_tokens")
            ->throw()
            ->json();

        $token = $response['token'] ?? null;

        throw_unless(
            is_string($token),
            new RuntimeException("GitHub did not return an access token for installation {$installationId}."),
        );

        $expiresAt = strtotime($response['expires_at'] ?? '') ?: time() + 3600;
        $ttl = $expiresAt - time() - self::TOKEN_EXPIRY_MARGIN;

        if ($ttl > 0) {
            Cache::put($key, Crypt::encryptString($token), $ttl);
        }

        return $token;
    }

    /**
     * Drop any cached token, so the next call mints a fresh one. Used when an
     * installation is reconnected or removed.
     */
    public function forgetInstallationToken(int $installationId): void
    {
        Cache::forget("github-app.installation-token.{$installationId}");
    }

    /**
     * A short-lived JWT identifying the app itself, which is what GitHub
     * accepts on the `/app/*` endpoints.
     */
    private function jwt(): string
    {
        $this->ensureConfigured();

        $issuedAt = time() - self::JWT_BACKDATE;

        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode(json_encode([
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::JWT_LIFETIME,
                'iss' => (string) config('services.github.app.id'),
            ])),
        ];

        $payload = implode('.', $segments);

        throw_unless(
            openssl_sign($payload, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256),
            new RuntimeException('Unable to sign the GitHub App JWT; check GITHUB_APP_PRIVATE_KEY is a valid RSA private key.'),
        );

        return $payload.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * The app's RSA private key, accepted either as a path to the .pem file
     * GitHub generates or as the key itself (whose newlines survive an
     * environment file only when escaped, so "\n" is restored here).
     */
    private function privateKey(): string
    {
        $key = (string) config('services.github.app.private_key');

        if (! str_contains($key, 'PRIVATE KEY')) {
            throw_unless(
                File::exists($key),
                new RuntimeException("GITHUB_APP_PRIVATE_KEY is neither a PEM key nor a readable file path [{$key}]."),
            );

            return File::get($key);
        }

        return str_replace('\n', "\n", $key);
    }

    private function ensureConfigured(): void
    {
        throw_unless($this->isConfigured(), new RuntimeException(
            'No GitHub App is configured. Set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY, '
            .'or give the source its own access token.'
        ));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.github.app.api_url'))
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->acceptJson();
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
