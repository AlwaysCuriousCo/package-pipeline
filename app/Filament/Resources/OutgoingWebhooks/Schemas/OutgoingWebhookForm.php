<?php

namespace App\Filament\Resources\OutgoingWebhooks\Schemas;

use App\Enums\WebhookEvent;
use App\Models\OutgoingWebhook;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class OutgoingWebhookForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Deploy pipeline')
                    ->helperText('A label for this endpoint; only shown in the admin.'),
                TextInput::make('url')
                    ->label('Endpoint URL')
                    ->required()
                    ->url()
                    ->maxLength(255)
                    ->placeholder('https://ci.example.com/hooks/registry')
                    ->helperText('Deliveries are POSTed here as JSON. A non-2xx response is retried twice, then recorded as a failure.'),
                self::secret(),
                self::events(),
                Toggle::make('active')
                    ->label('Active')
                    ->helperText('Switched off, nothing is delivered and deliveries already queued are dropped. The URL and secret are kept.'),
                self::lastDelivery(),
            ]);
    }

    /**
     * The shared secret deliveries are signed with.
     *
     * Never rendered back. The column is encrypted, so the panel *could* show
     * it — but a secret on a screen is a secret in a screenshot, and the one
     * question an operator has about it ("is one set?") is answered by the
     * placeholder. Leaving the field empty then keeps whatever is stored rather
     * than clearing it, which is what the conditional dehydration below buys —
     * and it is why rotating a secret is typing a new one, never blanking the
     * field.
     */
    private static function secret(): TextInput
    {
        return TextInput::make('secret')
            ->label('Signing secret')
            ->password()
            ->revealable()
            ->maxLength(255)
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->placeholder(fn (?OutgoingWebhook $record): string => $record?->secret === null
                ? 'None — deliveries are sent unsigned'
                : 'Set. Type a new one to replace it.')
            ->suffixAction(
                Action::make('generate')
                    ->icon('heroicon-m-sparkles')
                    ->label('Generate')
                    ->tooltip('Generate a random secret')
                    ->action(fn (TextInput $component) => $component->state(Str::random(40))),
            )
            ->helperText('Deliveries carry an X-Hub-Signature-256 header: "sha256=" plus an HMAC-SHA256 of the raw body, '
                .'exactly as GitHub signs the deliveries this app receives. Left empty, deliveries are unsigned — only '
                .'reasonable for an endpoint nothing else can reach.');
    }

    /**
     * Which events this endpoint hears about.
     *
     * Required, because an endpoint subscribed to nothing is a row that looks
     * configured and does nothing — the failure mode a webhook page exists to
     * prevent.
     */
    private static function events(): CheckboxList
    {
        $events = WebhookEvent::subscribable();

        return CheckboxList::make('events')
            ->options(array_column(
                array_map(fn (WebhookEvent $event): array => [
                    'value' => $event->value,
                    'label' => $event->getLabel(),
                ], $events),
                'label',
                'value',
            ))
            ->descriptions(array_column(
                array_map(fn (WebhookEvent $event): array => [
                    'value' => $event->value,
                    'description' => $event->getDescription(),
                ], $events),
                'description',
                'value',
            ))
            ->required()
            ->bulkToggleable()
            ->columnSpanFull();
    }

    /**
     * What happened last time, on the page where somebody would change the URL
     * because of it.
     *
     * A section rather than a column on the table alone: the table says "this
     * is red", and the reason a receiver gave is usually the whole answer.
     */
    private static function lastDelivery(): Section
    {
        return Section::make('Last delivery')
            ->visible(fn (?OutgoingWebhook $record): bool => $record?->last_delivered_at !== null)
            ->schema([
                TextInput::make('last_status')
                    ->label('Response status')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('No response — the request never completed'),
                TextInput::make('consecutive_failures')
                    ->label('Consecutive failures')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Reset by any delivery that succeeds.'),
                TextInput::make('last_error')
                    ->label('Reason')
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => filled($get('last_error'))),
            ])
            ->columns(2);
    }
}
