<?php

namespace App\Filament\Resources\Repositories\Schemas;

use App\Models\Repository;
use App\Models\ReservedVendor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
                self::reservedVendors(),
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
