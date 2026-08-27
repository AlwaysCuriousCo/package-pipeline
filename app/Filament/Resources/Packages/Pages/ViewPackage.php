<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Resources\Packages\Actions\CreateWebhookAction;
use App\Filament\Resources\Packages\Actions\ExportSbomAction;
use App\Filament\Resources\Packages\Actions\RebuildPackageAction;
use App\Filament\Resources\Packages\Actions\RefreshPageContentAction;
use App\Filament\Resources\Packages\Actions\SyncPackageAction;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Widgets\PackageDownloadsChart;
use App\Filament\Resources\Packages\Widgets\PackageSyncProgress;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPackage extends ViewRecord
{
    protected static string $resource = PackageResource::class;

    /**
     * Which of the package's repositories the Install card prints the
     * register command for. Home by default; the card's select changes it.
     */
    public ?int $installRepository = null;

    /**
     * The plain text of a token the Install card issued this request — the
     * only time it exists outside the creator's clipboard.
     */
    public ?string $plainTextToken = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->installRepository = $this->record->repository_id;
    }

    protected function getHeaderActions(): array
    {
        return [
            SyncPackageAction::make(),
            RebuildPackageAction::make(),
            RefreshPageContentAction::make(),
            CreateWebhookAction::make(),
            ExportSbomAction::make(),
            EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PackageSyncProgress::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            PackageDownloadsChart::class,
        ];
    }
}
