<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Models\Package;
use App\Services\SbomExport;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Download this package's CycloneDX bill of materials — one component per
 * version, with the licence each declares and the archive each resolves to.
 *
 * A link rather than an action returning a file; see SbomExportController for
 * why the bytes cannot come back through Livewire.
 */
class ExportSbomAction
{
    public static function make(): Action
    {
        return Action::make('exportSbom')
            ->label('SBOM')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->tooltip('CycloneDX '.SbomExport::SPEC_VERSION.' — every version of this package, with its declared license.')
            ->url(fn (Package $record): string => route('exports.sbom', ['package' => $record->getKey()]))
            ->openUrlInNewTab();
    }
}
