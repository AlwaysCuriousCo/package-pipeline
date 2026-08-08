<?php

namespace App\Filament\Resources\DeployTokens;

use App\Filament\Resources\DeployTokens\Pages\CreateDeployToken;
use App\Filament\Resources\DeployTokens\Pages\EditDeployToken;
use App\Filament\Resources\DeployTokens\Pages\ListDeployTokens;
use App\Filament\Resources\DeployTokens\Schemas\DeployTokenForm;
use App\Filament\Resources\DeployTokens\Tables\DeployTokensTable;
use App\Models\DeployToken;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DeployTokenResource extends Resource
{
    protected static ?string $model = DeployToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $recordTitleAttribute = 'name';

    // Machine credentials sit with the other access management concerns,
    // alongside Shield's roles and the users who hold them.
    protected static string|UnitEnum|null $navigationGroup = 'Access Management';

    public static function form(Schema $schema): Schema
    {
        return DeployTokenForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeployTokensTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeployTokens::route('/'),
            'create' => CreateDeployToken::route('/create'),
            'edit' => EditDeployToken::route('/{record}/edit'),
        ];
    }
}
