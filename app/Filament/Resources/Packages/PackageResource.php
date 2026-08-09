<?php

namespace App\Filament\Resources\Packages;

use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Filament\Resources\Packages\Schemas\PackageForm;
use App\Filament\Resources\Packages\Schemas\PackageInfolist;
use App\Filament\Resources\Packages\Tables\PackagesTable;
use App\Models\Package;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Spelled out rather than imported: Pint's phpdoc_types fixer reads a bare
 * `Resource` as PHP's `resource` pseudo-type and lowercases it.
 *
 * @extends \Filament\Resources\Resource<Package>
 */
class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Row-level scoping for users without Unscoped:Package: the table, and
     * every page that resolves a record through it, only reaches packages
     * the signed-in user has been granted.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleToUser(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return PackageForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PackageInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PackagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackages::route('/'),
            'create' => CreatePackage::route('/create'),
            'view' => ViewPackage::route('/{record}'),
            'edit' => EditPackage::route('/{record}/edit'),
        ];
    }
}
