<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('No role — cannot sign in'),
                TextColumn::make('teams.name')
                    ->label('Teams')
                    ->badge()
                    ->color('gray')
                    ->placeholder('None')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->preload(),
                SelectFilter::make('teams')
                    ->relationship('teams', 'name')
                    ->preload(),
            ])
            // Keeps "select all + delete" from taking your own account along.
            ->checkIfRecordIsSelectableUsing(fn (User $record): bool => ! $record->is(auth()->user()))
            ->recordActions([
                // The invitation's other half: a setup link lives five
                // minutes, so the one issued at creation is routinely stale
                // by the time it is pasted anywhere. This re-issues it, and
                // doubles as the recovery path `user:reset-password` offers
                // on the console.
                Action::make('setupLink')
                    ->label('Password link')
                    ->icon(Heroicon::OutlinedLink)
                    ->color('gray')
                    ->authorize(fn (User $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Issue a password setup link')
                    ->modalDescription('Any setup or reset link issued for this account earlier stops working. The account\'s current password, if it has one, keeps working until the link is used.')
                    ->modalSubmitActionLabel('Issue the link')
                    ->action(fn (User $record) => UserResource::passwordSetupNotification(
                        'Link issued — copy it now',
                        $record,
                    )->send()),
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (User $record): bool => $record->is(auth()->user())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
