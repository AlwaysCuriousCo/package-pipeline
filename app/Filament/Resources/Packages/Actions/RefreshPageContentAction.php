<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Models\Package;
use App\Services\PackagePage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Re-read the package's page body from its repository, now.
 *
 * A sync does this already, so this action is for the gap between the two:
 * somebody has just edited the README — usually because the published page
 * was wrong — and the alternative is a full sync that re-reads every ref, or
 * an hour's wait. Reading one file is neither.
 *
 * It runs inline rather than on the queue, which is the whole point: the
 * question being asked is "which file does this page get, and did my edit
 * land", and an answer that arrives after the page has been navigated away
 * from is no answer. It costs at most one request per candidate filename, and
 * usually one.
 */
class RefreshPageContentAction
{
    public static function make(): Action
    {
        return Action::make('refreshPageContent')
            ->label('Refresh page')
            ->icon(Heroicon::OutlinedArrowDownOnSquare)
            ->color('gray')
            // A package published by artifact upload has no file to read.
            // Only where there is something to read: a page whose body is
            // written in the panel reads nothing, and offering to refresh it
            // would promise work that does not happen.
            ->visible(fn (Package $record): bool => $record->hasPage()
                && filled($record->repository)
                && $record->pageBodyCandidates() !== [])
            ->action(function (Package $record): void {
                $found = app(PackagePage::class)->refresh($record);

                $record->refresh();

                if (! $found) {
                    // Deliberately not a failure: a repository with no README
                    // is a fact about the repository, and the page renders
                    // the package's metadata and install commands without one.
                    Notification::make()
                        ->warning()
                        ->title('No page content found')
                        ->body(sprintf(
                            'Looked for %s in %s. The page will show %s description and install commands alone.',
                            implode(', ', $record->pageBodyCandidates()),
                            $record->hasSubdirectory() ? "{$record->subdirectory}/" : 'the repository root',
                            $record->name,
                        ))
                        ->send();

                    return;
                }

                // Said out loud, because which file won is the thing an admin
                // is usually here to find out — a repository with both a
                // package-page.md and a README publishes only the first.
                $body = "Read {$record->page_source_path} from the repository.";

                Notification::make()
                    ->success()
                    ->title('Page content refreshed')
                    ->body($body)
                    ->send();
            });
    }
}
