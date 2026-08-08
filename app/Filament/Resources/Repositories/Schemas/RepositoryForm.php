<?php

namespace App\Filament\Resources\Repositories\Schemas;

use App\Models\Repository;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RepositoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('Internal packages')
                    ->helperText('A label for this repository; only shown in the admin.'),
                TextInput::make('path')
                    ->label('URL path')
                    ->prefix(url('/r').'/')
                    // The default repository is the one exception: it is
                    // served at the site root and created by the system, so
                    // its (null) path is shown but never edited — retyping it
                    // would orphan every consumer pointed at the root.
                    ->required(fn (?Repository $record): bool => ! $record?->isDefault())
                    ->disabled(fn (?Repository $record): bool => (bool) $record?->isDefault())
                    ->placeholder(fn (?Repository $record): string => $record?->isDefault()
                        ? 'None — served at the registry root'
                        : 'internal')
                    ->maxLength(64)
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->validationMessages([
                        'regex' => 'Only lowercase letters, numbers and hyphens — it becomes part of a URL.',
                    ])
                    ->unique(ignoreRecord: true)
                    ->helperText(fn (?Repository $record): string => $record?->isDefault()
                        ? 'This is the default repository, answering at the registry root.'
                        : 'The repository is served at this path, e.g. /r/internal/packages.json.'),
                Toggle::make('public')
                    ->label('Public')
                    ->helperText('Public repositories are readable without a token. Private ones require an access token that grants them.'),
                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
