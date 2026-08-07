<?php

namespace App\Notifications;

use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use App\Services\SyncOutcome;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
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
class PackageVersionsPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Package $package,
        public readonly SyncOutcome $outcome,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        // Users read these in the panel's bell; the Slack channel is routed to
        // anonymously, because it belongs to the installation rather than to
        // any one person. @see \App\Services\AdminNotifier
        return $notifiable instanceof AnonymousNotifiable ? ['slack'] : ['database'];
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
