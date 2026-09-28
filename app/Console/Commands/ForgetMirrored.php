<?php

namespace App\Console\Commands;

use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;

class ForgetMirrored extends Command
{
    protected $signature = 'mirror:forget
        {package : The mirrored package, e.g. livewire/flux-pro}
        {version? : One release to drop; every cached release when omitted}';

    protected $description = 'Delete a mirrored package (or one release) so it is fetched from the upstream again';

    public function handle(MirrorService $mirror): int
    {
        $package = (string) $this->argument('package');
        $version = $this->argument('version');

        $archives = $mirror->forget($package, is_string($version) ? $version : null);

        $this->components->info(sprintf(
            'Forgot %s%s (%d archive%s deleted). The next request fetches it from the upstream.',
            $package,
            is_string($version) ? " {$version}" : '',
            $archives,
            $archives === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
