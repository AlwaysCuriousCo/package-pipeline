<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Enums\WebhookCoverage;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Schemas\PackageWizard;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Source;
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
     * Arriving from a source's packages list pre-selects that source, so the
     * new package authenticates through it without being told to again.
     */
    protected function afterFill(): void
    {
        $sourceId = request()->integer('source');

        if ($sourceId && Source::whereKey($sourceId)->exists()) {
            $this->data['source_id'] = $sourceId;
        }
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

        // Any repositories chosen alongside the one the package lives in.
        // @see PackageForm::servingRepositories()
        $package->serveFrom($this->data['serving_repositories'] ?? []);

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

        // The package exists but has no versions until something syncs it, and
        // the next push could be months away — so the first sync runs now,
        // with everything above already configured.
        SyncPackageJob::dispatch($package);

        Notification::make()
            ->success()
            ->title('First sync queued')
            ->body("{$package->name} is importing its versions in the background.")
            ->send();
    }
}
