<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Http\Controllers\VersionArchiveController;
use App\Models\PackageVersion;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Download the stored zip for one version — the bytes Composer would install,
 * rather than what the panel says about them.
 *
 * A link rather than an action returning a file, for the reason the exports
 * are links: Livewire delivers a file by base64-encoding it into its response,
 * which for a package archive means holding the whole zip in memory to send a
 * file the disk could have streamed — or handed to the client itself, when the
 * dist disk signs its own URLs.
 *
 * @see VersionArchiveController
 */
class DownloadArchiveAction
{
    public static function make(string $name = 'downloadArchive'): Action
    {
        return Action::make($name)
            ->label('Download')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->tooltip('The stored zip for this version.')
            // Only the path is checked, not the file: asking the disk whether
            // the object is really there would be a metadata call per row on
            // every render of the table. A path that has outlived its file is
            // rare and the route says so plainly, which is the right place to
            // spend that call.
            ->visible(fn (PackageVersion $record): bool => $record->archive_path !== null)
            ->url(fn (PackageVersion $record): string => route('downloads.version', $record))
            ->openUrlInNewTab();
    }
}
