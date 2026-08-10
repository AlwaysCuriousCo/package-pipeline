<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Schemas\ActivityInfolist;
use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use App\Models\Activity;
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
