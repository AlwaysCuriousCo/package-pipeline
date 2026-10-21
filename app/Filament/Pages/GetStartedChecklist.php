<?php

namespace App\Filament\Pages;

use App\Models\Source;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The first run as a checklist: each item is a wizard of its own, opened from
 * here, with its progress shown so a setup left half way can be picked up in
 * any order. The repository wizard is the only item so far.
 */
class GetStartedChecklist extends Page
{
    protected static ?string $slug = 'get-started';

    protected static ?string $title = 'Get started';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = -10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Source::class);
    }

    /**
     * In the navigation until the registry has done its job once — a package
     * installed, or an installation test passed — so a first run left half
     * way is a click away from anywhere. Later packages are added from
     * Packages → Add packages.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && ! GetStarted::complete();
    }

    public static function getNavigationBadge(): ?string
    {
        return GetStarted::getNavigationBadge();
    }

    /**
     * Each item on the checklist, with where it has got to.
     *
     * @return list<array{key: string, title: string, description: string, url: string, status: string, done: bool, started: bool}>
     */
    public static function items(): array
    {
        $steps = GetStarted::progress();
        $number = GetStarted::currentStep();
        $done = GetStarted::complete();
        $started = $done || array_filter(array_column($steps, 'done')) !== [];

        return [[
            'key' => 'repository',
            'title' => 'Repository',
            'description' => 'Connect GitHub, choose packages, let them sync, and install one with Composer.',
            'url' => GetStarted::getUrl(),
            'status' => match (true) {
                $done => 'Done',
                $started => "Step {$number} of ".count($steps).': '.$steps[$number - 1]['title'],
                default => 'Not started',
            },
            'done' => $done,
            'started' => $started,
        ]];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(array_map(
            fn (array $item): Section => Section::make($item['title'])
                ->key($item['key'])
                ->description($item['description'])
                ->icon($item['done'] ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedRocketLaunch)
                ->headerActions([
                    Action::make("open_{$item['key']}")
                        ->label($item['done'] ? 'Review' : ($item['started'] ? 'Continue' : 'Start'))
                        ->color($item['done'] ? 'gray' : 'primary')
                        ->url($item['url']),
                ])
                ->schema([Text::make($item['status'])->color($item['done'] ? 'success' : 'gray')]),
            static::items(),
        ));
    }
}
