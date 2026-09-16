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
use App\Models\Package;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

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

    /** The Basic username that token is configured with. */
    public ?string $tokenUsername = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // $record on a ViewRecord is typed as the key or the model, so the
        // package is asked for rather than assumed.
        $package = $this->getRecord();

        $this->installRepository = $package instanceof Package ? $package->repository_id : null;
    }

    /**
     * The heading is a GitHub-style switcher: the package name opens a
     * searchable list of every package the user can see, and picking one
     * jumps to its view page. The title (breadcrumb, browser tab) stays text.
     */
    public function getHeading(): string|Htmlable|null
    {
        // ponytail: every visible package is rendered and filtered client-side;
        // move the search server-side when a registry outgrows a few thousand.
        return view('filament.resources.packages.package-switcher', [
            'current' => $this->getRecord(),
            'packages' => PackageResource::getEloquentQuery()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            SyncPackageAction::make(),
            EditAction::make(),
            ActionGroup::make([
                RebuildPackageAction::make(),
                RefreshPageContentAction::make(),
                CreateWebhookAction::make(),
                ExportSbomAction::make(),
            ]),
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
