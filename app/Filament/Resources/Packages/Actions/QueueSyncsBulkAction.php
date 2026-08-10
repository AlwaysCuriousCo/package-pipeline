<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Services\RebuildPackage;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Sync — or rebuild — every selected package at once.
 *
 * The recovery path after a source outage or a reconnected account, where the
 * alternative is clicking Sync down a list of failing rows or dropping to
 * `packages:sync` on a box the admin may not have.
 *
 * Both variants live in one class because they differ only in the force flag
 * and their wording: the queueing rules, and the honesty about what was
 * actually queued, are the same work.
 */
class QueueSyncsBulkAction
{
    /**
     * How many package names a notification spells out before it starts
     * counting instead. A "select all" across the registry must not produce a
     * toast taller than the page.
     */
    private const NAMES_SHOWN = 5;

    public static function sync(): BulkAction
    {
        return BulkAction::make('syncSelected')
            ->label('Sync selected')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalHeading('Sync the selected packages')
            ->modalDescription('Each one is queued for a sync that pulls new refs from its source. Packages with a sync already queued are left alone, and the result is reported per package.')
            ->modalSubmitActionLabel('Sync')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::queue($records, force: false));
    }

    public static function rebuild(): BulkAction
    {
        return BulkAction::make('rebuildSelected')
            ->label('Rebuild selected')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Rebuild the selected packages')
            ->modalDescription('Every version of every selected package is re-imported from its source: metadata re-read, archives re-downloaded, versions gone upstream pruned. They keep serving while the rebuilds run, and running it twice is safe.')
            ->modalSubmitActionLabel('Rebuild')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::queue($records, force: true));
    }

    /**
     * @param  Collection<int, Package>  $records
     */
    private static function queue(Collection $records, bool $force): void
    {
        // Resolved once rather than per record: a selection can be the whole
        // registry.
        $rebuilder = $force ? app(RebuildPackage::class) : null;

        $queued = 0;
        $pending = [];
        $unsyncable = [];

        foreach ($records as $record) {
            // A package published by artifact upload has no repository to pull
            // from; queueing one would only manufacture a failure.
            if (blank($record->repository)) {
                $unsyncable[] = $record->name;

                continue;
            }

            // Both paths end at SyncPackageJob::dispatchUnlessPending(), which
            // is what makes a drop observable — dispatch() would swallow a
            // duplicate silently and let this claim work that never runs.
            $accepted = $rebuilder !== null
                ? $rebuilder->queue($record)
                : SyncPackageJob::dispatchUnlessPending($record);

            if ($accepted) {
                $queued++;

                continue;
            }

            $pending[] = $record->name;
        }

        $noun = $force ? 'rebuild' : 'sync';

        $notes = array_values(array_filter([
            $queued === 0 ? null : 'They run in the background; failures land on the packages themselves.',
            $pending === [] ? null : count($pending).' already had a sync queued and were left alone: '.self::names($pending).'.',
            $unsyncable === [] ? null : count($unsyncable).' have no repository to '.$noun.' from: '.self::names($unsyncable).'.',
        ]));

        $notification = Notification::make()
            ->title($queued === 0
                ? 'Nothing queued'
                : sprintf('%d %s queued', $queued, str($noun)->plural($queued)))
            ->body($notes === [] ? null : implode(' ', $notes));

        $queued === 0 ? $notification->info() : $notification->success();

        $notification->send();
    }

    /**
     * @param  list<string>  $names
     */
    private static function names(array $names): string
    {
        $shown = array_slice($names, 0, self::NAMES_SHOWN);
        $rest = count($names) - count($shown);

        return implode(', ', $shown).($rest > 0 ? " and {$rest} more" : '');
    }
}
