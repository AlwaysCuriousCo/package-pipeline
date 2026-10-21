<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Support\HttpTimeouts;
use Closure;
use Composer\Semver\VersionParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;
use ZipArchive;

/**
 * Installs a package the way Composer would, against the registry's own
 * public address, and says what happened at each step.
 *
 * Everything the panel can check from the inside — that a package synced,
 * that an archive is stored — can be true while `composer require` still
 * fails, because what fails is usually in between: DNS, a certificate, a
 * proxy that strips the Authorization header, a CDN rule that answers with
 * its own error page. So the test goes out through APP_URL and back in, as a
 * consuming project would:
 *
 *  1. `packages.json` without credentials, which a private repository must
 *     refuse — the proof that authentication is enforced at all;
 *  2. `packages.json` again with a token, over HTTP Basic, exactly as
 *     `composer config http-basic` sends it;
 *  3. the package's metadata, through the `metadata-url` that answer gave;
 *  4. the archive of the chosen version, checked against the size and shasum
 *     the metadata published, and opened as a zip.
 *
 * The token is made for the run and deleted when it ends, however it ends.
 * Its dist request is not counted as a download, and the run leaves one audit
 * entry rather than a token created and revoked.
 */
class InstallationTest
{
    /**
     * The whole run gives up after this long; each request is held to what
     * is left of it as well as to its own timeout.
     */
    public const TIMEOUT = 30;

    /**
     * How much of a failing response body the transcript quotes.
     */
    private const EXCERPT = 300;

    private float $deadline = 0.0;

    /**
     * The host the registry's own address resolves to, which is the only one
     * the test's credentials may ever be sent to. Set when the run starts.
     */
    private string $credentialHost = '';

    /**
     * @var Closure(string, string): void
     */
    private Closure $report;

    private ?string $failedStep = null;

    /**
     * How long the last request took, for the line that reports it.
     */
    private int $elapsedMs = 0;

    /**
     * Run the test, reporting each line through $report as it happens.
     *
     * Lines come with a tone the transcript styles them by: `command`,
     * `request`, `ok`, `fail`, `detail`, `hint` and `done`.
     *
     * @param  Closure(string, string): void  $report
     */
    public function run(Package $package, User $user, ?string $version, Closure $report): bool
    {
        $this->report = $report;
        $this->deadline = microtime(true) + self::TIMEOUT;
        $this->failedStep = null;

        $repository = $package->composerRepository;
        $root = rtrim($repository->url(), '/');
        $host = (string) parse_url($root, PHP_URL_HOST);
        $this->credentialHost = $host;

        $new = Token::issueForInstallationTest($user);

        try {
            $this->line('Credentials redacted; test uses the actual issued credentials.', 'detail');
            $this->line($repository->configureCommand(), 'command');
            $this->line("composer config http-basic.{$host} <composer-username> <temporary-token>", 'command');
            $this->line("composer require {$package->name}".($version ? ":{$version}" : ''), 'command');

            $passed = $this->probe($repository, $root)
                && ($metadataUrl = $this->root($root, $new->token->composerUsername(), $new->plainText)) !== null
                && ($release = $this->metadata($package, $root, $metadataUrl, $version, $new->token->composerUsername(), $new->plainText)) !== null
                && $this->dist($release, $new->token->composerUsername(), $new->plainText);

            if ($passed) {
                $this->line("Installation test passed: {$package->name} {$release['version']} — metadata, authentication and archive checks passed at {$root}.", 'done');
            }
        } catch (Throwable $exception) {
            $passed = false;
            $this->failedStep ??= 'unexpected';
            $this->line($exception->getMessage(), 'fail');
        } finally {
            // Always, whichever step stopped the run: a test credential must
            // not outlive the test.
            activity()->withoutLogs(fn () => $new->token->forceDelete());
        }

        activity('audit')
            ->causedBy($user)
            ->performedOn($package)
            ->event('installation-test')
            ->withProperties([
                'repository' => $root,
                'version' => $version,
                'passed' => $passed,
                'failed_step' => $this->failedStep,
            ])
            ->log('installation-test');

        // The answer every first-run screen reads changed just now; the next
        // reader gets it from the log, not from the cache's minute.
        Cache::forget('installation-test.has-passed');

        return $passed;
    }

