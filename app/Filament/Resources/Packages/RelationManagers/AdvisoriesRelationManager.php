<?php

namespace App\Filament\Resources\Packages\RelationManagers;

use App\Models\PackageAdvisory;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Recording a vulnerability against an in-house package.
 *
 * A relation manager rather than its own resource: an advisory has no meaning
 * apart from the package it is about, and hanging it here means it inherits
 * PackageResource's row-level scoping — a user who cannot reach a package
 * cannot reach the page its advisories live on, with no second copy of that
 * rule to keep in step. A top-level resource would need its own Shield
 * permissions and its own re-statement of package visibility, which is the
 * duplication most likely to end up leaking one.
 */
class AdvisoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'advisories';

    protected static ?string $title = 'Security advisories';

    /**
     * Filament makes relation managers read-only on a resource's view page,
     * on the reasonable assumption that a view page views. Advisories are the
     * exception: nothing else in the app writes this table, so a read-only
     * relation manager here would be a list that can only ever be empty.
     *
     * Whether the actions are then *allowed* is still PackageAdvisoryPolicy's
     * decision, and which packages the page opens for is still the resource's.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->placeholder('Authentication bypass in the webhook verifier')
                ->helperText('The one line a consumer sees when their audit fails.'),
            TextInput::make('affected_versions')
                ->required()
                ->maxLength(255)
                ->placeholder('>=1.0,<1.4.2')
                // Not validated against the version rows: an advisory is
                // routinely filed before the fix exists, and the range often
                // names versions this registry has not synced yet.
                ->helperText('A Composer constraint. Composer compares the installed version against this to decide whether a project is affected.'),
            DateTimePicker::make('reported_at')
                ->label('Reported at')
                ->required()
                ->default(now())
                ->helperText('Composer refuses to load an advisory that carries no date.'),
            Select::make('severity')
                // Exactly the four levels `composer audit --ignore-severity`
                // accepts; anything else would be a rating no consumer can
                // filter on. Left blank, Composer reports the advisory
                // regardless of what the project chose to ignore.
                ->options([
                    'low' => 'Low',
                    'medium' => 'Medium',
                    'high' => 'High',
                    'critical' => 'Critical',
                ])
                ->helperText('Optional. Consumers can silence whole severity levels; an unrated advisory always reports.'),
            TextInput::make('cve')
                ->label('CVE')
                ->maxLength(255)
                ->placeholder('CVE-2026-1234'),
            TextInput::make('link')
                ->url()
                ->maxLength(255)
                ->helperText('Where the details live — an internal ticket is fine.'),
            TextInput::make('advisory_id')
                ->label('Advisory ID')
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                // Generated on save when left blank. Editable because it is
                // the string a consuming project pins in `audit.ignore`, so
                // an id carried over from wherever the advisory was first
                // tracked keeps those ignore entries working.
                ->helperText('Leave blank to generate one.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('reported_at', 'desc')
            ->emptyStateHeading('No advisories')
            ->emptyStateDescription('Recording one here is what makes `composer audit` warn about this package.')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('affected_versions')
                    ->label('Affected')
                    ->badge()
                    ->color('danger'),
                TextColumn::make('severity')
                    ->badge()
                    ->placeholder('Unrated')
                    ->color(fn (?string $state): string => match ($state) {
                        'critical', 'high' => 'danger',
                        'medium' => 'warning',
                        'low' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('cve')
                    ->label('CVE')
                    ->placeholder('None')
                    ->toggleable(),
                TextColumn::make('advisory_id')
                    ->label('ID')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reported_at')
                    ->label('Reported')
                    ->dateTime()
                    ->description(fn (PackageAdvisory $record): string => $record->reported_at->diffForHumans())
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
