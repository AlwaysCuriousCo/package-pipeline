<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\User;
use App\Services\DownloadExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a download-statistics CSV to the signed-in admin.
 *
 * A plain HTTP route rather than something the panel action returns directly,
 * and that is the whole reason it exists. Livewire delivers a file by
 * base64-encoding it into the response it sends the browser — which means
 * holding the entire export in memory, on the one table in this schema that
 * grows without bound. The action opens this URL instead, and the bytes go
 * straight out through PHP's output buffer as the cursor produces them.
 *
 * Outside the panel's own `/admin` paths so it cannot race Filament's route
 * registration, exactly as the password-setup route is.
 *
 * @see DownloadExport for the scoping and the streaming
 * @see docs/download-analytics.md
 */
class DownloadExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'report' => ['nullable', 'string', 'in:'.DownloadExport::SUMMARY.','.DownloadExport::DETAIL],
            'package' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $user = $request->user();

        // The same permission the package list itself is behind. An export is a
        // read of exactly what that page shows, so anything that could not open
        // the page has no business pulling its history as a file.
        abort_unless($user instanceof User && $user->can('viewAny', Package::class), 403);

        $export = new DownloadExport(
            report: $validated['report'] ?? DownloadExport::SUMMARY,
            from: $this->date($validated['from'] ?? null),
            to: $this->date($validated['to'] ?? null),
            package: $this->package($user, $validated['package'] ?? null),
            user: $user,
        );

        // Chunked, because the length is not knowable without running the
        // query twice — and the second run would be the expensive one.
        return response()->streamDownload(
            function () use ($export): void {
                $handle = fopen('php://output', 'w');

                if ($handle !== false) {
                    $export->writeTo($handle);
                }
            },
            $export->filename(),
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // It is a report about who downloaded what, cut to one admin's
                // grants. Nothing may keep a copy of it.
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * The package an export was narrowed to, resolved through the caller's own
     * visibility so an invisible package and a missing one read alike.
     *
     * A 404 rather than a silent registry-wide export: an operator who asked
     * for one package's history and quietly received everyone's would have no
     * way to tell.
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

    private function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