    /**
     * Whether any installation test has passed on this registry, which the
     * dashboard's first-run steps count as the last one done.
     *
     * The answer rides the activity log, which has no index for a JSON path
     * and can be long on a busy registry; it is cached for a minute, and a
     * run clears the cache itself so its own result is seen at once.
     */
    public static function hasPassed(): bool
    {
        return Cache::remember(
            'installation-test.has-passed',
            now()->addMinute(),
            fn (): bool => Activity::query()
                ->where('event', 'installation-test')
                ->where('properties->passed', true)
                ->exists(),
        );
    }

    /**
     * Step 1: no credentials. A private repository must answer 401, and a
     * public one 200; either way this is the first proof that the address
     * reaches this registry.
     */
    private function probe(Repository $repository, string $root): bool
    {
        $url = "{$root}/packages.json";
        $this->line("Checking {$url} without credentials", 'request');

        $response = $this->get('reach', $url);

        if ($response === null) {
            return false;
        }

        $expected = $repository->public ? 200 : 401;

        if ($response->status() !== $expected) {
            return $this->fail('reach', $response, $repository->public
                ? 'This public repository should answer without credentials. If the status is from a proxy or CDN, let /packages.json through.'
                : ($response->successful()
                    ? 'The repository answered without credentials, so anyone can read it. Check it is not marked public, and that nothing in front of the registry is caching authenticated responses.'
                    : $this->hintFor('reach', $response)));
        }

        $this->ok($response, $repository->public ? 'public repository, no credentials needed' : 'credentials required');

        return true;
    }

    /**
     * Step 2: packages.json with the token, which yields the metadata URL.
     */
    private function root(string $root, string $username, string $password): ?string
    {
        $url = "{$root}/packages.json";
        $this->line("Downloading {$url}", 'request');

        $response = $this->get('authenticate', $url, $username, $password);

        if ($response === null) {
            return null;
        }

        if (! $response->successful()) {
            $this->fail('authenticate', $response, $this->hintFor('authenticate', $response));

            return null;
        }

        $template = $response->json('metadata-url');

        if (! is_string($template)) {
            $this->fail('authenticate', $response, 'The answer is not this registry\'s packages.json: something in front of it replied instead. Check the proxy or CDN passes the request through.');

            return null;
        }

        $this->ok($response);

        return $template;
    }

    /**
     * Step 3: the package's metadata, and the version to install from it.
     *
     * @return array<string, mixed>|null
     */
    private function metadata(Package $package, string $root, string $template, ?string $requested, string $username, string $password): ?array
    {
        $this->line('Reading package details', 'request');
        $versions = $this->versionsFrom($package->name, $root, $template, $username, $password);

        if ($versions === null) {
            return null;
        }

        $release = $this->choose($versions, $requested);

        // Composer keeps branches in a second file, ~dev, and reads it for a
        // dev constraint — or here, for a package with no tagged release yet.
        if ($release === null && ($requested === null || str_starts_with($requested, 'dev-') || str_ends_with($requested, '-dev'))) {
            $dev = $this->versionsFrom("{$package->name}~dev", $root, $template, $username, $password);

            if ($dev === null) {
                return null;
            }

            $release = $this->choose($dev, $requested);
        }

        if ($release === null) {
            $this->failedStep = 'metadata';
            $this->line($requested
                ? "{$package->name} has no version {$requested} here."
                : "{$package->name} has no versions yet.", 'fail');
            $this->line($requested
                ? 'Hint: pick a version the package page lists, or sync the package if it is new.'
                : 'Hint: sync it, and check the queue worker is running.', 'hint');

            return null;
        }

        // A bare `composer require` only takes stable releases, so a pick that
        // is not one says what a project has to require instead.
        $constraint = $requested === null ? self::prereleaseConstraint((string) $release['version']) : null;

        $this->line("  Picked {$package->name} {$release['version']}".match (true) {
            $requested === null && str_starts_with((string) $release['version'], 'dev-') => ' — no tagged release yet, so a project needs `:'.$release['version'].'` or minimum-stability dev to require it',
            $constraint !== null => " — no stable release yet, so a project needs `:{$constraint}` to require it",
            default => '',
        }, 'ok');

        if ($constraint !== null) {
            $this->line("composer require {$package->name}:{$constraint}", 'command');
        }

        return $release;
    }

