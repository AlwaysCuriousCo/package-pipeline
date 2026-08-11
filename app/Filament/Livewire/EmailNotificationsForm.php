<?php

namespace App\Filament\Livewire;

use App\Models\User;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Joaopaulolndev\FilamentEditProfile\Concerns\HasUser;
use Joaopaulolndev\FilamentEditProfile\Livewire\BaseProfileForm;
use RuntimeException;

/**
 * The one thing a panel user gets to decide about the registry's announcements:
 * whether they arrive by email as well as in the bell.
 *
 * A section on the profile page rather than a screen of per-event checkboxes,
 * because there are four announcements and they are all the same answer in
 * practice — somebody either wants the registry in their inbox or does not. A
 * matrix would be more configuration and no more control.
 *
 * The bell is deliberately not on the form. It costs a row, it is read where
 * the work is done, and a user who could switch it off would be a user the
 * registry has no way at all of reaching about a package that stopped syncing.
 *
 * Rendered only while `MAIL_ADMIN_NOTIFICATIONS` is on — AdminPanelProvider
 * registers it conditionally — because a switch that governs nothing is worse
 * than no switch. It is still safe if that slips: User::wantsMailAnnouncements()
 * asks the installation's setting first, so the column alone can never turn
 * email on.
 *
 * @see User::wantsMailAnnouncements()
 */
class EmailNotificationsForm extends BaseProfileForm
{
    use HasUser;

    protected string $view = 'filament.livewire.email-notifications-form';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Between the password section (20) and browser sessions (50). */
    protected static int $sort = 30;

    public function mount(): void
    {
        $this->user = $this->getUser();

        $this->schema()->fill([
            'email_notifications' => $this->panelUser()->email_notifications,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Email notifications')
                    ->aside()
                    ->description('Releases, failed syncs and abandoned packages always appear in the bell. Choose whether they are emailed to you as well.')
                    ->schema([
                        Toggle::make('email_notifications')
                            ->label("Email me the registry's announcements")
                            ->helperText('Sent to '.$this->panelUser()->email.'.'),
                    ]),
            ])
            ->model($this->panelUser())
            ->statePath('data');
    }

    public function updateEmailNotifications(): void
    {
        try {
            $state = $this->schema()->getState();
        } catch (Halt $exception) {
            return;
        }

        $wanted = (bool) ($state['email_notifications'] ?? false);

        $this->panelUser()->update(['email_notifications' => $wanted]);

        Notification::make()
            ->success()
            ->title($wanted
                ? 'Announcements will be emailed to you as well as shown in the bell.'
                : 'Announcements will only appear in the bell.')
            ->send();
    }

    /**
     * The signed-in account, as this application's User rather than the
     * `Authenticatable & Model` the plugin promises every profile component.
     * One step narrower, because `email_notifications` is our column.
     */
    private function panelUser(): User
    {
        $user = $this->getUser();

        if (! $user instanceof User) {
            throw new RuntimeException('The profile page is only ever rendered for a panel user.');
        }

        return $user;
    }

    /**
     * The form, resolved by name rather than through the `$this->form` magic
     * the plugin's own components use — same object, but one static analysis
     * can see the type of.
     */
    private function schema(): Schema
    {
        return $this->getSchema('form')
            ?? throw new RuntimeException('The profile form schema is missing.');
    }
}
