<?php

namespace App\Notifications;

use App\Filament\Resources\Packages\PackageResource;
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
 * Packages this registry holds but cannot serve, because their stored names are
 * not the lowercase ones Composer asks in.
 *
 * Raised by the migration that normalizes package names, for the rows it had to
 * leave alone: the ones whose lowercase spelling a sibling package in the same
 * Composer repository already publishes, where renaming would collide on the
 * unique index and deleting either would unpublish somebody's versions.
 *
 * The panel carries this standing — PackageSynchronizer re-asserts the same
 * notice in `sync_error` on every run, so the red timestamp and the navigation
 * badge hold until a human resolves the pair. This is the push half: a deploy
 * running unattended in CI has nobody reading its output, and the packages
 * concerned are already 404ing on both endpoints by the time anyone opens the
 * panel to look.
 *
 * Not a webhook event. An endpoint subscribes to something a registry does, and
 * this is a one-off finding about the state of one installation's data.
 */
class UnserveablePackageNames extends Notification implements ShouldQueue
{
    use AnnouncedByMail, Queueable, RoutedByAdminNotifier;

    /**
     * @param  list<string>  $names  as stored, which is the spelling that has to
     *                               be searched for to find the row
     */
    public function __construct(public readonly array $names) {}

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
                    ->label('View packages')
                    ->url(PackageResource::getUrl('index'))
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

    /**
     * The listing rather than one package, because the finding is about a set
     * of them and the rows have to be found by searching for a spelling the
     * panel does not link to.
     *
     * @return array{label: string, url: string}
     */
    protected function mailAction(): array
    {
        return [
            'label' => 'View packages',
            'url' => PackageResource::getUrl('index'),
        ];
    }

    protected function mailTone(): string
    {
        return 'danger';
    }

    protected function title(): string
    {
        $count = count($this->names);

        return $count.' '.str('package')->plural($count).' cannot be served under the stored name';
    }

    protected function body(): string
    {
        return 'Composer only ever asks for a lowercase name, and these are not stored in one — they answer '
            .'404 from /p2 and /dist. Each shares its lowercase spelling with another package in the same '
            .'Composer repository, so one of every pair has to be deleted before the other can be renamed: '
            .implode(', ', $this->names);
    }
}
