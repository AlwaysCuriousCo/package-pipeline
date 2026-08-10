<?php

namespace App\Filament\Resources\Teams\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('Platform')
                    ->helperText('What this team is — shown wherever access is explained.'),
                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull()
                    ->placeholder('Who belongs here and why they need what the team grants.'),
                Select::make('users')
                    ->label('Members')
                    ->relationship('users', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->columnSpanFull()
                    ->helperText('Everyone here gains the team\'s grants, on top of whatever they already hold. Removing somebody takes back only what this team gave them.'),
                Section::make('Grants')
                    ->description('What every member of this team can see. These are the same grants a user can be given individually — held once, by a name that says why, instead of once per person.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('repositories')
                            ->label('Granted repositories')
                            ->relationship('repositories', 'name')
                            ->multiple()
                            ->preload()
                            ->helperText('Every package in the chosen repositories. Public repositories are visible to everyone already, team or no team.'),
                        Select::make('packages')
                            ->label('Granted packages')
                            ->relationship('packages', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            // The same caveat the user form carries, and for
                            // the same reason: a role holding Unscoped:Package
                            // already sees everything, so a grant adds nothing
                            // and its absence takes nothing away.
                            ->helperText('Individual packages, wherever they are served. Ignored for members whose role can already see everything.'),
                    ]),
            ]);
    }
}
