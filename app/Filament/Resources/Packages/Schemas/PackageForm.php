<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Models\Package;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('vendor/package'),
                TextInput::make('repository')
                    ->label('Repository URL')
                    ->required()
                    ->url()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('https://github.com/vendor/package'),
                TextInput::make('latest_version')
                    ->maxLength(255)
                    ->placeholder('v1.0.0')
                    ->helperText('Leave empty if the package has no release yet.'),
                TextInput::make('type')
                    ->maxLength(255)
                    // Suggests the types already in use without locking the
                    // column to a fixed vocabulary.
                    ->datalist(fn (): array => array_values(Package::types())),
                Textarea::make('description')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}
