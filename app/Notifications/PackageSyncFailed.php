<?php

namespace App\Notifications;

use App\Enums\WebhookEvent;
use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use App\Notifications\Concerns\AboutOnePackage;
use App\Notifications\Concerns\AnnouncedByMail;
use App\Notifications\Concerns\RoutedByAdminNotifier;
use App\Notifications\Contracts\SendsWebhook;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
class PackageSyncFailed extends Notification implements SendsWebhook, ShouldQueue
{
    use AboutOnePackage, AnnouncedByMail, Queueable, RoutedByAdminNotifier;

    /**
     * How much of a provider's error a receiver is sent.
     *
     * Far past the 300 characters the bell and Slack show, because the whole
     * reason a delivery carries the error at all is that a log sink or an
     * incident tracker can use what a chat message cannot. What it is not is
     * unbounded: `reason` is whatever a provider put in a failed response, and
     * an HTML error page or a JSON body from a proxy can run to hundreds of
     * kilobytes — which would then be signed, queued, retried twice and stored
     * in the job payload of every subscribed endpoint.
     */
    private const REASON_LIMIT = 2000;

    public function __construct(
        public readonly Package $package,
        public readonly string $reason,
    ) {}

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::SyncFailed;
    }

    /**
     * The reason keeps its shape rather than being squished to a line as the
     * bell and Slack show it: a receiver is as likely to be a log sink or an
     * incident tracker as a chat message, and cutting a provider's error to a
     * headline is a choice only a display should make.
     *
     * Its length is another matter. A limit is not a display decision, it is
     * what stops one provider's error page deciding the size of every delivery
     * this registry makes.
     *
     * @return array<string, mixed>
     */
    public function toWebhook(): array
    {
        return [
            'package' => $this->package->name,
            'repository' => $this->package->composerRepository->path,
            'source_url' => $this->package->repository,
            'reason' => Str::limit($this->reason, self::REASON_LIMIT),
            'last_synced_at' => $this->package->last_synced_at?->toIso8601String(),
        ];
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

    protected function mailTone(): string
    {
        return 'danger';
    }

    protected function title(): string
    {
        return "Could not sync {$this->package->name}";
    }

    protected function body(): string
    {
        // Provider errors carry whole JSON bodies; the panel and Slack both
        // want the first line of it, not the payload.
        return Str::limit(Str::squish($this->reason), 300);
    }
}
