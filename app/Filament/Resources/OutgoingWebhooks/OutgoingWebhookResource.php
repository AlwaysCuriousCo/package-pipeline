<?php

namespace App\Filament\Resources\OutgoingWebhooks;

use App\Filament\Resources\OutgoingWebhooks\Pages\CreateOutgoingWebhook;
use App\Filament\Resources\OutgoingWebhooks\Pages\EditOutgoingWebhook;
use App\Filament\Resources\OutgoingWebhooks\Pages\ListOutgoingWebhooks;
use App\Filament\Resources\OutgoingWebhooks\Schemas\OutgoingWebhookForm;
use App\Filament\Resources\OutgoingWebhooks\Tables\OutgoingWebhooksTable;
use App\Models\OutgoingWebhook;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OutgoingWebhookResource extends Resource
{
    protected static ?string $model = OutgoingWebhook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * "Webhooks" alone would be read as the ones this app receives, which are
     * configured on a package and are the more familiar half of the word here.
     */
    protected static ?string $navigationLabel = 'Outgoing webhooks';

    protected static ?string $modelLabel = 'outgoing webhook';

    // After the things it reports on.
    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return OutgoingWebhookForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OutgoingWebhooksTable::configure($table);
    }

    /**
     * A badge only when something is wrong, which is the only time an operator
     * has any reason to open this page. An endpoint that has been failing is
     * invisible otherwise: its deliveries are queued, swallowed and logged, and
     * nothing else in the panel would ever mention it.
     */
    public static function getNavigationBadge(): ?string
    {
        $failing = OutgoingWebhook::query()->where('active', true)->failing()->count();

        return $failing === 0 ? null : (string) $failing;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutgoingWebhooks::route('/'),
            'create' => CreateOutgoingWebhook::route('/create'),
            'edit' => EditOutgoingWebhook::route('/{record}/edit'),
        ];
    }
}
