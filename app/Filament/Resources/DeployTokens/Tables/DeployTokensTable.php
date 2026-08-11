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
use Illuminate\Database\Eloquent\Builder;

class DeployTokensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // The scope column asks the same two questions of every row —
            // "how many repositories" and "how many packages" — and isScoped()
            // asks them a third and fourth time. Counted in the list query,
            // that is four queries per row traded for two subselects.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['repositories', 'packages']))
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
                    ->state(fn (DeployToken $record): string => self::scoped($record)
                        ? implode(' · ', array_filter([
                            ($count = (int) $record->repositories_count) ? "{$count} ".str('repository')->plural($count) : null,
                            ($count = (int) $record->packages_count) ? "{$count} ".str('package')->plural($count) : null,
                        ]))
                        : 'Whole registry')
                    ->badge()
                    ->color(fn (DeployToken $record): string => self::scoped($record) ? 'gray' : 'warning'),
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
                        $abilities = $record->token->abilities ?? [TokenAbility::RepositoryRead];

                        // One at a time, so each revocation fires the model
                        // events the audit log is written from.
                        $record->tokens->each->delete();

                        $new = Token::issue($record, $record->name, $abilities);

                        DeployTokenResource::plainTextTokenNotification('Token rolled — copy it now', $new)->send();
                    }),
                DeleteAction::make()
                    ->modalHeading('Delete deploy token')
                    ->modalDescription('Its access token stops authenticating immediately.'),
            ]);
    }

    /**
     * DeployToken::isScoped() read off the counts the list query already
     * carries, rather than off two fresh EXISTS queries per row.
     */
    private static function scoped(DeployToken $record): bool
    {
        return (int) $record->repositories_count > 0 || (int) $record->packages_count > 0;
    }
}
