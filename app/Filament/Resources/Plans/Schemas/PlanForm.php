<?php

namespace App\Filament\Resources\Plans\Schemas;

use App\Enums\BillingInterval;
use App\Enums\BillingModel;
use App\Enums\CancellationTiming;
use App\Enums\LapseBehaviour;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Pro')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, callable $set, ?string $state): void {
                        if (! $get('slug')) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->rule('alpha_dash')
                    ->helperText('The plan\'s URL on the pricing page, and the handle it is synced to merchants under. Renaming after launch breaks shared links.'),
                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull()
                    ->placeholder('What buying this plan gets a customer.'),
                Toggle::make('active')
                    ->default(true)
                    ->helperText('Whether the plan can be bought at all. Existing subscriptions are unaffected either way.'),
                Toggle::make('listed')
                    ->helperText('Show on the public pricing page. An unlisted plan is still sellable by direct link — launch offers, negotiated tiers.'),

                Section::make('Billing')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('billing_model')
                            ->options(BillingModel::class)
                            ->default(BillingModel::Recurring)
                            ->required()
                            ->live()
                            ->helperText('How the plan charges — and what "it ended" means.'),
                        TextInput::make('trial_days')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => $get('billing_model') === BillingModel::Recurring || $get('billing_model') === BillingModel::Recurring->value)
                            ->helperText('Days before the first charge. 0 is no trial.'),
                        TextInput::make('updates_window_months')
                            ->numeric()
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => $get('billing_model') === BillingModel::OneTimeWithUpdates || $get('billing_model') === BillingModel::OneTimeWithUpdates->value)
                            ->requiredIf('billing_model', BillingModel::OneTimeWithUpdates->value)
                            ->helperText('Months of new releases a purchase includes. What exists when the window closes is the customer\'s forever.'),
                        Repeater::make('prices')
                            ->relationship()
                            ->columnSpanFull()
                            ->columns(4)
                            ->defaultItems(1)
                            ->schema([
                                TextInput::make('currency')
                                    ->default(fn (): string => (string) config('registry.billing.currency'))
                                    ->required()
                                    ->maxLength(3)
                                    ->helperText('ISO 4217, e.g. usd.'),
                                TextInput::make('amount')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->helperText('Minor units: 1999 is $19.99.'),
                                Select::make('interval')
                                    ->options(BillingInterval::class)
                                    ->default(BillingInterval::Month)
                                    ->required(),
                                Toggle::make('active')
                                    ->default(true)
                                    ->inline(false),
                            ])
                            ->helperText('Retiring a price stops offering it; subscriptions already on it keep being charged under it.'),
                    ]),

                Section::make('Entitlements')
                    ->description('What a subscription to this plan grants — the same two shapes a grant has always had, projected onto every subscriber for as long as they are paid up.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('repositories')
                            ->label('Granted repositories')
                            ->relationship('repositories', 'name', self::visibleRepositories(...))
                            ->multiple()
                            ->preload()
                            ->helperText('Every package in the chosen repositories, present and future.'),
                        Select::make('packages')
                            ->label('Granted packages')
                            ->relationship('packages', 'name', self::visiblePackages(...))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->helperText('Individual packages, wherever they are served from.'),
                    ]),

                Section::make('Lifecycle')
                    ->description('What happens when a subscription to this plan stops being paid — each plan decides for itself.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('lapse_behaviour')
                            ->options(LapseBehaviour::class)
                            ->default(LapseBehaviour::WithdrawEntitlement)
                            ->required()
                            ->helperText('Freeze-at-version is the perpetual-licence shape, and the implied choice for a one-time purchase.'),
                        TextInput::make('grace_days')
                            ->numeric()
                            ->minValue(0)
                            ->placeholder('Follow the merchant')
                            ->helperText('Days of continued access after the merchant gives up collecting, before the lapse behaviour runs. Empty follows the merchant\'s own dunning alone.'),
                        Select::make('cancellation')
                            ->options(CancellationTiming::class)
                            ->default(CancellationTiming::Immediate)
                            ->required()
                            ->helperText('When the customer\'s own cancellation takes access away. End-of-period is the industry norm.'),
                        TextInput::make('token_limit')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Unlimited')
                            ->helperText('How many access tokens a subscription may hold at once. The auto-issued one counts.'),
                        Toggle::make('auto_issue_token')
                            ->default(true)
                            ->label('Auto-issue a token on activation')
                            ->helperText('Mint a read token the moment the purchase completes, shown once, so the buyer leaves checkout with a working credential.'),
                        TextInput::make('sort')
                            ->numeric()
                            ->default(0)
                            ->helperText('Position on the pricing page, lowest first.'),
                    ]),
            ]);
    }

    /**
     * Scoped for the reason TeamForm scopes its pickers: an unscoped,
     * preloaded picker is a list of every private repository and package
     * name, handed to anybody holding Create:Plan.
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