    /**
     * One metadata file, expanded, or null after reporting why not.
     *
     * @return list<array<string, mixed>>|null
     */
    private function versionsFrom(string $name, string $root, string $template, string $username, string $password): ?array
    {
        $url = $this->resolve($root, str_replace('%package%', $name, $template));
        $this->line("Downloading {$url}", 'request');

        $response = $this->get('metadata', $url, $username, $password);

        if ($response === null) {
            return null;
        }

        $package = Str::before($name, '~');

        if (! $response->successful()) {
            $this->fail('metadata', $response, $response->status() === 404
                ? "{$package} is not in this repository's metadata. Check it has synced at least one version, and that it is served from this repository."
                : $this->hintFor('metadata', $response));

            return null;
        }

        $versions = $this->expand($response->json("packages.{$package}") ?? []);

        $this->ok($response, count($versions).' '.Str::plural('version', count($versions)));

        return $versions;
    }

    /**
     * Step 4: the archive, checked the way Composer checks it.
     *
     * @param  array<string, mixed>  $release
     */
    private function dist(array $release, string $username, string $password): bool
    {
        $this->line('Downloading the package', 'request');
        $dist = is_array($release['dist'] ?? null) ? $release['dist'] : [];
        $url = (string) ($dist['url'] ?? '');

        if ($url === '') {
            $this->failedStep = 'dist';
            $this->line("{$release['version']} has no dist archive in the metadata.", 'fail');
            $this->line('Hint: sync the package so its archive is built.', 'hint');

            return false;
        }

        $this->line("Downloading {$url}", 'request');

        $response = $this->get('dist', $url, $username, $password, HttpTimeouts::ARCHIVE);

        if ($response === null) {
            return false;
        }

        if (! $response->successful()) {
            return $this->fail('dist', $response, $response->status() === 404
                ? 'No archive is stored for this version. Sync the package to build it.'
                : $this->hintFor('dist', $response));
        }

        $this->line('Verifying the download', 'request');
        $body = $response->body();
        $checks = [];

        if (filled($shasum = $dist['shasum'] ?? null)) {
            if (! hash_equals((string) $shasum, sha1($body))) {
                return $this->fail('dist', $response, 'The archive does not match the shasum in the metadata, so Composer would refuse it. Rebuild the package; if it keeps happening, something between here and the client is changing the download.', detail: 'shasum mismatch: expected '.$shasum.', got '.sha1($body));
            }

            $checks[] = 'shasum matches';
        }

        $files = $this->zipEntries($body);

        if ($files === null) {
            return $this->fail('dist', $response, 'The download is not a zip archive. Something between here and the client replied instead — often a proxy or CDN error page.', detail: 'Response: '.$this->excerpt($body));
        }

        $checks[] = "zip opens ({$files} files)";

        $this->ok($response, implode(' · ', $checks));

        return true;
    }

