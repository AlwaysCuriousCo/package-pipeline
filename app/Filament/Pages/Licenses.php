<?php

namespace App\Filament\Pages;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\LicenseReport;
use App\Services\SbomExport;
use App\Support\LicenseUsage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the registry publishes, by licence.
 *
 * A page rather than a resource, because a licence is not a record here — it
 * is a string a manifest declared, and the thing being listed is the versions
 * carrying it. The overview above the table answers "what is this registry
 * under" at a glance; the table is the drill-down, and the SBOM button is what
 * anybody who came here for compliance actually needs.
 *
 * Scoped to the signed-in user's own grants throughout, exactly as the package
 * list is: a licence report is a list of package names, and naming a package
 * is what a private repository does not do.
 *
 * @see docs/licensing.md
 */
class Licenses extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Licenses';

    protected static ?string $title = 'Licenses';

    protected string $view = 'filament.pages.licenses';

    /**
     * The same permission the package list is behind. This page names packages
     * and nothing else, so anyone who may open that may open this.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Package::class) ?? false;
    }

    /**
     * The registry-wide breakdown the view renders above the table.
     *
     * @return array{totals: array{versions: int, undeclared: int, packages: int}, licenses: Collection<int, LicenseUsage>}
     */
    public function overview(): array
    {
        $report = $this->report();

        return ['totals' => $report->totals(), 'licenses' => $report->summary()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->report()->versions()->with('package.composerRepository'))
            // The registry read the way a compliance question is asked: what
            // is under each licence, rather than what each package is under.
            ->defaultGroup('license')
            ->groups([
                Group::make('license')
                    ->label('License')
                    ->getTitleFromRecordUsing(fn (PackageVersion $record): string => $record->license ?? 'Not declared'),
            ])
            ->columns([
                TextColumn::make('package.name')
                    ->label('Package')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('license')
                    ->badge()
                    ->fontFamily(FontFamily::Mono)
                    // A version with no licence is the finding, not a gap in
                    // the table — a consuming project that vendors it has no
                    // grant at all, so it is coloured like the problem it is.
                    ->color(fn (?string $state): string => $state === null ? 'danger' : 'success')
                    ->placeholder('Not declared'),
                TextColumn::make('package.composerRepository.name')
                    ->label('Served in')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('released_at')
                    ->label('Released')
                    ->date()
                    ->sortable()
                    ->placeholder('Unknown')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('license')
                    ->label('License')
                    // The registry's own vocabulary rather than a hard-coded
                    // list: what is on offer is what is actually in use.
                    ->options(fn (): array => $this->report()->options())
                    ->searchable(),
                TernaryFilter::make('undeclared')
                    ->label('Licensing')
                    ->placeholder('All versions')
                    ->trueLabel('Declares no license')
                    ->falseLabel('Declares a license')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('license'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('license'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->defaultSort('package_versions.id')
            ->emptyStateHeading('Nothing to report')
            ->emptyStateDescription('No package version is visible to you yet. Licences are read from each version\'s composer.json as it syncs.');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sbom')
                ->label('Export SBOM')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->tooltip('CycloneDX '.SbomExport::SPEC_VERSION.', one component per package version, cut to what you can see.')
                // A plain URL rather than an action returning a file, for the
                // reason the download export is: Livewire delivers a file by
                // base64-encoding it into its response, which would hold the
                // whole document in memory. The route streams it instead.
                ->url(route('exports.sbom'))
                ->openUrlInNewTab(),
        ];
    }

    private function report(): LicenseReport
    {
        /** @var User $user */
        $user = auth()->user();

        return new LicenseReport($user);
    }
}
