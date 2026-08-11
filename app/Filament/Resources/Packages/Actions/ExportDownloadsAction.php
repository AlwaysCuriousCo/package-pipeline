<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Models\Package;
use App\Services\DownloadExport;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Support\Icons\Heroicon;

/**
 * Export download statistics as CSV — for one package, or for everything the
 * signed-in admin can see.
 *
 * The action only collects the window and opens the streaming route with it;
 * see DownloadExportController for why the bytes cannot come back through
 * Livewire.
 */
class ExportDownloadsAction
{
    /**
     * @param  bool  $forRecord  whether this hangs off one package's row, as
     *                           opposed to the list's header
     */
    public static function make(bool $forRecord = true): Action
    {
        return Action::make('exportDownloads')
            ->label($forRecord ? 'Export downloads' : 'Export downloads')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalHeading($forRecord ? 'Export this package\'s downloads' : 'Export download statistics')
            ->modalSubmitActionLabel('Export')
            ->schema([
                Radio::make('report')
                    ->label('Report')
                    ->options([
                        DownloadExport::SUMMARY => 'Summary — one row per version, with a count',
                        DownloadExport::DETAIL => 'Detail — one row per download',
                    ])
                    ->default(DownloadExport::SUMMARY)
                    ->required()
                    ->helperText('The summary is what you chart. The detail is what you reach for when the question is which credential pulled which version, and on a busy registry it is a very large file.'),
                DatePicker::make('from')
                    ->label('From')
                    ->maxDate(now())
                    // Both ends optional and inclusive: an operator asking for
                    // "everything up to the 31st" means the 31st as well.
                    ->helperText('Inclusive. Leave empty to start at the first download recorded.'),
                DatePicker::make('to')
                    ->label('To')
                    ->maxDate(now())
                    ->afterOrEqual('from')
                    ->helperText('Inclusive. Leave empty to run up to now.'),
            ])
            // A GET in a new tab, so the streamed response is a plain download
            // and the panel page the admin was on stays where it was.
            ->action(fn (array $data, ?Package $record) => redirect()->route('exports.downloads', array_filter([
                'report' => $data['report'] ?? DownloadExport::SUMMARY,
                'package' => $forRecord ? $record?->getKey() : null,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
            ])));
    }
}
