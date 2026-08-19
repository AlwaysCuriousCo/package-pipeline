<?php

namespace App\Notifications\Billing;

use App\Notifications\Concerns\AnnouncedByMail;
use App\Notifications\Concerns\RoutedByAdminNotifier;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * An admin-side billing event that needs a person: a dispute opened, a
 * merchant event that failed processing, reconcile drift.
 *
 * One class rather than one per event because these differ only in their
 * words and their urgency — the routing (bell, mail, Slack) is identical,
 * and each carries a link to the record that needs looking at.
 */
class BillingAlert extends Notification implements ShouldQueue
{
    use AnnouncedByMail, Queueable, RoutedByAdminNotifier;

    public function __construct(
        private readonly string $alertTitle,
        private readonly string $alertBody,
        private readonly string $url,
        private readonly string $tone = 'danger',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->status($this->tone)
            ->icon('heroicon-o-credit-card')
            ->title($this->title())
            ->body($this->body())
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->url($this->url)
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text("{$this->title()} — {$this->body()}")
            ->headerBlock($this->title())
            ->sectionBlock(fn (SectionBlock $block) => $block->text($this->body())->markdown());
    }

    protected function mailTone(): string
    {
        return $this->tone;
    }

    /**
     * @return array{label: string, url: string}
     */
    protected function mailAction(): array
    {
        return ['label' => 'View', 'url' => $this->url];
    }

    protected function title(): string
    {
        return $this->alertTitle;
    }

    protected function body(): string
    {
        return $this->alertBody;
    }
}
