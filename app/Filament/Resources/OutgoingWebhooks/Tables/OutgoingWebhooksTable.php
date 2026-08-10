<?php

namespace App\Filament\Resources\OutgoingWebhooks\Tables;

use App\Enums\WebhookEvent;
use App\Filament\Resources\OutgoingWebhooks\Actions\SendTestDeliveryAction;
use App\Models\OutgoingWebhook;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutgoingWebhooksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn (OutgoingWebhook $record): string => (string) $record->url),
                TextColumn::make('events')
                    ->badge()
                    ->color('gray')
                    // The column is a JSON array of wire values; the label is
                    // what an operator chose in the form.
                    ->formatStateUsing(fn (string $state): string => WebhookEvent::tryFrom($state)?->getLabel() ?? $state),
                IconColumn::make('secret')
                    ->label('Signed')
                    ->boolean()
                    ->tooltip(fn (OutgoingWebhook $record): string => $record->secret === null
                        ? 'Deliveries are sent unsigned'
                        : 'Deliveries carry an X-Hub-Signature-256 header'),
                IconColumn::make('active')
                    ->boolean(),
                self::health(),
                TextColumn::make('last_delivered_at')
                    ->label('Last attempt')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('events')
                    ->label('Event')
                    ->options(array_column(
                        array_map(fn (WebhookEvent $event): array => [
                            'value' => $event->value,
                            'label' => $event->getLabel(),
                        ], WebhookEvent::subscribable()),
                        'label',
                        'value',
                    ))
                    // The events are a JSON array, and the three databases here
                    // spell "contains" three different ways; whereJsonContains
                    // is Laravel's one spelling of it. Unlike the fan-out — see
                    // OutgoingWebhook::listeningFor — this one may fail on an
                    // exotic driver without anything being missed.
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query) => $query->whereJsonContains('events', $data['value']),
                    )),
                Filter::make('failing')
                    ->label('Failing')
                    ->query(self::failing(...)),
            ])
            ->recordActions([
                SendTestDeliveryAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * The model's own definition of failing, reached through a signature that
     * names the model — the closure Filament hands a filter is typed only as a
     * builder, which is not enough for the scope to resolve.
     *
     * @param  Builder<OutgoingWebhook>  $query
     * @return Builder<OutgoingWebhook>
     */
    private static function failing(Builder $query): Builder
    {
        return $query->failing();
    }

    /**
     * Whether the endpoint is working, which is the column this page exists for.
     *
     * Three states and not two: an endpoint nothing has happened to yet is not
     * healthy and is certainly not broken, and colouring it either way would
     * have an operator either trust or chase a thing that has never been tried.
     */
    private static function health(): TextColumn
    {
        return TextColumn::make('consecutive_failures')
            ->label('Health')
            ->badge()
            ->color(fn (OutgoingWebhook $record): string => match ($record->healthy()) {
                true => 'success',
                false => 'danger',
                null => 'gray',
            })
            ->formatStateUsing(fn (int $state, OutgoingWebhook $record): string => match ($record->healthy()) {
                true => 'Delivering',
                false => $state === 1 ? '1 failure' : "{$state} failures",
                null => 'Untried',
            })
            ->tooltip(fn (OutgoingWebhook $record): ?string => $record->last_error)
            ->sortable();
    }
}
