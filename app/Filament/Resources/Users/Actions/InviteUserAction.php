<?php

namespace App\Filament\Resources\Users\Actions;

use App\Console\Commands\AddUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Provision an account without inventing a password for someone else.
 *
 * The panel's Create form asks an admin to type one, which then has to travel
 * to its owner somehow — and whatever channel carries it, the admin knows the
 * password. The console has not worked that way since `user:add`: the account
 * is created holding a random string nobody ever learns, and a short-lived
 * link sets the real password in the browser. This is that flow, in the panel.
 *
 * @see AddUser
 */
class InviteUserAction
{
    public static function make(): Action
    {
        return Action::make('invite')
            ->label('Invite user')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (): bool => UserResource::canCreate())
            ->modalHeading('Invite a user')
            ->modalDescription('The account is created holding a random password nobody ever learns; a single-use link, shown once here, is how its owner sets a real one.')
            ->modalSubmitActionLabel('Create and show the link')
            ->schema([
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(User::class, 'email')
                    ->helperText('The address the account signs in with. Nothing is sent to it — you deliver the link yourself.'),
                TextInput::make('name')
                    ->maxLength(255)
                    ->helperText('Optional. Derived from the address when left empty.'),
                Select::make('roles')
                    ->multiple()
                    // Roles are guarded, and only the panel's guard can sign
                    // in here; offering another guard's role would create an
                    // account that looks provisioned and is not.
                    ->options(fn (): array => Utils::getRoleModel()::query()
                        ->where('guard_name', 'web')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->helperText('Without a role the account cannot sign in to the panel. What a role may do is defined under Roles.'),
            ])
            ->action(function (array $data): void {
                $user = User::query()->create([
                    'name' => filled($data['name'] ?? null)
                        ? $data['name']
                        : Str::headline(Str::before((string) $data['email'], '@')),
                    'email' => $data['email'],
                    // A placeholder nobody ever learns, including the admin
                    // issuing the invitation. The link below is the only way
                    // this account gets a usable password.
                    'password' => Str::password(64),
                ]);

                // Assigned through Spatie rather than synced onto the bare
                // relation, so the guard check runs and the permission cache
                // is invalidated the way every other grant does it.
                $user->syncRoles(
                    Utils::getRoleModel()::query()->whereKey($data['roles'] ?? [])->get()
                );

                UserResource::passwordSetupNotification('Account created — copy the link now', $user)->send();
            });
    }
}
