<?php

namespace App\Filament\Widgets;

use App\Models\Download;
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
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();

        $repositories = Repository::query()
            ->when($user, fn ($query, $user) => $query->visibleToUser($user));

        $packages = Package::query()
            ->when($user, fn ($query, $user) => $query->visibleToUser($user));

        $packageIds = (clone $packages)->select('packages.id');

        return [
            'stats' => [
                [
                    'label' => 'Repositories',
                    'value' => number_format($repositories->count()),
                    'tone' => 'info',
                ],
                [
                    'label' => 'Packages',
                    'value' => number_format($packages->count()),
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
                    'value' => number_format(
                        Download::query()->whereIn('package_id', $packageIds)->count(),
                    ),
                    'tone' => 'success',
                ],
            ],
            'pollingInterval' => $this->getPollingInterval(),
        ];
    }
}
