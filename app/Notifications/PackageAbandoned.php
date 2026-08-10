<?php

namespace App\Notifications;

use App\Enums\WebhookEvent;
use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
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

/**
 * A package was marked abandoned.
 *
 * Composer surfaces this to every consumer that resolves the package, so it is
 * the one metadata change here that reaches out and asks other teams to do
 * something. Sent once, when the flag is raised — not on the syncs afterwards,
 * which would say the same thing hourly forever.
 */
class PackageAbandoned extends Notification implements SendsWebhook, ShouldQueue
{
    use Queueable, RoutedByAdminNotifier;

    public function __construct(public readonly Package $package) {}

    public function webhookEvent(): WebhookEvent
    {
        return WebhookEvent::PackageAbandoned;
    }

    /**
     * `replacement` is null when the package was abandoned without naming one,
     * which is a different thing from "not stated": Composer prints a bare
     * "abandoned, no replacement suggested" for that case, and a receiver
     * opening tickets wants to know which of the two it is looking at.
     *
     * @return array<string, mixed>
     */
    public function toWebhook(): array
    {
        return [
            'package' => $this->package->name,
            'repository' => $this->package->composerRepository->path,
            'source_url' => $this->package->repository,
            'replacement' => $this->package->replacement_package,
            'latest' => $this->package->latest_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->warning()
            ->icon('heroicon-o-archive-box-x-mark')
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
            ->contextBlock(fn (ContextBlock $block) => $block->text((string) $this->package->repository));
    }

    private function title(): string
    {
        return "Abandoned {$this->package->name}";
    }

    private function body(): string
    {
        return filled($this->package->replacement_package)
            ? "Consumers are now told to use {$this->package->replacement_package} instead."
            : 'Consumers are now told it is abandoned, with no replacement suggested.';
    }
}
