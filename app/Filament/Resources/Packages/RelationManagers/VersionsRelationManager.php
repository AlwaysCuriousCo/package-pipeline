<?php

namespace App\Filament\Resources\Packages\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_dev')
                    ->label('Dev')
                    ->boolean(),
                TextColumn::make('reference')
                    ->label('Commit')
                    ->limit(12)
                    ->copyable(),
                TextColumn::make('updated_at')
                    ->label('Last synced')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_dev')
                    ->label('Dev versions'),
            ]);
    }
}
