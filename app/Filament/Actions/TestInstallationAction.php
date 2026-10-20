<?php

namespace App\Filament\Actions;

use App\Models\Package;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

class TestInstallationAction
{
    /**
     * Run an installation test from wherever a package is in view.
     *
     * The modal stays open once the run ends, so the transcript can be read —
     * and run again after a fix — without starting over.
     *
     * @param  Closure(): ?Package|null  $package  the package to install; the
     *                                             page's record when omitted
     */
    public static function make(?Closure $package = null, bool $chooseVersion = true): Action
    {
        // The component runs it, through RunsInstallationTests, so that what
        // streams into the modal is the component's own transcript.
        $resolve = fn (?Package $record): ?Package => $package !== null ? $package() : $record;

        return Action::make('testInstallation')
            ->label('Test installation')
            ->icon(Heroicon::OutlinedCommandLine)
            ->color('gray')
            ->visible(fn (?Package $record): bool => $resolve($record)?->versions()->exists() ?? false)
            ->modalHeading(fn (?Package $record): string => 'Test installing '.$resolve($record)?->name)
            ->modalDescription(fn (?Package $record): string => 'Makes the requests Composer makes, through '
                .rtrim((string) $resolve($record)?->composerRepository->url(), '/')
                .', with a token that exists for this run only. Nothing is counted as a download.')
            ->modalWidth(Width::FourExtraLarge)
            ->schema($chooseVersion ? [
                Select::make('version')
                    ->label('Version')
                    ->placeholder('Latest stable, as composer require would pick')
                    ->options(fn (?Package $record): array => $resolve($record)?->versions()
                        ->orderByDesc('released_at')
                        ->pluck('version', 'version')
                        ->all() ?? []),
            ] : [])
            ->modalContent(fn (Component $livewire) => view('filament.terminal', [
                'lines' => property_exists($livewire, 'installationTranscript') ? $livewire->installationTranscript : [],
                'stream' => 'installation-test',
                'idle' => 'Run test makes the requests Composer makes, and shows each one here as it happens.',
            ]))
            ->modalSubmitActionLabel('Run test')
            ->modalCancelActionLabel('Close')
            ->action(function (array $data, Action $action, Component $livewire, ?Package $record) use ($resolve): void {
                $target = $resolve($record);

                if ($target !== null && method_exists($livewire, 'runInstallationTest')) {
                    $livewire->runInstallationTest($target, filled($data['version'] ?? null) ? (string) $data['version'] : null);
                }

                // Keep the modal open on the transcript.
                $action->halt();
            });
    }
}
