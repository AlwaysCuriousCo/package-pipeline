<?php

namespace App\Notifications;

use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;

/**
 * A package's sync gave up.
 *
 * Sent only once the job has exhausted its retries, so a rate limit that
 * clears on the second attempt never reaches anyone. A silent failure here
 * means a package quietly stops receiving releases, which is the one outcome
 * worth interrupting someone for.
 */
class PackageSyncFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Package $package,
        public readonly string $reason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['slack'] : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
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
            ->text("{$this->title()} — {$this->body()}")
            ->headerBlock($this->title())
            ->sectionBlock(fn (SectionBlock $block) => $block->text($this->body())->markdown())
            ->contextBlock(fn (ContextBlock $block) => $block->text($this->package->repository));
    }

    private function title(): string
    {
        return "Could not sync {$this->package->name}";
    }

    private function body(): string
    {
        // Provider errors carry whole JSON bodies; the panel and Slack both
        // want the first line of it, not the payload.
        return Str::limit(Str::squish($this->reason), 300);
    }
}
