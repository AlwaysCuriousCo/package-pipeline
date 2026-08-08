<?php

namespace App\Filament\Resources\DeployTokens\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DeployTokenForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('production-deploys')
                    ->helperText('Which machine or pipeline this token belongs to.'),
                Select::make('repositories')
                    ->relationship('repositories', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText('Grants every package in the chosen repositories.'),
                Select::make('packages')
                    ->relationship('packages', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Grants individual packages, wherever they are served. Leave both empty to grant the whole registry.'),
            ]);
    }
}
