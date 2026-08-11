<?php

namespace App\Filament\Resources\Users;

use App\Auth\PasswordSetupLink;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Js;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    // Shares a group with Shield's Roles resource, which defines what the
    // roles assigned here may do.
    protected static string|UnitEnum|null $navigationGroup = 'Access Management';

    // Account administration sits after the day-to-day resources.
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * The one place a password setup link is shown: persistent so it survives
     * any redirect, dismissed only by the admin who copied it.
     *
     * Shown rather than emailed, for the same reason the console commands
     * print it — provisioning an account must not depend on working mail, and
     * a self-hosted registry is routinely stood up before SMTP is configured.
     * The admin delivers it however they already talk to the person.
     */
    public static function passwordSetupNotification(string $title, User $user): Notification
    {
        $link = PasswordSetupLink::for($user);

        return Notification::make()
            ->success()
            ->title($title)
            ->body(sprintf(
                'Send it to %s yourself — nothing is emailed. It is single-use and expires in %d minutes; issue another from the users list if it goes stale.<br><br><code>%s</code>',
                e($user->email),
                PasswordSetupLink::TTL_MINUTES,
                e($link),
            ))
            ->persistent()
            ->actions([
                Action::make('copy')
                    ->label('Copy link')
                    ->icon(Heroicon::OutlinedClipboard)
                    // Client-side only: the link ships inside the
                    // notification, so copying never round-trips the token.
                    ->alpineClickHandler('window.navigator.clipboard.writeText('.Js::from($link).')'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
