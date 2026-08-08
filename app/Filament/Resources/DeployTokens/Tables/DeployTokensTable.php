<?php

namespace App\Filament\Resources\DeployTokens\Tables;

use App\Enums\TokenAbility;
use App\Filament\Resources\DeployTokens\DeployTokenResource;
use App\Models\DeployToken;
use App\Models\Token;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeployTokensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('token.token_prefix')
                    ->label('Token')
                    ->formatStateUsing(fn (string $state): string => "{$state}…")
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('Revoked'),
                TextColumn::make('scope')
                    ->state(fn (DeployToken $record): string => $record->isScoped()
                        ? implode(' · ', array_filter([
                            ($count = $record->repositories()->count()) ? "{$count} ".str('repository')->plural($count) : null,
                            ($count = $record->packages()->count()) ? "{$count} ".str('package')->plural($count) : null,
                        ]))
                        : 'Whole registry')
                    ->badge()
                    ->color(fn (DeployToken $record): string => $record->isScoped() ? 'gray' : 'warning'),
                TextColumn::make('token.last_used_at')
                    ->label('Last used')
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                // Rotation straight from the list, keeping whatever abilities
                // the token already has; granting write stays a deliberate
                // act on the edit page.
                Action::make('roll')
                    ->label('Roll')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Roll the access token')
                    ->modalDescription('The current token stops authenticating immediately; whatever machine uses it needs the new one.')
                    ->action(function (DeployToken $record): void {
                        $abilities = $record->token?->abilities ?? [TokenAbility::RepositoryRead];

                        $record->tokens()->delete();

                        $new = Token::issue($record, $record->name, $abilities);

                        DeployTokenResource::plainTextTokenNotification('Token rolled — copy it now', $new)->send();
                    }),
                DeleteAction::make()
                    ->modalHeading('Delete deploy token')
                    ->modalDescription('Its access token stops authenticating immediately.'),
            ]);
    }
}
