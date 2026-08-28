<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\AccessTokens\AccessTokenResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's personal tokens, for the admin who needs to revoke one without
 * deleting the person. Revoke only: issuing happens on the user's own API
 * tokens page, so nobody ever holds a credential minted in their name by
 * someone else.
 *
 * Gated by the right to edit the user rather than by a token permission —
 * whoever may change an account may pull its credentials.
 */
class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'API tokens';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('update', $ownerRecord) ?? false;
    }

    protected function canDelete(Model $record): bool
    {
        return static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns(AccessTokenResource::tokenColumns())
            ->recordActions([
                AccessTokenResource::revokeAction(),
            ])
            ->emptyStateHeading('No API tokens')
            ->emptyStateDescription('This user has issued none from their API tokens page.');
    }
}
