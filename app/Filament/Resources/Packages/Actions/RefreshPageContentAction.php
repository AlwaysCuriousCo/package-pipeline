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
            // Only where there is both a page to refresh and a repository to
            // refresh it from. A package published by artifact upload has no
            // file to read, and its page body is whatever was typed in the
            // panel.
            ->visible(fn (Package $record): bool => $record->hasPage() && filled($record->repository))
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
                            implode(', ', Package::PAGE_FILES),
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

                if (filled($record->page_body)) {
                    // The content was refreshed and the page will not show it,
                    // which is a confusing enough result to name.
                    $body .= ' The page still shows the content written here, which takes precedence — clear it to publish the file instead.';
                }

                Notification::make()
                    ->success()
                    ->title('Page content refreshed')
                    ->body($body)
                    ->send();
            });
    }
}
