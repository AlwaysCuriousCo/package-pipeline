<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Models\Package;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PackageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('repository')
                    ->label('Repository URL')
                    ->url(fn (Package $record): string => $record->repository)
                    ->openUrlInNewTab()
                    ->color('primary'),
                TextEntry::make('latest_version')
                    ->label('Latest version')
                    ->badge()
                    ->placeholder('Unreleased'),
                TextEntry::make('type')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
