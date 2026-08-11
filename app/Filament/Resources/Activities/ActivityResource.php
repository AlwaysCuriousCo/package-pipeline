<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Schemas\ActivityInfolist;
use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use App\Models\Activity;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The audit trail, read-only.
 *
 * One resource rather than a relation manager per record. The question an
 * audit log is opened to answer is almost never "what happened to this
 * package" — it is "what happened around Tuesday", or "what has this account
 * been doing", and both of those cross record boundaries. A relation manager
 * can only answer the first, and would have to be repeated on every audited
 * resource to answer it everywhere.
 *
 * **`ViewAny:Activity` is registry-wide and supersedes package scoping.** A
 * role holding it reads every entry: private package names and their VCS URLs,
 * every account's name and email, every token's name, prefix and abilities, and
 * through the infolist the before-and-after of records the role could not open
 * anywhere else. Grants, team membership and `Unscoped:Package` do not narrow
 * it.
 *
 * That is a decision rather than an oversight. Scoping it would mean answering,
 * per row, "may this viewer see this subject", for a dozen morph types whose
 * visibility rules are unrelated to each other — and for subjects that no
 * longer exist, since a deletion is the single event most worth recording and
 * leaves nothing to run a policy against. A log that quietly dropped those rows
 * would be worse than one that admits its reach: an investigator would be
 * reading a filtered trail believing it complete, and the filtering would be
 * heaviest exactly where the trail matters most.
 *
 * So it is said out loud instead, and said where the decision is made — the
 * Roles screen carries it as a notice, and the README says it under "Roles and
 * permissions". Treat the permission as "may read the whole registry's history"
 * and grant it accordingly.
 *
 * @see AdminPanelProvider for the notice on the Roles screen
 *
 * @extends \Filament\Resources\Resource<Activity>
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    // Beside the users, roles and tokens whose changes it records.
    protected static string|UnitEnum|null $navigationGroup = 'Access Management';

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return ActivityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
    }

    /**
     * Nothing writes here through the panel — no create page, no edit page,
     * and the policy refuses both regardless of how they were reached.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
