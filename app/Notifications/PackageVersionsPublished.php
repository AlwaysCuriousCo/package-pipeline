<?php

namespace App\Notifications;

use App\Enums\WebhookEvent;
use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use App\Notifications\Concerns\RoutedByAdminNotifier;
use App\Notifications\Contracts\SendsWebhook;
use App\Services\SyncOutcome;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * A package published one or more tagged versions.
 *
 * Only tags reach here. A dev branch moving is a version change too, but it
 * happens on every commit, and a bell that rings on every commit is a bell
 * nobody reads.
 */
class PackageVersionsPublished extends Notification implements SendsWebhook, ShouldQueue
{
    use Queueable, RoutedByAdminNotifier;

    public function __construct(
        public readonly Package $package,
        public readonly SyncOutcome $outcome,
    ) {}

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::VersionPublished;
    }

    /**
     * The releases, not the whole sync. A receiver's usual job is "deploy the
     * new version", so the versions that are new are the payload; what was
     * already served is not news. `latest` is stated separately because the
     * highest of the new releases need not be the package's latest — a
     * backported 1.9.1 landing after 2.0.0 is exactly this case.
     *
     * @return array<string, mixed>
     */
    public function toWebhook(): array
    {
        return [
            'package' => $this->package->name,
            'repository' => $this->package->composerRepository->path,
            'source_url' => $this->package->repository,
            'releases' => $this->outcome->releases,
            'latest' => $this->package->latest_version,
            'initial_import' => $this->outcome->initialImport,
            'total_versions' => $this->outcome->total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->success()
            ->icon('heroicon-o-tag')
            ->title($this->title())
            ->body($this->body())
            ->actions([
                Action::make('view')
                    ->label('View package')
                    ->url(PackageResource::getUrl('view', ['record' => $this->package]))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            // Also the notification preview, which is all a muted channel shows.
            ->text("{$this->title()} — {$this->body()}")
            ->headerBlock($this->title())
            ->sectionBlock(fn (SectionBlock $block) => $block->text($this->body())->markdown())
            ->contextBlock(fn (ContextBlock $block) => $block->text($this->package->repository));
    }

    private function title(): string
    {
        if ($this->outcome->initialImport) {
            return "Imported {$this->package->name}";
        }

        return count($this->outcome->releases) === 1
            ? "{$this->package->name} {$this->outcome->releases[0]}"
            : "{$this->package->name} — ".count($this->outcome->releases).' new versions';
    }

    private function body(): string
    {
        // A first import can bring in years of tags at once; listing them all
        // would say less than the count and the latest version do.
        if ($this->outcome->initialImport) {
            return "{$this->outcome->total} versions are now served, latest "
                .($this->package->latest_version ?? 'none tagged').'.';
        }

        return 'Now serving '.implode(', ', $this->outcome->releases).'.';
    }
}
