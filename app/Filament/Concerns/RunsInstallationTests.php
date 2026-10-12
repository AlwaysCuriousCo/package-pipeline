<?php

namespace App\Filament\Concerns;

use App\Models\Package;
use App\Models\User;
use App\Services\InstallationTest;

/**
 * Gives a page or widget the transcript an installation test writes into.
 *
 * Lines are streamed into the open modal as they happen — `wire:stream`, so a
 * slow DNS lookup or a hanging proxy shows as the step it is stuck on rather
 * than a spinner — and kept on the component as well, so the transcript is
 * still there once the request ends and Livewire renders the modal afresh.
 */
trait RunsInstallationTests
{
    /**
     * @var list<array{text: string, tone: string}>
     */
    public array $installationTranscript = [];

    public function runInstallationTest(Package $package, ?string $version = null): bool
    {
        $this->installationTranscript = [];
        $this->stream(content: '', replace: true, name: 'installation-test');

        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return app(InstallationTest::class)->run($package, $user, $version, function (string $text, string $tone): void {
            $this->installationTranscript[] = ['text' => $text, 'tone' => $tone];
            $this->installationTestLineReported();

            $this->stream(
                content: view('filament.terminal-line', ['text' => $text, 'tone' => $tone])->render(),
                name: 'installation-test',
            );
        });
    }

    /** Pages can stream a summary alongside the existing transcript. */
    protected function installationTestLineReported(): void {}
}
