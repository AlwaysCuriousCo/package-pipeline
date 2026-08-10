<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\User;
use App\Services\SbomExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CycloneDX bill of materials to the signed-in admin.
 *
 * A plain HTTP route rather than something a panel action returns, for exactly
 * the reason the download export is one: Livewire delivers a file by
 * base64-encoding it into the response it sends the browser, which would mean
 * holding a document with a component per package version in memory. The
 * action opens this URL instead and the bytes go out through PHP's output
 * buffer as the cursor produces them.
 *
 * Outside the panel's own `/admin` paths so it cannot race Filament's route
 * registration, as the download export and the password-setup route are.
 *
 * @see SbomExport for the format, the scoping and the streaming
 * @see docs/licensing.md
 */
class SbomExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'package' => ['nullable', 'integer'],
        ]);

        $user = $request->user();

        // The same permission the package list is behind. A bill of materials
        // is a list of package names and versions, so anything that could not
        // open that page has no business pulling it as a file.
        abort_unless($user instanceof User && $user->can('viewAny', Package::class), 403);

        $export = new SbomExport(
            package: $this->package($user, $validated['package'] ?? null),
            user: $user,
        );

        // Chunked, because the length is not knowable without running the
        // whole export twice.
        return response()->streamDownload(
            function () use ($export): void {
                $handle = fopen('php://output', 'w');

                if ($handle !== false) {
                    $export->writeTo($handle);
                }
            },
            $export->filename(),
            [
                // CycloneDX's own media type, which its tooling content-sniffs
                // for; `application/json` is correct but tells a consumer
                // nothing about what the document is.
                'Content-Type' => 'application/vnd.cyclonedx+json; version='.SbomExport::SPEC_VERSION,
                // It is an inventory cut to one admin's grants. Nothing may
                // keep a copy of it.
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * The package an export was narrowed to, resolved through the caller's own
     * visibility so an invisible package and a missing one read alike.
     *
     * A 404 rather than a silent registry-wide export: an operator who asked
     * for one package's bill of materials and quietly received the whole
     * registry's would have no way to tell.
     */
    private function package(User $user, ?int $id): ?Package
    {
        if ($id === null) {
            return null;
        }

        $package = Package::query()->visibleToUser($user)->whereKey($id)->first();

        abort_unless($package instanceof Package, 404, 'No such package.');

        return $package;
    }
}
