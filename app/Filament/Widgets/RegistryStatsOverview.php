<?php

namespace App\Filament\Widgets;

use App\Models\Download;
use App\Models\Package;
use App\Models\PackageVersion;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The dashboard's headline numbers, scoped to what the signed-in user may
 * see — a user granted three packages reads a dashboard about those three.
 */
class RegistryStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    protected function getStats(): array
    {
        $packages = Package::query()
            ->when(auth()->user(), fn ($query, $user) => $query->visibleToUser($user));

        $packageIds = (clone $packages)->select('packages.id');

        $downloads = Download::query()->whereIn('package_id', $packageIds);

        return [
            Stat::make('Packages', number_format($packages->count()))
                ->description(number_format((clone $packages)->has('versions')->count()).' with synced versions'),
            Stat::make('Versions', number_format(
                PackageVersion::query()->whereIn('package_id', $packageIds)->count(),
            )),
            Stat::make('Downloads · 30 days', number_format(
                (clone $downloads)->where('created_at', '>=', now()->subDays(30))->count(),
            ))
                ->description(number_format($downloads->count()).' all time'),
        ];
    }
}