    private function get(string $step, string $url, ?string $username = null, ?string $password = null, int $timeout = HttpTimeouts::API): ?Response
    {
        $remaining = (int) ceil($this->deadline - microtime(true));

        if ($remaining <= 0) {
            $this->failedStep = $step;
            $this->line('Gave up: the test took longer than '.self::TIMEOUT.' seconds.', 'fail');

            return null;
        }

        // Composer scopes http-basic credentials to the one host they were
        // configured for, and so does the test: an absolute metadata or dist
        // URL pointing anywhere else means the answer did not come from this
        // registry, and its token is not sent there.
        if ($username !== null && ! $this->belongsToCredentialHost($url)) {
            $this->failedStep = $step;
            $this->line('  '.(string) parse_url($url, PHP_URL_HOST).' is not this registry — refusing to send the credentials there ✗', 'fail');
            $this->line('    The answer pointed at another host, so it did not come from this registry.', 'detail');
            $this->line('Hint: check APP_URL, and that nothing in front of the registry (a proxy, a CDN) is answering with its own content.', 'hint');

            return null;
        }

        $request = $this->request(min($timeout, $remaining));

        if ($username !== null && $password !== null) {
            $request = $request->withBasicAuth($username, $password);
        }

        $started = microtime(true);

        try {
            $response = $request->get($url);
        } catch (ConnectionException $exception) {
            $this->failedStep = $step;
            $this->line('Could not connect: '.$exception->getMessage(), 'fail');
            $this->line('Hint: the address does not reach this registry. Check APP_URL, the domain\'s DNS and its TLS certificate.', 'hint');

            return null;
        }

        $this->elapsedMs = (int) round((microtime(true) - $started) * 1000);

        return $response;
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(min(HttpTimeouts::CONNECT, $timeout))
            ->withUserAgent('Composer/2 (Package Pipeline installation test)');
    }

    private function ok(Response $response, ?string $note = null): void
    {
        $this->line('  '.$this->summary($response).($note ? " · {$note}" : '').' ✓', 'ok');
    }

    private function fail(string $step, Response $response, string $hint, ?string $detail = null): bool
    {
        $this->failedStep = $step;

        $this->line('  '.$this->summary($response).' ✗', 'fail');
        $this->line('    '.($detail ?? 'Response: '.$this->excerpt($response->body())), 'detail');

        if ($code = $this->cloudflareError($response)) {
            $this->line("    Cloudflare error {$code}".(($ray = $response->header('cf-ray')) ? " · ray {$ray}" : ''), 'detail');
        }

        $this->line("Hint: {$hint}", 'hint');

        return false;
    }

    /**
     * "200 OK · 21 ms · 412 B", the shape Composer's verbose output gives a
     * request.
     */
    private function summary(Response $response): string
    {
        return sprintf(
            '%d %s · %d ms · %s',
            $response->status(),
            $response->reason(),
            $this->elapsedMs,
            Number::fileSize(strlen($response->body())),
        );
    }

    private function hintFor(string $step, Response $response): string
    {
        if ($code = $this->cloudflareError($response)) {
            return "Cloudflare blocked the request (error {$code}). Check the zone's firewall and WAF rules let Composer's requests through to this host.";
        }

        return match (true) {
            $response->status() === 401 && $step !== 'reach' => 'The test token was refused. Check nothing in front of the registry strips the Authorization header.',
            $response->status() === 403 => 'The request was refused. If it is not the registry\'s own message above, a proxy or firewall in front of it is blocking Composer.',
            $response->status() >= 500 => 'The registry or something in front of it failed. Check the application log and the proxy.',
            default => 'The address does not answer as this registry. Check APP_URL and the domain\'s DNS point here.',
        };
    }

    private function cloudflareError(Response $response): ?string
    {
        $body = $response->body();
        $fromCloudflare = $response->header('cf-ray') !== '' || Str::contains($response->header('server'), 'cloudflare', true);

        if (preg_match('/(?:error code:?|error)\s*(1\d{3})\b/i', $body, $matches) && ($fromCloudflare || Str::contains($body, 'cloudflare', true))) {
            return $matches[1];
        }

        return null;
    }

    private function excerpt(string $body): string
    {
        // An HTML error page is mostly stylesheet; what it says is the text.
        $body = (string) preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', ' ', $body);
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        return $text === '' ? '(empty)' : Str::limit($text, self::EXCERPT);
    }

