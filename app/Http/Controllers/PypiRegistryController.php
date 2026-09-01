<?php

namespace App\Http\Controllers;

use App\Enums\Ecosystem;
use App\Events\PackageDownloaded;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\ReservedVendor;
use App\Models\Token;
use App\Services\ArchiveStore;
use App\Services\CreatePypiFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the app as a Python package index, so a project can point pip at it:
 *   pip config set global.index-url <this-app's-url>/pypi/simple/
 * with the token as HTTP basic credentials (`__token__` as the username, or
 * any other — AuthenticateComposer reads the password), and twine at
 * <this-app's-url>/pypi/legacy/ to publish.
 *
 * The PEP 503 HTML index and nothing fancier: pip negotiates the JSON form
 * (PEP 691) but speaks this one fluently, and one rendering is less to get
 * wrong. The same repository mounts, tokens, visibility scoping, archive
 * storage and download accounting as the Composer and npm surfaces.
 *
 * A Python release is several files — an sdist and a wheel, sometimes many
 * wheels — so a version row's metadata carries a `files` list, and the index
 * page serves every entry. @see CreatePypiFile
 */
class PypiRegistryController extends Controller
{
    /**
     * A project name as PEP 508 admits it, checked before normalization.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$/';

    /**
     * A distribution filename: what setuptools and wheel produce, and the one
     * string here that is interpolated into both HTML and storage paths.
     */
    private const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+!-]*$/';

    public function __construct(
        private readonly ArchiveStore $archives,
    ) {}

    /**
     * The one spelling a project name is stored and looked up under — PEP
     * 503's: lowercase, with runs of dots, hyphens and underscores folded to
     * one hyphen. pip normalizes before asking; this is the same fold for
     * hand-typed URLs and for whatever twine was handed.
     */
    public static function normalize(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[-_.]+/', '-', trim($name)));
    }

    /**
     * The index root: every served project, one anchor each. pip only reads
     * this for `pip search`-adjacent tooling, but PEP 503 requires it and it
     * is the cheapest page here.
     */
    public function index(Request $request): Response
    {
        $repository = $this->repository($request);

        $anchors = $repository->packages()
            ->ofEcosystem(Ecosystem::Pypi)
            ->visibleTo($this->token($request), $repository)
            ->has('versions')
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (string $name): string => '<a href="'.e($repository->url("/pypi/simple/{$name}/")).'">'.e($name).'</a>');

        return $this->page('Simple index', $anchors->all());
    }

    /**
     * One project's page: every file of every version, each href carrying the
     * sha256 pip verifies the download against, oldest release first as the
     * spec's examples have it.
     */
    public function project(Request $request, string $name): Response
    {
        $repository = $this->repository($request);
        $record = $this->servedPackage($request, $repository, $name);

        $anchors = [];

        foreach ($record->versions()->orderedByVersion('asc')->get() as $version) {
            foreach ($this->files($version) as $file) {
                $href = $repository->url("/pypi/files/{$record->name}/{$version->version}/{$file['filename']}")
                    .'#sha256='.$file['sha256'];

                $requires = isset($file['requires_python']) && is_string($file['requires_python'])
                    ? ' data-requires-python="'.e($file['requires_python']).'"'
                    : '';

                $anchors[] = '<a href="'.e($href).'"'.$requires.'>'.e($file['filename']).'</a>';
            }
        }

        abort_if($anchors === [], 404, "Project {$record->name} has no published files.");

        return $this->page("Links for {$record->name}", $anchors);
    }

    /**
     * Serve one stored distribution file, as the Composer dist and npm
     * tarball endpoints serve theirs — with one difference: the filename is
     * served back verbatim, because pip reads a wheel's platform tags out of
     * its name and a renamed wheel is an uninstallable one.
     */
    public function file(Request $request, string $name, string $versionString, string $filename): StreamedResponse|RedirectResponse
    {
        $repository = $this->repository($request);
        $record = $this->servedPackage($request, $repository, $name);

        $version = $record->versions()->where('version', $versionString)->first();

        $entry = $version instanceof PackageVersion
            ? collect($this->files($version))->first(fn (array $file): bool => $file['filename'] === $filename)
            : null;

        abort_unless(
            is_array($entry) && is_string($entry['path'] ?? null) && $this->archives->disk()->exists($entry['path']),
            404,
            "No file named {$filename} is stored for {$record->name} {$versionString}.",
        );

        if ($request->isMethod('GET')) {
            PackageDownloaded::dispatch(
                $record->id,
                $version->id,
                $version->version,
                $this->token($request)?->token_prefix,
            );
        }

        $url = $this->archives->temporaryUrl($entry['path'], $filename);

        if ($url !== null) {
            return redirect()->away($url, headers: ['Cache-Control' => 'no-store']);
        }

        return $this->archives->disk()->download($entry['path'], $filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * `twine upload` — one file per POST, multipart, with the metadata beside
     * it as form fields.
     *
     * Requires a token with the write ability; the middleware never lets an
     * unauthenticated request through to here. The same scope rules as the
     * other two publish endpoints.
     */
    public function upload(Request $request, CreatePypiFile $creator): JsonResponse
    {
        $maxKilobytes = (int) config('registry.upload_max_megabytes') * 1024;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:'.self::NAME_PATTERN],
            'version' => ['required', 'string', 'max:255'],
            'content' => ['required', 'file', "max:{$maxKilobytes}"],
            'summary' => ['nullable', 'string', 'max:512'],
            'requires_python' => ['nullable', 'string', 'max:255'],
            'sha256_digest' => ['nullable', 'string', 'size:64'],
        ]);

        $name = self::normalize($validated['name']);
        $file = $validated['content'];
        $filename = $file->getClientOriginalName();

        if (preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            throw ValidationException::withMessages([
                'content' => "\"{$filename}\" is not a distribution filename this index will serve.",
            ]);
        }

        // twine states what it hashed before sending; a mismatch is a
        // corrupted upload, and storing it would publish bytes nobody built.
        if (filled($validated['sha256_digest'] ?? null)
            && ! hash_equals(mb_strtolower($validated['sha256_digest']), (string) hash_file('sha256', $file->getRealPath()))) {
            throw ValidationException::withMessages([
                'content' => 'The uploaded bytes do not match the sha256_digest the client declared.',
            ]);
        }

        $repository = $this->repository($request);

        $existing = $repository->packages()->where('packages.name', $name)->first();

        // ponytail: names are unique per repository across ecosystems, so one
        // registry cannot serve an npm "widgets" and a PyPI "widgets" from the
        // same mount; scope the unique indexes by ecosystem if that bites.
        abort_if(
            $existing instanceof Package && $existing->ecosystem !== Ecosystem::Pypi,
            409,
            "\"{$name}\" is already served by this repository as a {$existing?->ecosystem->value} package; one repository answers for one package per name.",
        );

        abort_unless(
            $this->mayPublishTo($request, $repository, $existing),
            403,
            'This token may not publish into this repository.',
        );

        if (! $existing instanceof Package) {
            $conflict = ReservedVendor::conflictFor($name, (int) $repository->id);

            abort_if($conflict instanceof ReservedVendor, 403, $conflict?->refusal($name) ?? '');
        }

        $version = $creator->create(
            $repository,
            $name,
            $validated['version'],
            $file->getRealPath(),
            $filename,
            $validated['summary'] ?? null,
            $validated['requires_python'] ?? null,
        );

        return response()->json([
            'name' => $name,
            'version' => $version->version,
            'filename' => $filename,
        ], 201);
    }

    /**
     * The version's file entries, defensively shaped: metadata is a JSON
     * column and the index page must not fatal over one malformed row.
     *
     * @return list<array<string, mixed>>
     */
    private function files(PackageVersion $version): array
    {
        return array_values(array_filter(
            (array) ($version->metadata['files'] ?? []),
            fn (mixed $entry): bool => is_array($entry)
                && is_string($entry['filename'] ?? null)
                && is_string($entry['sha256'] ?? null),
        ));
    }

    /**
     * A PEP 503 page: a title and a list of anchors, nothing else. Hand-built
     * because the format is six lines of HTML and a Blade view would be
     * longer than the format.
     *
     * @param  list<string>  $anchors  already-escaped anchor elements
     */
    private function page(string $title, array $anchors): Response
    {
        $body = '<!DOCTYPE html><html><head>'
            .'<meta name="pypi:repository-version" content="1.0">'
            .'<title>'.e($title).'</title></head><body>'
            .implode('', array_map(fn (string $anchor): string => $anchor.'<br>', $anchors))
            .'</body></html>';

        return response($body, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Private for the reason every Composer response is: what this
            // URL answers depends on who asked.
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'Authorization',
        ]);
    }

    /**
     * Whether the authenticated principal's scope reaches this publish — the
     * same two rules the Composer and npm publishes apply.
     *
     * @see Token::mayWriteTo() where both live
     */
    private function mayPublishTo(Request $request, Repository $repository, ?Package $existing): bool
    {
        $token = $this->token($request);

        if (! $token instanceof Token) {
            return false;
        }

        return $existing instanceof Package
            ? $token->mayWriteToPackage($existing)
            : $token->mayWriteTo($repository);
    }

    /**
     * The Python package this repository serves under the name, refused with
     * the same 404 whether absent or merely invisible to this principal.
     */
    private function servedPackage(Request $request, Repository $repository, string $name): Package
    {
        $record = $repository->packages()
            ->ofEcosystem(Ecosystem::Pypi)
            ->visibleTo($this->token($request), $repository)
            ->where('name', self::normalize($name))
            ->first();

        abort_unless($record instanceof Package, 404, "Project {$name} is not served by this index.");

        return $record;
    }

    /**
     * The repository this request is addressed to, resolved by the middleware.
     */
    private function repository(Request $request): Repository
    {
        return $request->attributes->get('composerRepository');
    }

    /**
     * The access token the request authenticated with, if any.
     */
    private function token(Request $request): ?Token
    {
        return $request->attributes->get('composerToken');
    }
}
