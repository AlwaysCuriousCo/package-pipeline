<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A shields-style SVG badge for a package — version, downloads, license or
 * PHP constraint — for pasting into a README.
 *
 * Public only where the page is: a package that publishes no page publishes
 * no badge, so this leaks nothing the page does not already say. Rendered
 * inline rather than by proxying shields.io, because a private registry's
 * package names are not something to hand a third party.
 */
class PackageBadgeController extends Controller
{
    public const KINDS = ['version', 'downloads', 'license', 'php'];

    public function __invoke(Request $request, string $vendor, string $package, string $kind): Response
    {
        /** @var Repository $repository */
        $repository = $request->attributes->get('composerRepository');

        $found = $repository->packages()
            ->withPage()
            ->where('name', mb_strtolower("{$vendor}/{$package}"))
            ->first();

        abort_unless($found instanceof Package && in_array($kind, self::KINDS, true), 404);

        [$label, $value, $color] = $this->content($found, $kind);

        return response($this->svg($label, $value, $color), 200, [
            'Content-Type' => 'image/svg+xml',
            'X-Content-Type-Options' => 'nosniff',
            // Short: a release or a download changes the answer, and GitHub's
            // camo proxy honours this when deciding how long to keep a copy.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * @return array{string, string, string}
     */
    private function content(Package $package, string $kind): array
    {
        $latest = $package->latest_version === null
            ? null
            : $package->versions()->where('version', $package->latest_version)->first();

        return match ($kind) {
            'version' => ['version', $package->latest_version ?? 'none', $package->latest_version ? '#4c1' : '#9f9f9f'],
            'downloads' => ['downloads', $this->compact((int) $package->total_downloads), '#007ec6'],
            'license' => ['license', implode(', ', $latest?->licenses() ?? []) ?: 'unknown', '#97ca00'],
            default => ['php', (string) ($latest?->metadata['require']['php'] ?? '') ?: 'unknown', '#777bb4'],
        };
    }

    private function compact(int $count): string
    {
        return match (true) {
            $count >= 1_000_000 => round($count / 1_000_000, 1).'M',
            $count >= 1_000 => round($count / 1_000, 1).'k',
            default => (string) $count,
        };
    }

    private function svg(string $label, string $value, string $color): string
    {
        // ponytail: width is a per-character estimate rather than measured
        // text; swap in a font-metrics table if long values look clipped.
        $width = fn (string $text): int => (int) ceil(mb_strlen($text) * 6.5) + 10;

        $l = $width($label);
        $v = $width($value);
        $total = $l + $v;

        $label = e($label);
        $value = e($value);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$total}" height="20" role="img" aria-label="{$label}: {$value}">
        <title>{$label}: {$value}</title>
        <linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>
        <clipPath id="r"><rect width="{$total}" height="20" rx="3" fill="#fff"/></clipPath>
        <g clip-path="url(#r)"><rect width="{$l}" height="20" fill="#555"/><rect x="{$l}" width="{$v}" height="20" fill="{$color}"/><rect width="{$total}" height="20" fill="url(#s)"/></g>
        <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">
        <text x="{$this->mid(0, $l)}" y="15" fill="#010101" fill-opacity=".3">{$label}</text><text x="{$this->mid(0, $l)}" y="14">{$label}</text>
        <text x="{$this->mid($l, $v)}" y="15" fill="#010101" fill-opacity=".3">{$value}</text><text x="{$this->mid($l, $v)}" y="14">{$value}</text>
        </g>
        </svg>
        SVG;
    }

    private function mid(int $offset, int $width): float
    {
        return $offset + $width / 2;
    }
}