    /**
     * Number of entries in a zip held in memory, or null when it is not one.
     */
    private function zipEntries(string $body): ?int
    {
        $path = tempnam(sys_get_temp_dir(), 'pp-installation-test');

        try {
            file_put_contents($path, $body);

            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                return null;
            }

            $count = $zip->numFiles;
            $zip->close();

            return $count;
        } finally {
            @unlink($path);
        }
    }

    /**
     * Composer's minified metadata lists each version as a diff against the
     * one before it; this restores the full entries.
     *
     * @param  array<int, array<string, mixed>>  $versions
     * @return list<array<string, mixed>>
     */
    private function expand(array $versions): array
    {
        $expanded = [];
        $previous = [];

        foreach ($versions as $version) {
            $current = [...$previous, ...$version];

            foreach ($current as $key => $value) {
                if ($value === '__unset') {
                    unset($current[$key]);
                }
            }

            $expanded[] = $previous = $current;
        }

        return $expanded;
    }

    /**
     * The requested version, or the newest stable one — what a bare
     * `composer require` would pick — falling back to the newest of any kind.
     *
     * @param  list<array<string, mixed>>  $versions
     * @return array<string, mixed>|null
     */
    private function choose(array $versions, ?string $requested): ?array
    {
        if ($requested !== null) {
            foreach ($versions as $version) {
                if (in_array($requested, [$version['version'] ?? null, $version['version_normalized'] ?? null], true)) {
                    return $version;
                }
            }

            return null;
        }

        // Branches carry no order to sort by ("dev-main" against what?), so
        // the pick comes from the tagged releases; a branch is the last
        // resort, unchanged from where the metadata listed it.
        $tagged = array_values(array_filter($versions, fn (array $version): bool => ! self::isBranch($version)));

        usort($tagged, fn (array $a, array $b): int => version_compare(
            (string) ($b['version_normalized'] ?? $b['version']),
            (string) ($a['version_normalized'] ?? $a['version']),
        ));

        $stable = array_values(array_filter($tagged, function (array $version): bool {
            try {
                return VersionParser::parseStability((string) ($version['version'] ?? '')) === 'stable';
            } catch (UnexpectedValueException) {
                return false;
            }
        }));

        // Newest first: a stable one, else the newest pre-release — the same
        // pick the install card makes, and what the transcript then says a
        // project must require.
        return $stable[0] ?? $tagged[0] ?? $versions[0] ?? null;
    }

    /**
     * Whether a metadata entry is a branch rather than a tagged release, as
     * Composer writes branches into the ~dev file: `dev-main`, or
     * `1.x-dev`.
     *
     * @param  array<string, mixed>  $version
     */
    private static function isBranch(array $version): bool
    {
        $name = (string) ($version['version'] ?? '');

        return str_starts_with($name, 'dev-') || str_ends_with($name, '-dev');
    }

    /**
     * What a project requires a pre-release with: its minor line and its
     * stability, as in `^0.1@alpha` for 0.1.0-alpha11. Null for a stable
     * release or a branch, which need nothing of the kind.
     */
    public static function prereleaseConstraint(string $version): ?string
    {
        try {
            $stability = VersionParser::parseStability($version);
        } catch (UnexpectedValueException) {
            return null;
        }

        if (in_array($stability, ['stable', 'dev'], true) || preg_match('/^v?(\d+)\.(\d+)/', $version, $parts) !== 1) {
            return null;
        }

        return "^{$parts[1]}.{$parts[2]}@{$stability}";
    }

    private function resolve(string $root, string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $parts = parse_url($root);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/'.ltrim($path, '/');
    }

    /**
     * Whether a URL the registry's own answer led to is still the registry
     * itself. A relative template resolved against the root always is; only
     * an absolute URL can leave it, and every URL the test requests has been
     * through resolve().
     */
    private function belongsToCredentialHost(string $url): bool
    {
        return strcasecmp((string) parse_url($url, PHP_URL_HOST), $this->credentialHost) === 0;
    }

    private function line(string $text, string $tone): void
    {
        ($this->report)($text, $tone);
    }
}
