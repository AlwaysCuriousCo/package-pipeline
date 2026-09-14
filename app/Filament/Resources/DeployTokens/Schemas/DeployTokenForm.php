<?php

namespace App\Filament\Resources\DeployTokens\Schemas;

use App\Enums\TokenAbility;
use App\Models\DeployToken;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

class DeployTokenForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::credential(),
                self::identity(),
                self::scope(),
            ]);
    }

    /**
     * What the machine actually presents — the half of this page that is read
     * rather than filled in, and the half somebody opens the record for: which
     * credential is live, what it may do, and whether anything is still using
     * it.
     *
     * Nothing here is editable: the secret is gone, and the rest changes by
     * regenerating. Absent on create, where there is no credential yet.
     */
    private static function credential(): Section
    {
        return Section::make('Credential')
            ->description('Issued once and never shown again. Regenerate from the header to replace it.')
            ->icon(Heroicon::OutlinedKey)
            ->hiddenOn('create')
            ->columns(4)
            ->columnSpanFull()
            ->schema([
                TextEntry::make('token.token_prefix')
                    ->label('Token')
                    ->formatStateUsing(fn (string $state): string => "{$state}…")
                    ->fontFamily(FontFamily::Mono)
                    ->badge()
                    ->color('gray')
                    ->placeholder('Revoked — regenerate to issue a new one'),
                TextEntry::make('username')
                    ->label('auth.json username')
                    ->state(fn (DeployToken $record): ?string => $record->token?->composerUsername())
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->helperText('Cosmetic: only the token authenticates.'),
                TextEntry::make('token.abilities')
                    ->label('Abilities')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TokenAbility::tryFrom($state)?->getLabel() ?? $state)
                    ->placeholder('—'),
                // A state rather than a placeholder, so "never used" arrives
                // as the badge it deserves: an unused credential is the one
                // worth deleting.
                TextEntry::make('last_used')
                    ->label('Last used')
                    ->state(fn (DeployToken $record): string => $record->token?->last_used_at?->diffForHumans() ?? 'Never used')
                    ->badge()
                    ->color(fn (DeployToken $record): string => $record->token?->last_used_at === null ? 'warning' : 'success')
                    ->icon(Heroicon::OutlinedSignal)
                    ->helperText('Recorded at most once a minute.'),
            ]);
    }

    private static function identity(): Section
    {
        return Section::make('Identity')
            ->description('Which machine or pipeline this credential belongs to.')
            ->icon(Heroicon::OutlinedServerStack)
            ->columnSpanFull()
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('production-deploys')
                    ->helperText('Shown in listings and in the audit log — name the machine, not the person.'),
            ]);
    }

    /**
     * What the credential reaches.
     *
     * The heading carries the warning rather than a helper text under the last
     * field, because the dangerous state here is the empty one: a deploy token
     * with no grants sees the whole registry, so *removing* its last grant is
     * what widens it.
     */
    private static function scope(): Section
    {
        return Section::make('Scope')
            ->description('Leave both empty and this credential reaches every package in the registry.')
            ->icon(Heroicon::OutlinedLockClosed)
            ->columnSpanFull()
            ->afterHeader([
                TextEntry::make('scope_summary')
                    ->hiddenLabel()
                    ->badge()
                    ->state(fn (Get $get): string => self::summary($get))
                    ->color(fn (Get $get): string => self::scoped($get) ? 'success' : 'warning')
                    ->icon(fn (Get $get): Heroicon => self::scoped($get)
                        ? Heroicon::OutlinedLockClosed
                        : Heroicon::OutlinedGlobeAlt),
            ])
            ->schema([
                Select::make('repositories')
                    ->relationship('repositories', 'name')
                    ->multiple()
                    ->preload()
                    ->live()
                    ->helperText('Grants every package in the chosen repositories, including ones published later.'),
                Select::make('packages')
                    ->relationship('packages', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->helperText('Grants individual packages, wherever they are served.'),
            ]);
    }

    /**
     * The scope badge, read off the form state rather than the record so it
     * follows what is on screen before anything is saved.
     */
    private static function summary(Get $get): string
    {
        $repositories = count((array) $get('repositories'));
        $packages = count((array) $get('packages'));

        if ($repositories === 0 && $packages === 0) {
            return 'Whole registry';
        }

        return implode(' · ', array_filter([
            $repositories ? "{$repositories} ".str('repository')->plural($repositories) : null,
            $packages ? "{$packages} ".str('package')->plural($packages) : null,
        ]));
    }

    private static function scoped(Get $get): bool
    {
        return count((array) $get('repositories')) > 0 || count((array) $get('packages')) > 0;
    }
}
