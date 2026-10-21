<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\GetStarted;
use App\Filament\Pages\GetStartedChecklist;
use App\Models\Source;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * The way into the first run from the page a new install lands on: where the
 * Get started wizard has got to, and a button to carry on there.
 *
 * A dashboard of zeros says nothing about what to do next. The steps
 * themselves live on the wizard, which reads them from the registry's own
 * state, so this card has nothing to tick off: it goes away once a package
 * has been installed, or an installation test has passed, which is the
 * moment the registry has done its job once. Anybody done with it sooner
 * can dismiss it for themselves.
 */
class GettingStarted extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.getting-started';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('viewAny', Source::class)
            && $user->getting_started_dismissed_at === null
            && ! GetStarted::complete();
    }

    /**
     * Put the card away for this user, on every browser they sign in from.
     * Nothing brings it back: the wizard and everything it uses are still a
     * link or the navigation away.
     */
    public function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->iconButton()
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->tooltip('Dismiss')
            ->action(function (): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    $user->forceFill(['getting_started_dismissed_at' => now()])->save();
                }

                Notification::make()->success()->title('Getting started dismissed')->send();

                // The dashboard decides which widgets to render; reloading it
                // is what takes this one off the page.
                $this->redirect(Dashboard::getUrl());
            });
    }

    public function continueAction(): Action
    {
        return Action::make('continue')
            ->label(GetStarted::currentStep() === 1 ? 'Start setup' : 'Continue setup')
            ->icon(Heroicon::OutlinedArrowRight)
            ->iconPosition(IconPosition::After)
            ->color('primary')
            ->url(GetStartedChecklist::getUrl());
    }

    /**
     * Where the wizard has got to.
     *
     * @return array{number: int, total: int, title: string}
     */
    public function step(): array
    {
        $steps = GetStarted::progress();
        $number = GetStarted::currentStep();

        return ['number' => $number, 'total' => count($steps), 'title' => $steps[$number - 1]['title']];
    }
}
