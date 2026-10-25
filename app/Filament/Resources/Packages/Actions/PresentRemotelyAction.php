<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Models\Package;
use App\Services\PackagePresentation;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use WendellAdriel\SlideWire\DTOs\RemoteConfig;
use WendellAdriel\SlideWire\Support\RemoteSessionManager;

/**
 * Remote control for a package's Presentation Mode deck: one person drives,
 * everyone with the viewer link follows.
 *
 * SlideWire's own sessions, started from the panel instead of its artisan
 * command. Two links come back:
 *
 *  - the controller's, signed and expiring with the session, which is the
 *    only credential involved — it opens the deck as presenter, notes and
 *    all. Signed relative to the host, so it works on a repository's own
 *    host, which is the host it is handed out on. @see App\Livewire\PackageDeck
 *  - the viewer's, which is the page's own URL plus the session key. It is
 *    not a secret; it is the link given to an audience.
 *
 * One live session per package: starting another ends the last, and ending
 * one kills both links at once, because the session they point at is gone.
 * Both are written to the audit log, which is the record of who presented
 * what, and when.
 */
class PresentRemotelyAction
{
    /** @var array<string, string> */
    private const DURATIONS = [
        '30m' => '30 minutes',
        '1h' => '1 hour',
        '2h' => '2 hours',
        '4h' => '4 hours',
        '8h' => '8 hours',
        '1d' => '1 day',
    ];

    public static function make(): Action
    {
        return Action::make('presentRemotely')
            ->label('Present remotely')
            ->icon(Heroicon::OutlinedPresentationChartBar)
            ->color('gray')
            ->visible(fn (Package $record): bool => $record->hasPresentation())
            ->modalDescription('Starts a remote session: you get a presenter link that drives the deck and shows your speaker notes, and a link for the audience that follows along. Starting a new session ends the current one.')
            ->schema([
                Select::make('ttl')
                    ->label('Session length')
                    ->options(self::DURATIONS)
                    ->default('2h')
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->action(function (Package $record, array $data): void {
                if (app(PackagePresentation::class)->compile($record) === null) {
                    Notification::make()
                        ->warning()
                        ->title('Nothing to present')
                        ->body('This package has no deck that can be shown yet.')
                        ->send();

                    return;
                }

                $ttl = array_key_exists($data['ttl'] ?? null, self::DURATIONS) ? $data['ttl'] : '2h';

                self::end($record);

                $session = app(RemoteSessionManager::class)->create(PackagePresentation::key($record), $ttl);

                self::store()->put(self::pointer($record), $session['key'], $session['ttl_seconds']);

                [$controller, $viewer] = self::links($record, $session['key'], $session['ttl_seconds']);

                activity('audit')
                    ->performedOn($record)
                    ->event('presentation_remote_started')
                    ->withProperties(['ttl' => $ttl])
                    ->log('presentation_remote_started');

                Notification::make()
                    ->success()
                    ->title('Remote session started')
                    ->body(new HtmlString(self::linksHtml($controller, $viewer, self::DURATIONS[$ttl])))
                    ->persistent()
                    ->send();
            });
    }

    public static function makeEnd(): Action
    {
        return Action::make('endRemotePresentation')
            ->label('End remote session')
            ->icon(Heroicon::OutlinedStopCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The presenter and viewer links stop working immediately.')
            ->visible(fn (Package $record): bool => self::store()->has(self::pointer($record)))
            ->action(function (Package $record): void {
                if (! self::end($record)) {
                    return;
                }

                Notification::make()->success()->title('Remote session ended')->send();
            });
    }

    /**
     * End the package's live session, if it has one.
     */
    private static function end(Package $record): bool
    {
        $key = self::store()->pull(self::pointer($record));

        if (! is_string($key)) {
            return false;
        }

        app(RemoteSessionManager::class)->delete($key);

        activity('audit')
            ->performedOn($record)
            ->event('presentation_remote_ended')
            ->log('presentation_remote_ended');

        return true;
    }

    /**
     * The presenter's signed link and the audience's, both at the page's own
     * address — a repository's host when it has one.
     *
     * @return array{string, string}
     */
    private static function links(Package $record, string $key, int $ttlSeconds): array
    {
        $repository = $record->servingRepository();
        [$vendor, $name] = explode('/', (string) $record->name, 2);

        $parameters = ['vendor' => $vendor, 'package' => $name, 'remote' => $key];
        $route = 'pages.package';

        if ($repository->pathPrefix() !== '') {
            $route = 'pages.repository.package';
            $parameters['repositoryPath'] = $repository->path;
        }

        $signed = URL::temporarySignedRoute($route, now()->addSeconds($ttlSeconds), $parameters, absolute: false);

        return [
            $repository->pageRootUrl().$signed,
            $record->pageUrl().'?remote='.rawurlencode($key),
        ];
    }

    private static function linksHtml(string $controller, string $viewer, string $duration): string
    {
        // Plain markup: Filament sanitizes a notification's body, styles and
        // all. The code's light modules are drawn white so it scans on a
        // dark panel too.
        $qr = new QRCode(new QROptions(['bgColor' => '#ffffff']));

        $link = fn (string $label, string $url, string $note): string => sprintf(
            '<p><strong>%1$s</strong> — %4$s</p>'
            .'<p><img src="%3$s" alt="QR code for the %1$s link" width="128" height="128"></p>'
            .'<p><a href="%2$s" target="_blank" rel="noopener" class="underline break-all">%2$s</a></p>',
            e($label),
            e($url),
            e($qr->render($url)),
            e($note),
        );

        return '<p>Lasts '.e($duration).'.</p>'
            .$link('Presenter', $controller, 'keep this one to yourself: it drives the deck and shows your notes.')
            .$link('Audience', $viewer, 'share this one.');
    }

    /**
     * Which session a package has live, kept beside SlideWire's own state in
     * whichever store it is configured to use.
     */
    private static function pointer(Package $record): string
    {
        return 'presentation-remote:'.$record->getKey();
    }

    private static function store(): Cache
    {
        return CacheFacade::store(RemoteConfig::resolved()->cacheStore);
    }
}
