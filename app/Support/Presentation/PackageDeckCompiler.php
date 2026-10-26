<?php

namespace App\Support\Presentation;

use App\Services\PackagePresentation;
use WendellAdriel\SlideWire\Support\PresentationCompiler;

/**
 * SlideWire's compiler, taught one more kind of deck: a package's, which is
 * a database row rather than a file. Bound in place of SlideWire's own, so
 * its deck component — which compiles by name and nothing else — can show
 * one. Any other name is SlideWire's business, exactly as before.
 *
 * An unpublished or unknown package compiles to no slides, which SlideWire
 * answers with a 404 — the same answer the page gives.
 */
class PackageDeckCompiler extends PresentationCompiler
{
    public function compile(string $presentation): array
    {
        if (! PackagePresentation::isKey($presentation)) {
            return parent::compile($presentation);
        }

        $package = PackagePresentation::packageFor($presentation);
        $deck = $package === null ? null : app(PackagePresentation::class)->compile($package);

        if ($deck === null) {
            return ['deck_meta' => [], 'slides' => []];
        }

        return [
            'deck_meta' => $deck['deck_meta'],
            'slides' => array_map(fn ($slide): array => [$slide], $deck['slides']),
        ];
    }
}
