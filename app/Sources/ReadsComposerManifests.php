<?php

namespace App\Sources;

/**
 * A source that can say which of its projects look like Composer packages,
 * so onboarding can offer those first.
 */
interface ReadsComposerManifests
{
    /**
     * The root composer.json of each of the source's projects that has one,
     * keyed by the project's web URL. Projects without one are absent.
     *
     * @return array<string, array{name: ?string, type: ?string, fork: bool}>
     */
    public function composerManifests(): array;
}
