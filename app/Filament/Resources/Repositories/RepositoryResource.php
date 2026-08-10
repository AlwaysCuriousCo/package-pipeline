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

/**
 * The registry's own mounts, configured by whoever runs it.
 *
 * Deliberately not row-scoped, unlike the package resource next to it. A grant
 * says which packages somebody may *read*; it says nothing about who
 * administers the registry, and this screen is administration — mount paths,
 * the public flag, reserved vendors, upstream mirrors and their credentials.
 * The gate is ViewAny:Repository, a permission a read-only role does not carry
 * and one that means "configures the registry" when it is given out. Scoping on
 * top of it would answer the wrong question with the wrong tool and hide from
 * an operator the mounts they are here to look after — which is also why
 * PackageForm's repository picker offers every mount rather than the author's.
 *
 * Repository::scopeVisibleToUser() belongs to the serving surfaces instead:
 * /api/v1/repositories and the Composer endpoints, where a listing is a
 * directory of what exists shown to a credential rather than a control panel.
 */
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
