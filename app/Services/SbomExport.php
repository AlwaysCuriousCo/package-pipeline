<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Everything this registry publishes, as a CycloneDX software bill of
 * materials.
 *
 * One implementation behind the panel action, the HTTP route and the
 * `sbom:export` command, for the same reasons DownloadExport is: the only
 * interesting parts are the scoping and the streaming, and neither is worth
 * having two of.
 *
 * **Nothing is materialised.** A component is emitted per package *version*,
 * because that is what a registry serves and what a consumer's lock file pins
 * — so the document is as long as the registry is deep, and it is written out
 * as the cursor produces it rather than assembled and then encoded.
 *
 * ## What was verified about the format, and when
 *
 * Checked against the CycloneDX specification repository in August 2026:
 *
 * - 1.7 is the current release (October 2025, standardised as ECMA-424), but
 *   1.6 is what tooling actually ingests — Dependency-Track and Trivy both
 *   still reject 1.7, and the official cyclonedx-php-composer plugin still
 *   defaults to 1.5. So 1.6 is emitted: the newest version a consumer can be
 *   assumed to read.
 * - `bomFormat` and `specVersion` are the only required top-level keys, and
 *   the top level is `additionalProperties: false` — nothing may be invented.
 * - `$schema` is `http://cyclonedx.org/schema/bom-1.6.schema.json`, over http
 *   rather than https, matching the schema's own `$id`.
 * - `serialNumber` is matched against a lowercase-only `urn:uuid:` pattern.
 * - `metadata.tools` as an array of tools was deprecated in 1.5 and replaced
 *   by an object carrying `components` and `services`; the object form is what
 *   is emitted here.
 * - `hashes[].alg` is a closed enum in which SHA-1 is spelled with the hyphen.
 * - `externalReferences[].type` admits `distribution` and `vcs`.
 *
 * SPDX is deliberately not offered alongside. The PHP ecosystem's own tooling
 * is CycloneDX — there is no comparable first-party SPDX generator for
 * Composer — and a second format maintained for nobody in particular is a
 * second format to keep correct. If a consumer ever needs SPDX it should be
 * generated from the same rows rather than by a parallel exporter.
 *
 * @see docs/licensing.md
 */
class SbomExport
{
    /**
     * @see the class comment for why this is not the newest published version
     */
    public const SPEC_VERSION = '1.6';

    public function __construct(
        /**
         * Narrow to one package, or to none for the whole registry.
         */
        private readonly ?Package $package = null,
        /**
         * Whose visibility to apply, or null for a caller that has none —
         * the console, which has no session to scope to and is already
         * trusted with the database it is reading. Every path a *user* can
         * reach passes one.
         */
        private readonly ?User $user = null,
    ) {}

    /**
     * The filename a browser or an operator ends up with.
     *
     * `.cdx.json` rather than `.json`, which is the convention CycloneDX's own
     * tooling recognises a bill of materials by.
     */
    public function filename(): string
    {
        // Folded to the characters a filename may safely carry rather than
        // just having its slash swapped: a package name is adopted verbatim
        // from a repository's composer.json, and one carrying a backslash or a
        // quote would have the response's Content-Disposition refuse to build.
        $scope = $this->package === null
            ? 'registry'
            : preg_replace('#[^A-Za-z0-9._-]+#', '-', (string) $this->package->name);

        return 'sbom-'.(trim((string) $scope, '-.') ?: 'package').'.cdx.json';
    }

