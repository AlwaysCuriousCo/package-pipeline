<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Enums\WebhookCoverage;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Schemas\PackageWizard;
use App\Models\Package;
use App\Services\GitHub\WebhookRegistrar;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class CreatePackage extends CreateRecord
{
    use HasWizard;

    protected static string $resource = PackageResource::class;

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return PackageWizard::steps();
    }

    /**
     * Give the new package a way to hear about its own pushes.
     *
     * Nearly always this does nothing at all: a package under a connected
     * GitHub App source is already covered by the app's own webhook. Where it
     * is not — a token-based source, or a repository with only its own token —
     * a hook is created on the repository, which is the one thing an admin
     * would otherwise have to go and do on GitHub by hand.
     */
    protected function afterCreate(): void
    {
        /** @var Package $package */
        $package = $this->getRecord();

        $registrar = app(WebhookRegistrar::class);

        if ($registrar->register($package) === WebhookCoverage::Repository) {
            Notification::make()
                ->success()
                ->title('Webhook created')
                ->body("Pushes to {$package->repositoryPath()} will sync this package automatically.")
                ->send();
        }

        // A package that cannot auto-sync is still a package, so this is said
        // rather than raised — and said fully, because the fix is not obvious
        // and the alternative is finding out weeks later that a release never
        // arrived.
        if ($reason = $registrar->unmetRequirement($package)) {
            Notification::make()
                ->warning()
                ->title('This package will not sync itself')
                ->body($reason)
                ->persistent()
                ->send();
        }
    }
}
