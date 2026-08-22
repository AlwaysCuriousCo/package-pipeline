<?php

namespace App\Filament\Resources\Subscriptions\Actions;

use App\Models\Subscription;
use App\Services\Billing\EntitlementProjector;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

/**
 * The administrative hard stop, and its reversal.
 *
 * Suspension withholds access without touching the billing relationship —
 * the merchant keeps charging unless somebody also cancels — and it bypasses
 * the plan's lapse behaviour on purpose: it is for abuse, licence sharing
 * and disputes, where a frozen-ceiling keepsake would defeat the act.
 */
class SuspendSubscriptionAction
{
    public static function make(): Action
    {
        return Action::make('suspend')
            ->label(fn (Subscription $record): string => $record->suspended_at === null ? 'Suspend' : 'Unsuspend')
            ->icon('heroicon-o-no-symbol')
            ->color(fn (Subscription $record): string => $record->suspended_at === null ? 'danger' : 'success')
            ->schema(fn (Subscription $record): array => $record->suspended_at === null ? [
                TextInput::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Shown to other administrators, and kept in the audit log.'),
            ] : [])
            ->requiresConfirmation()
            ->modalDescription(fn (Subscription $record): string => $record->suspended_at === null
                ? 'Access is withdrawn immediately, whatever the plan\'s lapse behaviour says. Billing at the merchant continues until cancelled.'
                : 'Access is restored immediately.')
            ->action(function (Subscription $record, array $data): void {
                $record->forceFill($record->suspended_at === null
                    ? ['suspended_at' => now(), 'suspension_reason' => $data['reason']]
                    : ['suspended_at' => null, 'suspension_reason' => null])->save();

                app(EntitlementProjector::class)->project($record);
            });
    }
}
