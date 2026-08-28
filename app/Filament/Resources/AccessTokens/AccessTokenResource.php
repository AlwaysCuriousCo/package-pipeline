<?php

namespace App\Filament\Resources\AccessTokens;

use App\Enums\TokenAbility;
use App\Filament\Resources\AccessTokens\Pages\ListAccessTokens;
use App\Filament\Resources\DeployTokens\DeployTokenResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\DeployToken;
use App\Models\Token;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Every access token in the registry, whoever it was issued to.
 *
 * Personal tokens are self-service on the API tokens page and deploy tokens
 * have their own resource; this is the view across both, for the question
 * neither answers: "whose token is pp_a1b2c3…, and kill it". Revoke-only —
 * issuing a token happens where its principal is managed, so a credential is
 * never minted *as* someone by somebody else.
 */
class AccessTokenResource extends Resource
{
    protected static ?string $model = Token::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'access token';

    protected static ?string $navigationLabel = 'Access tokens';

    protected static string|UnitEnum|null $navigationGroup = 'Access Management';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('tokenable'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('tokenable.name')
                    ->label('Principal')
                    ->description(fn (Token $record): string => $record->tokenable_type === DeployToken::class ? 'Deploy token' : 'User')
                    ->url(fn (Token $record): ?string => match (true) {
                        $record->tokenable instanceof DeployToken => DeployTokenResource::getUrl('edit', ['record' => $record->tokenable]),
                        $record->tokenable instanceof User => UserResource::getUrl('edit', ['record' => $record->tokenable]),
                        default => null,
                    }),
                ...self::tokenColumns(),
                TextColumn::make('deleted_at')
                    ->label('Revoked')
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tokenable_type')
                    ->label('Principal type')
                    ->options([User::class => 'User', DeployToken::class => 'Deploy token']),
                TrashedFilter::make()->label('Revoked'),
            ])
            ->recordActions([
                self::revokeAction(),
            ])
            ->emptyStateHeading('No access tokens')
            ->emptyStateDescription('Personal tokens are issued from the user menu; deploy tokens from their own list.');
    }

    /**
     * The columns that describe a token wherever one is listed — here, on the
     * personal API tokens page, and on a user's edit page.
     *
     * @return list<TextColumn>
     */
    public static function tokenColumns(): array
    {
        return [
            TextColumn::make('name')
                ->searchable(),
            TextColumn::make('token_prefix')
                ->label('Token')
                ->formatStateUsing(fn (string $state): string => "{$state}…")
                ->fontFamily(FontFamily::Mono)
                ->searchable(),
            TextColumn::make('abilities')
                ->badge()
                ->formatStateUsing(fn (string $state): string => TokenAbility::tryFrom($state)?->getLabel() ?? $state),
            TextColumn::make('last_used_at')
                ->label('Last used')
                ->since()
                ->placeholder('Never'),
            TextColumn::make('expires_at')
                ->label('Expires')
                ->date()
                ->placeholder('Never'),
            TextColumn::make('created_at')
                ->label('Created')
                ->date(),
        ];
    }

    public static function revokeAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Revoke')
            ->modalHeading('Revoke token')
            ->modalDescription('Composer clients using this token stop authenticating immediately.')
            ->successNotificationTitle('Token revoked');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessTokens::route('/'),
        ];
    }
}
