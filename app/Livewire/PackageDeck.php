<?php

namespace App\Livewire;

use App\Services\PackagePresentation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use WendellAdriel\SlideWire\Livewire\PresentationDeck;

/**
 * SlideWire's deck, for a package page. Two differences, both about remote
 * control:
 *
 *  - The controller's signed link is checked against the page it opened,
 *    relative to the host. SlideWire checks against a named route of its
 *    own, which a package page is not — and a signature over the absolute
 *    URL would break on a repository's own host, which is the host the link
 *    is handed out on.
 *  - Whoever holds that link also gets the presenter view: the slide's
 *    speaker notes, what comes next and a clock. Rendered here, server side
 *    and only in controller mode, so the notes are never in a viewer's page
 *    or in the component's public state.
 */
class PackageDeck extends PresentationDeck
{
    /**
     * Only ever asked while mounting, which is the page request itself — the
     * signed URL the panel minted, with `remote` among its parameters.
     */
    protected function hasValidControllerSignature(): bool
    {
        return URL::hasValidSignature(request(), absolute: false);
    }

    public function render(): View
    {
        $deck = parent::render();

        return view('livewire.package-deck', [...$deck->getData(), 'presenter' => $this->presenter()]);
    }

    /**
     * @return array{notes: list<string>, next: ?string, position: int, count: int}|null
     */
    private function presenter(): ?array
    {
        if ($this->remoteMode !== 'controller') {
            return null;
        }

        $package = PackagePresentation::packageFor($this->presentation);
        $deck = $package === null ? null : app(PackagePresentation::class)->compile($package);

        if ($deck === null) {
            return null;
        }

        $next = $this->slides[$this->activeIndex + 1] ?? null;

        return [
            'notes' => $deck['notes'][$this->activeIndex] ?? [],
            'next' => $next === null ? null : Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($next->html))), 200),
            'position' => $this->activeIndex + 1,
            'count' => count($this->slides),
        ];
    }
}
