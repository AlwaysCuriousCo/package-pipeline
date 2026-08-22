<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Plans\Schemas\PlanForm;
use App\Filament\Resources\Plans\Tables\PlansTable;
use App\Models\Plan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Plans: what the registry sells, and every rule a subscription lives by.
 *
 * A plan bundles entitlements the way a team bundles grants, so Create:Plan
 * and Update:Plan are powers of the same weight as Create:Team — whoever
 * holds them decides what money buys. The entitlement pickers are scoped to
 * the editor's own sight for exactly the reason TeamForm's are.
 *
 * @extends \Filament\Resources\Resource<Plan>
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    /**
     * The whole Commercial group stays out of the sidebar until billing is
     * turned on: a registry that has not enabled it shows exactly the panel
     * it always showed. The pages still answer by URL for administrators
     * setting things up ahead of the switch.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('registry.billing.enabled');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
