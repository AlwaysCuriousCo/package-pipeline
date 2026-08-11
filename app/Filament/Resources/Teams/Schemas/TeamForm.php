<?php

namespace App\Filament\Resources\Teams\Schemas;

use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                            ->relationship('repositories', 'name', self::visibleRepositories(...))
                            ->multiple()
                            ->preload()
                            ->helperText('Every package in the chosen repositories. Public repositories are visible to everyone already, team or no team.'),
                        Select::make('packages')
                            ->label('Granted packages')
                            ->relationship('packages', 'name', self::visiblePackages(...))
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

    /**
     * The grants on offer: what the person filling the form in can already see,
     * and nothing else.
     *
     * Managing teams would otherwise be a way to read the registry. An
     * unscoped, preloaded picker is a list of every private repository and
     * every package name in the installation, handed to anybody holding
     * Create:Team — who could then grant themselves all of it. Scoped, the
     * screen offers what its author could reach anyway and enumerates nothing.
     *
     * A grant the author cannot see survives their edit: Filament works out
     * what to detach from the same scoped query it filled the field from, and
     * syncs without detaching. So a team may hold more than this screen shows,
     * which is the right way round — the alternative is a scoped editor
     * silently revoking what an unscoped one granted.
     *
     * @param  Builder<Repository>  $query
     * @return Builder<Repository>
     */
    private static function visibleRepositories(Builder $query): Builder
    {
        return $query->visibleToUser(self::actingUser());
    }

    /**
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     *
     * @see visibleRepositories() for why both are scoped
     */
    private static function visiblePackages(Builder $query): Builder
    {
        return $query->visibleToUser(self::actingUser());
    }

    private static function actingUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
