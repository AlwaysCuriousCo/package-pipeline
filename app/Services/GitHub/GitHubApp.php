<?php

namespace App\Services\GitHub;

use App\Enums\GitHubAppOwner;
use App\Enums\WebhookState;
use App\Models\GitHubAppRegistration;
use App\Models\Source;
use App\Support\HttpTimeouts;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
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
        $credentials = $this->credentials();

        return filled($credentials['id']) && filled($credentials['private_key']);
    }

    /**
     * Whether the environment names the app. When it does, it is the whole
     * answer: an app registered from the panel is ignored rather than mixed
     * in, since an id from one place and a key from the other would only ever
     * produce a JWT GitHub rejects.
     *
     * Either variable counts, so a half-configured environment reports itself
     * as unconfigured instead of quietly falling back to something else.
     */
    public function configuredFromEnvironment(): bool
    {
        return filled(config('services.github.app.id'))
            || filled(config('services.github.app.private_key'));
    }

    /**
     * The app registered from the panel, when the environment does not name
     * one. Read once per instance: a single page asks several questions of it.
     */
    public function registration(): ?GitHubAppRegistration
    {
        if ($this->configuredFromEnvironment()) {
            return null;
        }

        return once(fn (): ?GitHubAppRegistration => GitHubAppRegistration::current());
    }

    /**
     * The secret GitHub signs the app's webhook deliveries with.
     *
     * The environment's own secret wins, as it always has, and may be set
     * without the app itself — the webhook can be configured before or after
     * the rest. A registered app's secret is only used when the app in use is
     * that registered one; it was generated for its webhook and nobody else's.
     */
    public function webhookSecret(): ?string
    {
        if (filled($secret = config('services.github.app.webhook_secret'))) {
            return (string) $secret;
        }

        return $this->registration()?->webhook_secret;
    }

    /**
     * Store an app registered from the panel, once GitHub confirms the key is
     * the app's.
     *
     * Both registration paths end here: the manifest conversion, whose
     * credentials came straight from GitHub, and the enterprise form, whose
     * id and key were brought back by hand. Checking the first is cheap
     * insurance; checking the second is the point.
     */
    public function register(GitHubAppOwner $ownerType, string $owner, string $appId, string $privateKey, ?string $webhookSecret): GitHubAppRegistration
    {
        throw_if($this->isConfigured(), new RuntimeException('A GitHub App is already configured. Forget it before registering another.'));

        $app = $this->describe($appId, $privateKey);

        return GitHubAppRegistration::query()->create([
            'app_id' => $appId,
            'slug' => $app['slug'],
            'name' => is_string($app['name'] ?? null) ? $app['name'] : $app['slug'],
            'owner' => $owner,
            'owner_type' => $ownerType,
            'html_url' => is_string($app['html_url'] ?? null) ? $app['html_url'] : "https://github.com/apps/{$app['slug']}",
            'private_key' => $privateKey,
            'webhook_secret' => filled($webhookSecret) ? $webhookSecret : null,
        ]);
    }

    /**
     * Give a registered app's webhook a secret of this registry's choosing.
     *
     * An enterprise app is created from a prefilled form, and no parameter
     * can fill in the webhook secret, so rather than have the admin invent
     * one and paste it, the registry sets it through the app's own API once
     * the key is verified. The form already switched the webhook on and
     * pointed it here; this adds the secret, and restates the rest so the
     * three always agree.
     */
    public function configureWebhook(GitHubAppRegistration $registration): void
    {
        $secret = Str::random(40);

        try {
            $this->request()
                ->withToken($this->jwt(['id' => $registration->app_id, 'private_key' => $registration->private_key]))
                ->patch('/app/hook/config', [
                    'url' => $this->webhookUrl(),
                    'content_type' => 'json',
                    'secret' => $secret,
                    'insecure_ssl' => '0',
                ])
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException($exception->response->status() === 404
                ? 'The app has no webhook to configure: on GitHub, tick Webhook → Active on the app\'s settings page, then try again. '.GitHubError::describe($exception)
                : 'GitHub refused the webhook settings. '.GitHubError::describe($exception), previous: $exception);
        }

        $registration->update(['webhook_secret' => $secret]);

        Cache::forget('github-app.webhook-config.'.$registration->app_id);
    }

    /**
     * Forget the app registered from the panel.
     *
     * Sources installed through it keep their records and package links, as
     * a disconnect does, but stop authenticating until the app they were
     * installed on is configured again. Their cached tokens go now rather
     * than in an hour, so "forgotten" means it from this request on.
     */
    public function forget(): void
    {
        $registration = $this->registration();

        if ($registration === null) {
            return;
        }

        Source::query()->whereNotNull('installation_id')->pluck('installation_id')
            ->each(fn (int|string $installationId) => $this->forgetInstallationToken((int) $installationId));

        Cache::forget('github-app.slug.'.$registration->app_id);
        Cache::forget('github-app.webhook-config.'.$registration->app_id);

        $registration->delete();
    }

    /**
     * Ask GitHub which app a key belongs to, before it is stored.
     *
     * The same JWT the app signs every other call with, minted from the
     * candidate credentials rather than the configured ones. A key from
     * another app fails here with GitHub's own 401 instead of failing every
     * sync later.
     *
     * @return array<string, mixed>
     */
    public function describe(string $appId, string $privateKey): array
    {
        try {
            $app = $this->request()
                ->withToken($this->jwt(['id' => $appId, 'private_key' => $privateKey]))
                ->get('/app')
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new RuntimeException($exception->response->status() === 401
                ? "GitHub did not accept that private key for app {$appId}. ".GitHubError::describe($exception)
                : "GitHub could not be asked about app {$appId}. ".GitHubError::describe($exception), previous: $exception);
        }

        // GitHub answers for whichever app signed the JWT, which is the one the
        // key belongs to. An id that is not the one asked about means the two
        // were copied from different apps.
        throw_unless(
            is_array($app) && (string) ($app['id'] ?? '') === $appId && is_string($app['slug'] ?? null),
            new RuntimeException("That private key does not belong to app {$appId}."),
        );

        return $app;
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
        if (blank($this->webhookSecret())) {
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
            'github-app.webhook-config.'.$this->credentials()['id'],
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
        Cache::forget('github-app.webhook-config.'.$this->credentials()['id']);
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
        if (filled($known = $this->credentials()['slug'])) {
            return $known;
        }

        return Cache::remember(
            'github-app.slug.'.$this->credentials()['id'],
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
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                // The key that encrypted this is not the key in use now — an
                // APP_KEY rotation, or a cache shared with another instance.
                // Left to throw, every app-authenticated sync fails with an
                // opaque decrypt error until the entry expires the better
                // part of an hour later, over a token that is cheap to mint
                // again. Forgotten, the next line simply mints it.
                Cache::forget($key);
            }
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
     *
     * Signed with the configured app unless other credentials are given, which
     * is how a registration is checked before anything is stored.
     *
     * @param  array{id: ?string, private_key: ?string}|null  $credentials
     */
    private function jwt(?array $credentials = null): string
    {
        if ($credentials === null) {
            $this->ensureConfigured();

            $credentials = $this->credentials();
        }

        $issuedAt = time() - self::JWT_BACKDATE;

        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64UrlEncode(json_encode([
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::JWT_LIFETIME,
                'iss' => (string) $credentials['id'],
            ])),
        ];

        $payload = implode('.', $segments);

        throw_unless(
            openssl_sign($payload, $signature, $this->pem((string) $credentials['private_key']), OPENSSL_ALGO_SHA256),
            new RuntimeException('Unable to sign the GitHub App JWT; check the private key is a valid RSA private key.'),
        );

        return $payload.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * The app in use: the environment's when it names one, otherwise the one
     * registered from the panel, otherwise nothing.
     *
     * Every question about the app's identity goes through here, so the two
     * places it can be configured cannot drift into answering differently.
     *
     * @return array{id: ?string, private_key: ?string, slug: ?string}
     */
    private function credentials(): array
    {
        if ($this->configuredFromEnvironment()) {
            return [
                'id' => filled($id = config('services.github.app.id')) ? (string) $id : null,
                'private_key' => filled($key = config('services.github.app.private_key')) ? (string) $key : null,
                'slug' => filled($slug = config('services.github.app.slug')) ? (string) $slug : null,
            ];
        }

        $registration = $this->registration();

        return [
            'id' => $registration?->app_id,
            'private_key' => $registration?->private_key,
            'slug' => $registration?->slug,
        ];
    }

    /**
     * The app's RSA private key, accepted either as a path to the .pem file
     * GitHub generates or as the key itself (whose newlines survive an
     * environment file only when escaped, so "\n" is restored here). A key
     * registered from the panel is always the latter, with real newlines.
     */
    private function pem(string $key): string
    {
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
            'No GitHub App is configured. Register one under Sources → Connect GitHub → Register GitHub App, set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY, '
            .'or give the source its own access token.'
        ));
    }

    private function request(): PendingRequest
    {
        // Everything here is a small call on the app's own endpoints, and half
        // of it runs while an admin waits on a panel page that reports webhook
        // coverage; none of it may hang for GitHub's benefit.
        return Http::baseUrl(config('services.github.app.api_url'))
            ->timeout(HttpTimeouts::API)
            ->connectTimeout(HttpTimeouts::CONNECT)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->acceptJson();
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