    /**
     * The document, in the order it is written.
     *
     * A generator rather than a string, so the caller decides how much is ever
     * in memory and nothing is queried until somebody starts reading. The
     * envelope is encoded whole — it is a few hundred bytes — and the
     * components arrive one per line, which keeps a large export greppable and
     * diffable without a pretty-printer having to hold it all.
     *
     * The generator's return value is how many components it wrote, which
     * writeTo() reports and which only the loop below can know — counting the
     * chunks instead would tie the answer to how the punctuation happens to be
     * split up.
     *
     * @return Generator<int, string, mixed, int>
     */
    public function chunks(): Generator
    {
        $envelope = json_encode($this->envelope(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // The envelope is emitted as an object missing its closing brace, so
        // the components can be streamed into it and the array and object
        // closed at the end. Encoding it whole first is what keeps the
        // hand-written JSON here down to three punctuation marks. Cut at the
        // *last* brace rather than by trimming them off the end, which would
        // have taken the nested `metadata` object's closing brace with it.
        yield rtrim(substr($envelope, 0, (int) strrpos($envelope, '}')))
            .",\n    \"components\": [\n";

        $separator = '';
        $components = 0;

        foreach ($this->versions() as $version) {
            yield $separator.json_encode(
                $this->component($version),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            $separator = ",\n";
            $components++;
        }

        yield "\n]\n}\n";

        return $components;
    }

    /**
     * Write the whole document to an open handle, flushing as it goes.
     *
     * The handle belongs to the caller: one caller's is `php://output`
     * mid-response and another's is a file that has to be closed even if this
     * throws.
     *
     * @param  resource  $handle
     * @return int how many components were written
     */
    public function writeTo($handle): int
    {
        $chunks = $this->chunks();

        foreach ($chunks as $chunk) {
            fwrite($handle, $chunk);
        }

        return $chunks->getReturn();
    }

    /**
     * How many components the document will carry — what the panel shows
     * before offering the download, and what the command reports after.
     */
    public function count(): int
    {
        return $this->scoped()->count();
    }

    /**
     * Everything above `components`.
     *
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        return [
            '$schema' => 'http://cyclonedx.org/schema/bom-'.self::SPEC_VERSION.'.schema.json',
            'bomFormat' => 'CycloneDX',
            'specVersion' => self::SPEC_VERSION,
            // Lowercased because the schema's pattern for this admits no other
            // case, and PHP's uuid formatting is not guaranteed to be.
            'serialNumber' => 'urn:uuid:'.mb_strtolower((string) Str::uuid()),
            'version' => 1,
            'metadata' => [
                'timestamp' => now()->toIso8601String(),
                'tools' => [
                    'components' => [[
                        'type' => 'application',
                        'group' => 'always-curious',
                        'name' => 'package-pipeline',
                    ]],
                ],
                'component' => $this->subject(),
            ],
        ];
    }

    /**
     * What the bill of materials is *of*: the package, when one was asked for,
     * and otherwise the registry itself.
     *
     * @return array<string, mixed>
     */
    private function subject(): array
    {
        if (! $this->package instanceof Package) {
            return [
                'type' => 'application',
                'name' => (string) config('app.name'),
                'description' => 'A private Composer registry.',
            ];
        }

        [$group, $name] = $this->splitName((string) $this->package->name);

        return array_filter([
            'type' => 'library',
            'group' => $group,
            'name' => $name,
            'description' => $this->package->description,
        ], fn (mixed $value): bool => filled($value));
    }

    /**
     * One version, as a CycloneDX component.
     *
     * The archive's sha1 hangs off the distribution reference rather than off
     * the component's own `hashes`, which is the distinction the official
     * cyclonedx-php-composer plugin draws and the correct one: it is the hash
     * of the zip this registry serves, not of the package as a thing.
     *
     * @return array<string, mixed>
     */
    private function component(PackageVersion $version): array
    {
        $package = $version->package;

        [$group, $name] = $this->splitName((string) $package->name);

        $purl = $this->purl($group, $name, (string) $version->version, $package);

        return array_filter([
            'type' => filled($package->type) ? $this->componentType((string) $package->type) : 'library',
            // The purl is unique per version and already in hand, which is
            // exactly what a bom-ref has to be.
            'bom-ref' => $purl,
            'group' => $group,
            'name' => $name,
            'version' => (string) $version->version,
            'description' => $package->description,
            'purl' => $purl,
            'licenses' => $this->licenses($version),
            'externalReferences' => $this->externalReferences($version, $package),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * A Composer package type as one of CycloneDX's, which is a closed enum
     * where Composer's `type` is free text.
     *
     * Only the two that genuinely differ are mapped; everything else a
     * registry serves — a library, a plugin, a theme, a metapackage — is a
     * library as far as a bill of materials is concerned.
     */
    private function componentType(string $type): string
    {
        return match ($type) {
            'project' => 'application',
            'composer-plugin' => 'application',
            default => 'library',
        };
    }

    /**
     * The Package URL for a version, carrying the registry it actually comes
     * from.
     *
     * The `repository_url` qualifier is the whole point of emitting a purl for
     * a private package: without it the purl reads as `pkg:composer/acme/…`
     * and every scanner that sees it will resolve that against packagist.org,
     * where it is either absent or — far worse — somebody else's. The official
     * plugin does not emit the qualifier yet; the purl specification's own
     * Composer type definition provides for it.
     */
    private function purl(string $group, string $name, string $version, Package $package): string
    {
        $purl = sprintf(
            'pkg:composer/%s/%s@%s',
            rawurlencode(mb_strtolower($group)),
            rawurlencode(mb_strtolower($name)),
            rawurlencode($version),
        );

        $repository = rtrim($package->composerRepository->url(), '/');

        return $purl.'?repository_url='.rawurlencode($repository);
    }

    /**
     * A version's licensing, or an empty array when it declares none.
     *
     * Always the `expression` form, and always as the array's only element.
     *
     * The alternative — `{"license": {"id": "MIT"}}` — is the more informative
     * shape and is not available to us: `id` is a `$ref` to CycloneDX's SPDX
     * enumeration, a closed list this registry does not hold, so one mistyped
     * licence in one repository's composer.json would make the whole document
     * fail validation. `expression` is an unvalidated string in 1.6, and
     * Composer already requires the field to be an SPDX identifier or the
     * literal `proprietary`, so the string is an expression by contract. The
     * one shape that cannot produce an invalid document out of data this
     * registry does not control wins.
     *
     * `proprietary` is the exception, because it is the one value Composer
     * documents that SPDX does not define. It goes out as a named licence,
     * which is precisely what `name` is for.
     *
     * Sole element because 1.6 caps the expression form of the array at one
     * item. 1.7 lifted that, but a document that obeys the stricter rule is
     * valid under both — and this one has no reason to emit more.
     *
     * @return list<array<string, mixed>>
     */
    private function licenses(PackageVersion $version): array
    {
        $expression = $version->license;

        if (blank($expression)) {
            return [];
        }

        if (mb_strtolower($expression) === PackageVersion::PROPRIETARY) {
            return [['license' => ['name' => $expression]]];
        }

        return [['expression' => $expression]];
    }

    /**
     * Where the version can be fetched from and where it came from.
     *
     * @return list<array<string, mixed>>
     */
    private function externalReferences(PackageVersion $version, Package $package): array
    {
        $references = [];

        if (filled($version->archive_path) && filled($version->reference)) {
            $references[] = array_filter([
                'type' => 'distribution',
                'url' => $package->composerRepository->url("/dist/{$package->name}/{$version->reference}.zip"),
                // A version synced before archives were stored has no shasum,
                // and `content` is length-validated — so an absent one is
                // omitted rather than sent as an empty string that would fail
                // the document.
                'hashes' => filled($version->shasum)
                    ? [['alg' => 'SHA-1', 'content' => $version->shasum]]
                    : [],
            ], fn (mixed $value): bool => $value !== []);
        }

        if (filled($package->repository)) {
            $references[] = ['type' => 'vcs', 'url' => $package->repository];
        }

        return $references;
    }

    /**
     * A Composer name as CycloneDX's group and name.
     *
     * @return array{string, string}
     */
    private function splitName(string $name): array
    {
        $parts = explode('/', $name, 2);

        return count($parts) === 2 ? [$parts[0], $parts[1]] : ['', $name];
    }

    /**
     * The versions the document describes, newest package first and oldest
     * version first inside each — the order a reader scanning for a package
     * and then following its history would want.
     *
     * `lazy()` rather than `cursor()`, because the components need each
     * version's package and the repository serving it: a cursor cannot eager
     * load, and one query per version to build a name would be the whole cost
     * of the export.
     *
     * @return iterable<int, PackageVersion>
     */
    private function versions(): iterable
    {
        return $this->scoped()
            ->with('package.composerRepository')
            ->join('packages', 'packages.id', '=', 'package_versions.package_id')
            ->orderBy('packages.name')
            ->orderBy('package_versions.order')
            ->orderBy('package_versions.version')
            // Qualified because of the join: an unqualified `id` is ambiguous
            // once `packages` is in the query, and every driver says so
            // differently.
            ->select([
                'package_versions.id', 'package_versions.package_id', 'package_versions.version',
                'package_versions.order', 'package_versions.reference', 'package_versions.license',
                'package_versions.shasum', 'package_versions.archive_path',
            ])
            ->lazy(500);
    }

    /**
     * The versions in scope, unordered and unjoined — count() asks this too,
     * and an aggregate carrying an ORDER BY over a joined column is a query
     * PostgreSQL refuses outright.
     *
     * @return Builder<PackageVersion>
     */
    private function scoped(): Builder
    {
        $packages = Package::query();

        if ($this->package instanceof Package) {
            $packages->whereKey($this->package->getKey());
        }

        if ($this->user !== null) {
            $packages->visibleToUser($this->user);
        }

        return PackageVersion::query()->ofPackages($packages);
    }
}
