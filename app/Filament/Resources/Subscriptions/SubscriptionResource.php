<?php

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\CreateSubscription;
use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Filament\Resources\Subscriptions\Tables\SubscriptionsTable;
use App\Models\Subscription;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Subscriptions: who has paid access, through which plan, in what state.
 *
 * Merchant-billed rows are projections the webhooks and the reconciler keep
 * true — the panel reads them and acts on them (suspend, cancel) but never
 * edits their clocks by hand. What *is* created here is the Manual
 * subscription: a comp, a wire transfer, a PO, recorded first-class so the
 * projector treats it exactly like paid ones.
 *
 * @extends \Filament\Resources\Resource<Subscription>
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return SubscriptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubscriptionsTable::configure($table);
    }

    /**
     * The whole Billing group stays out of the sidebar until billing is
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
            'index' => ListSubscriptions::route('/'),
            'create' => CreateSubscription::route('/create'),
            'edit' => EditSubscription::route('/{record}/edit'),
        ];
    }
}
