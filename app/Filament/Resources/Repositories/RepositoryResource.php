<?php

namespace App\Filament\Resources\Repositories;

use App\Filament\Resources\Repositories\Pages\CreateRepository;
use App\Filament\Resources\Repositories\Pages\EditRepository;
use App\Filament\Resources\Repositories\Pages\ListRepositories;
use App\Filament\Resources\Repositories\Schemas\RepositoryForm;
use App\Filament\Resources\Repositories\Tables\RepositoriesTable;
use App\Models\Repository;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RepositoryResource extends Resource
{
    protected static ?string $model = Repository::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $recordTitleAttribute = 'name';

    // "Repository" alone would read as the VCS repository packages sync from;
    // these are the registries Composer consumes.
    protected static ?string $navigationLabel = 'Composer repositories';

    protected static ?string $modelLabel = 'Composer repository';

    protected static ?string $pluralModelLabel = 'Composer repositories';

    public static function form(Schema $schema): Schema
    {
        return RepositoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RepositoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepositories::route('/'),
            'create' => CreateRepository::route('/create'),
            'edit' => EditRepository::route('/{record}/edit'),
        ];
    }
}
