<?php

namespace App\Filament\Widgets;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use Filament\Widgets\Concerns\CanPoll;
use Filament\Widgets\Widget;

/**
 * The registry's four running totals — repositories, packages, versions,
 * downloads — as one quad card, scoped like the rest of the dashboard to
 * what the signed-in user may see.
 *
 * The top row is what the registry hosts, the bottom row what it has
 * shipped; the two tones follow that split.
 */
class RegistryTotals extends Widget
{
    use CanPoll;

    protected string $view = 'filament.widgets.registry-totals';

    protected static ?int $sort = -4;

    /**
     * One column of the dashboard's default two: half the page.
     */
    protected int|string|array $columnSpan = 1;

    /**
     * These are standing figures, not a live readout — a registry does not
     * gain repositories while you watch. The trait's vendor default is 5s,
     * which has every open dashboard tab re-running four aggregates twelve
     * times a minute for the lifetime of the tab; once a minute is still
     * quicker than anyone would reload the page by hand.
     *
     * Stated as a method rather than by redeclaring CanPoll's property, which
     * PHP rejects outright: a class using a trait may not restate one of its
     * properties with a different default.
     */
    protected function getPollingInterval(): ?string
    {
        return '60s';
    }

    /**
     * How long a computed set of totals stands for. Sized to the polling
     * interval so a second tab — or a second user with the same grants —
     * costs nothing, and the numbers still move at roughly the rate the
     * page appears to refresh.
     */
    private const CACHE_SECONDS = 60;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'stats' => $this->stats(),
            'pollingInterval' => $this->getPollingInterval(),
        ];
    }

    /**
     * The four figures, keyed by what the card calls them.
     *
     * Cached per viewer because every query below is narrowed by that
     * viewer's grants, so two users' totals are not interchangeable. A grant
     * changed inside the window shows up a minute late, which is the same
     * lateness the poll already has.
     *
     * @return list<array<string, string>>
     */
    private function stats(): array
    {
        $user = auth()->user();

        return cache()->remember(
            'widget:registry-totals:user:'.($user?->getAuthIdentifier() ?? 'guest'),
            self::CACHE_SECONDS,
            function () use ($user): array {
                $repositories = Repository::query()
                    ->when($user, fn ($query, $user) => $query->visibleToUser($user));

                $packages = Package::query()
                    ->when($user, fn ($query, $user) => $query->visibleToUser($user));

                $packageIds = (clone $packages)->select('packages.id');

                return [
                    [
                        'label' => 'Repositories',
                        'value' => number_format($repositories->count()),
                        'tone' => 'info',
                    ],
                    [
                        'label' => 'Packages',
                        'value' => number_format((clone $packages)->count()),
                        'tone' => 'info',
                    ],
                    [
                        'label' => 'Versions',
                        'value' => number_format(
                            PackageVersion::query()->whereIn('package_id', $packageIds)->count(),
                        ),
                        'tone' => 'success',
                    ],
                    [
                        'label' => 'Downloads',
                        // Summed from the denormalized counters rather than
                        // counted over the downloads table, which is what
                        // packages.total_downloads was added to avoid: that
                        // table is append-only and grows with every zip the
                        // registry serves. It is also the table
                        // `downloads:prune` shortens, which the counters
                        // survive and a count over the rows would not.
                        // `downloads:recalculate` is what puts the two back in
                        // step.
                        'value' => number_format((int) (clone $packages)->sum('total_downloads')),
                        'tone' => 'success',
                    ],
                ];
            },
        );
    }
}
