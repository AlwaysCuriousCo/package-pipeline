<?php

namespace App\Filament\Resources\Teams;

use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Schemas\TeamForm;
use App\Filament\Resources\Teams\Tables\TeamsTable;
use App\Models\Team;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Teams: grants held once, by a name that says why.
 *
 * Filed with Users and Roles rather than with Packages, because what this
 * screen decides is who can reach what — the same question those two answer,
 * from the other two directions.
 *
 * Which makes Create:Team and Update:Team permissions to hand out like
 * Unscoped:Package rather than like Update:Package. Whoever holds them can put
 * anything they can see into a team and put themselves in it, so the two are
 * not "may administer teams" so much as "may grant themselves what they can
 * already reach". The form's pickers are scoped to their holder's own sight,
 * which is what keeps *what they can see* from being the whole registry — but
 * nothing below turns the grant itself into a smaller thing than it is.
 *
 * Spelled out rather than imported, as PackageResource is: Pint's phpdoc_types
 * fixer reads a bare `Resource` as PHP's `resource` pseudo-type.
 *
 * @extends \Filament\Resources\Resource<Team>
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Access Management';

    // Between Users (10) and the Roles screen the Shield plugin registers,
    // so the group reads people, then the groups they are in, then what a
    // role lets any of them do.
    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return TeamForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'create' => CreateTeam::route('/create'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];
    }
}
