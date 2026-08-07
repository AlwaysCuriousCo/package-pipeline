<?php

namespace App\Filament\Resources\Sources;

use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\Pages\ViewSource;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Filament\Resources\Sources\Schemas\SourceInfolist;
use App\Filament\Resources\Sources\Tables\SourcesTable;
use App\Models\Source;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?string $recordTitleAttribute = 'name';

    // Sources are what packages are pulled from, so they read first.
    protected static ?int $navigationSort = -1;

    public static function form(Schema $schema): Schema
    {
        return SourceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SourceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PackagesRelationManager::class,
        ];
    }

    /**
     * Surfaces a source that has stopped authenticating without the admin
     * having to open the list.
     */
    public static function getNavigationBadge(): ?string
    {
        $failing = static::getModel()::query()->whereNull('connected_at')->count();

        return $failing === 0 ? null : (string) $failing;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSources::route('/'),
            'create' => CreateSource::route('/create'),
            'view' => ViewSource::route('/{record}'),
            'edit' => EditSource::route('/{record}/edit'),
        ];
    }
}
