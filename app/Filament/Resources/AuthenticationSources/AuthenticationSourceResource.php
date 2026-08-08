<?php

namespace App\Filament\Resources\AuthenticationSources;

use App\Filament\Resources\AuthenticationSources\Pages\CreateAuthenticationSource;
use App\Filament\Resources\AuthenticationSources\Pages\EditAuthenticationSource;
use App\Filament\Resources\AuthenticationSources\Pages\ListAuthenticationSources;
use App\Filament\Resources\AuthenticationSources\Schemas\AuthenticationSourceForm;
use App\Filament\Resources\AuthenticationSources\Tables\AuthenticationSourcesTable;
use App\Models\AuthenticationSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AuthenticationSourceResource extends Resource
{
    protected static ?string $model = AuthenticationSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Login providers';

    protected static ?string $modelLabel = 'login provider';

    protected static string|UnitEnum|null $navigationGroup = 'Access Management';

    public static function form(Schema $schema): Schema
    {
        return AuthenticationSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AuthenticationSourcesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuthenticationSources::route('/'),
            'create' => CreateAuthenticationSource::route('/create'),
            'edit' => EditAuthenticationSource::route('/{record}/edit'),
        ];
    }
}
