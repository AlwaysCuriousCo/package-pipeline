<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    // The model's `hashed` cast does the hashing; an empty
                    // input on edit keeps the current password.
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->placeholder(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Leave empty to keep the current password'
                        : null),
                Select::make('roles')
                    // Roles are guarded; syncing through the bare relation
                    // skips Spatie's guard check, so offer only matching ones.
                    ->relationship('roles', 'name', fn (Builder $query): Builder => $query->where('guard_name', 'web'))
                    ->multiple()
                    ->preload()
                    // Removing your own super_admin role is a lockout with
                    // `admin:create` as the only way back; a disabled select
                    // also skips the relationship sync on save.
                    ->disabled(fn (?User $record): bool => $record !== null && $record->is(auth()->user()))
                    ->helperText(fn (?User $record): string => $record !== null && $record->is(auth()->user())
                        ? 'You cannot change your own roles — ask another admin.'
                        : 'Without a role the account cannot sign in to the panel. What a role may do is defined under Roles.'),
                Select::make('repositories')
                    ->label('Granted repositories')
                    ->relationship('repositories', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText('Every package in the chosen repositories, for accounts whose role lacks Unscoped:Package. Public repositories are visible to everyone.'),
                Select::make('packages')
                    ->label('Granted packages')
                    ->relationship('packages', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Individual packages, wherever they are served. Ignored for accounts that can already see everything.'),
            ]);
    }
}
