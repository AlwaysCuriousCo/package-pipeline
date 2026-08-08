<?php

namespace App\Sources;

/**
 * What a connected source can answer about itself, independent of any one
 * package — the browsing side of a provider, powering project search and
 * one-click onboarding.
 */
interface SourceClient
{
    /**
     * The projects the source's credential can reach, optionally narrowed by
     * a search term.
     *
     * @return list<Project>
     */
    public function projects(?string $search = null): array;
}
