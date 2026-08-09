<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Enums\WebhookCoverage;
use App\Filament\Resources\Packages\Actions\SyncPackageAction;
use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use App\Services\GitHub\WebhookRegistrar;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SyncPackageAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Carry the auto-sync toggle through to GitHub.
     *
     * Only when it was the thing that changed: every other save — a renamed
     * package, a corrected description — must not cost an API call, and must
     * not retry a webhook that failed for a reason nobody has fixed yet.
     */
    protected function afterSave(): void
    {
        /** @var Package $package */
        $package = $this->getRecord();

        if (! $package->wasChanged('webhook_enabled')) {
            return;
        }

        $coverage = app(WebhookRegistrar::class)->reconcile($package);

        Notification::make()
            ->status($coverage === WebhookCoverage::Failed ? 'warning' : 'success')
            ->title(match ($coverage) {
                WebhookCoverage::Disabled => 'Auto-sync switched off',
                WebhookCoverage::Failed => 'Auto-sync is on, but the webhook could not be created',
                default => 'Auto-sync switched on',
            })
            ->body(match ($coverage) {
                WebhookCoverage::Disabled => "Pushes to {$package->repositoryPath()} will no longer sync this package, and its webhook has been removed from GitHub.",
                WebhookCoverage::Application => 'Pushes are delivered through the GitHub App\'s webhook, so there was nothing to create.',
                WebhookCoverage::Repository => "A webhook is set up on {$package->repositoryPath()}.",
                WebhookCoverage::Failed => (string) app(WebhookRegistrar::class)->unmetRequirement($package),
                WebhookCoverage::None => 'No webhook covers this repository yet.',
            })
            // persistent() takes no argument, so passing the condition to it
            // pinned every one of these notifications open, success included.
            ->when(
                $coverage === WebhookCoverage::Failed,
                fn (Notification $notification): Notification => $notification->persistent(),
            )
            ->send();
    }
}
