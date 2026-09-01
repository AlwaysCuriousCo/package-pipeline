<?php

namespace App\Filament\Resources\Repositories\Schemas;

use App\Enums\Ecosystem;
use App\Models\Repository;
use App\Models\ReservedVendor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

class RepositoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('Internal packages')
                    ->helperText('A label for this repository; only shown in the admin.'),
                TextInput::make('path')
                    ->label('URL path')
                    ->prefix(url('/r').'/')
                    // The default repository is the one exception: it is
                    // served at the site root and created by the system, so
                    // its (null) path is shown but never edited — retyping it
                    // would orphan every consumer pointed at the root.
                    ->required(fn (?Repository $record): bool => ! $record?->isDefault())
                    ->disabled(fn (?Repository $record): bool => (bool) $record?->isDefault())
                    ->placeholder(fn (?Repository $record): string => $record?->isDefault()
                        ? 'None — served at the registry root'
                        : 'internal')
                    ->maxLength(64)
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->validationMessages([
                        'regex' => 'Only lowercase letters, numbers and hyphens — it becomes part of a URL.',
                    ])
                    ->unique(ignoreRecord: true)
                    ->helperText(fn (?Repository $record): string => $record?->isDefault()
                        ? 'This is the default repository, answering at the registry root.'
                        : 'The repository is served at this path, e.g. /r/internal/packages.json.'),
                Toggle::make('public')
                    ->label('Public')
                    ->helperText('Public repositories are readable without a token. Private ones require an access token that grants them.'),
                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
                self::page(),
                self::reservedVendors(),
                self::upstreams(),
            ]);
    }

    /**
     * The repository's own landing page, served at the URL its Composer
     * endpoints hang off — which for the default repository is the site root.
     *
     * The page an operator wants when they hand somebody a registry URL: what
     * this is, the one line that points a project at it, and which packages
     * it publishes pages for.
     */
    public static function page(): Section
    {
        return Section::make('Public page')
            ->description(fn (?Repository $record): string => $record?->isDefault()
                ? 'Served at the registry root, in place of the redirect to the admin login.'
                : 'Served at this repository\'s own URL, the one projects are pointed at.')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->columnSpanFull()
            ->schema([
                Toggle::make('page_enabled')
                    ->label('Publish a landing page')
                    ->live()
                    ->helperText(fn (?Repository $record): string => $record?->isDefault()
                        ? 'Off, the registry root goes on redirecting to the admin login, exactly as it does today.'
                        : 'Off, this URL answers 404 to anything but the Composer endpoints under it.'),
                Toggle::make('page_lists_packages')
                    ->label('List the packages served here')
                    // On by default, as the column is: a landing page that
                    // names nothing is the rarer want, and a hidden toggle
                    // left unset would otherwise save as off.
                    ->default(true)
                    ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
                    // Only packages that publish a page of their own are ever
                    // listed, whatever this says — naming a package the
                    // registry will not describe is how a private package's
                    // existence leaks out of a public landing page.
                    ->helperText('Only packages that publish a page of their own are listed. Off leaves the page describing the repository alone.'),
                TextInput::make('page_image')
                    ->label('Social preview image')
                    ->url()
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
                    ->placeholder('https://example.com/registry-card.png')
                    ->helperText('Shown when this page is linked in Slack, a post or a chat. Empty uses the registry-wide default.'),
                Textarea::make('page_body')
                    ->label('Page content')
                    ->rows(8)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => (bool) $get('page_enabled'))
                    ->helperText('Markdown, shown above the package list. There is no repository to read a README from here, so this is the only body.'),
            ]);
    }

    /**
     * The Composer repositories this one mirrors from, in the order they are
     * consulted.
     *
     * Below the reservations on purpose. The two sections are one decision
     * read in order: turning mirroring on means this repository will answer
     * for names it does not publish, and reserving your own vendors first is
     * what keeps an upstream from being one of the things it answers with.
     *
     * A section rather than a resource of its own, like the reservations: an
     * upstream is not something an operator goes looking for, it is something
     * they decide about a repository.
     */
    private static function upstreams(): Section
    {
        return Section::make('Upstream mirroring')
            ->description('Other Composer repositories this one serves packages from on demand — usually packagist.org, so that a consuming project can resolve its whole dependency graph through one URL and keep installing when packagist.org or GitHub has a bad day. Off until you add one. Metadata and archives are cached here and pruned when nothing has asked for them; see docs/mirroring.md for the disk cost.')
            ->collapsible()
            ->collapsed(fn (?Repository $record): bool => $record === null || $record->upstreams()->doesntExist())
            ->columnSpanFull()
            ->schema([
                Repeater::make('upstreams')
                    ->relationship()
                    ->hiddenLabel()
                    ->addActionLabel('Add an upstream')
                    // Upstreams are consulted in order and the first that has
                    // a package wins, so the order is an operator's decision
                    // rather than whatever order they happened to be added in.
                    ->orderColumn('position')
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('packagist.org')
                            ->helperText('A label for the admin. The URL is the identity.'),
                        Select::make('ecosystem')
                            ->options(Ecosystem::class)
                            ->default(Ecosystem::Composer)
                            ->required()
                            ->native(false)
                            ->helperText('Which protocol this upstream speaks — and therefore which of this repository\'s surfaces consults it.'),
                        TextInput::make('url')
                            ->label('Repository URL')
                            ->required()
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://repo.packagist.org')
                            ->helperText('The root the protocol resolves from: a Composer v2 repository (https://repo.packagist.org), an npm registry (https://registry.npmjs.org), or a PEP 691 simple index (https://pypi.org/simple).'),
                        TextInput::make('token')
                            ->label('Access token')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            // As on a source: the stored token is never echoed
                            // back to the browser, a blank input keeps it and a
                            // new value replaces it.
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->placeholder('Only for an upstream that needs one')
                            // The warning is here rather than in the docs alone
                            // because this is the field that creates the
                            // hazard: everything an upstream serves is served
                            // on through this repository's own read rules.
                            ->helperText('Sent as the HTTP Basic password. Anything a credentialed upstream serves is served on under this repository\'s access rules — so a public repository with a private upstream republishes it to everyone.'),
                        Toggle::make('enabled')
                            ->helperText('Turning an upstream off stops it being consulted but keeps what is already cached.'),
                    ]),
            ]);
    }

    /**
     * The vendor prefixes this repository owns — the server half of a
     * dependency-confusion defence.
     *
     * A section on the repository rather than a resource of its own, because a
     * reservation is not a thing an operator goes looking for: it is something
     * they decide *about a repository*, at the moment they are deciding what
     * that repository is for. Nothing else about it would fill a screen.
     *
     * The consumer half cannot live here at all — Composer has to be told to
     * treat this registry as canonical for the vendor, in each consuming
     * project — so the description says so and points at where that is written
     * down. A reservation that stops at the registry stops a mistake, not an
     * attack.
     */
    private static function reservedVendors(): Section
    {
        return Section::make('Reserved vendors')
            ->description('Vendor prefixes only this repository may introduce package names under. Reserving "acme" means no other repository here can start serving anything called acme/… — including an artifact upload or a sync adopting the name. Tell consuming projects to treat this registry as canonical too; see docs/dependency-confusion.md.')
            ->collapsible()
            ->collapsed(fn (?Repository $record): bool => $record === null || $record->reservedVendors()->doesntExist())
            ->columnSpanFull()
            ->schema([
                Repeater::make('reservedVendors')
                    ->relationship()
                    ->hiddenLabel()
                    ->addActionLabel('Reserve a vendor')
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state): ?string => $state['vendor'] ?? null)
                    ->schema([
                        TextInput::make('vendor')
                            ->hiddenLabel()
                            ->required()
                            ->maxLength(128)
                            // The vendor half of a Composer name, which is the
                            // only half a reservation is about — the model
                            // trims "acme/widgets" and "acme/*" down to this
                            // anyway, and refusing them here would only make
                            // the operator do it by hand.
                            ->regex('/^[a-z0-9]([_.-]?[a-z0-9]+)*(\/\*?)?$/')
                            ->validationMessages([
                                'regex' => 'A Composer vendor prefix: lowercase letters, numbers, and . _ - between them.',
                                'unique' => 'That vendor is already reserved, by this repository or another one.',
                            ])
                            // The column is unique across the registry, because
                            // two repositories claiming one vendor is the
                            // ambiguity this exists to remove. Caught here so
                            // it reads as a field error rather than a query
                            // one.
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, ?string $state): Unique => $rule->where(
                                    'vendor',
                                    ReservedVendor::normalize((string) $state),
                                ),
                            )
                            ->placeholder('acme'),
                    ]),
            ]);
    }
}
