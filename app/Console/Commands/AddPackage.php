<?php

namespace App\Console\Commands;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Repository;
use App\Models\ReservedVendor;
use App\Services\GitHub\WebhookRegistrar;
use Illuminate\Console\Command;

use function Laravel\Prompts\text;

/**
 * The create wizard's job, scriptable: name a repository URL and the package
 * is created, webhooked where possible, and queued for its first sync.
 */
class AddPackage extends Command
{
    protected $signature = 'package:add
        {repository? : The VCS repository URL (https://github.com/owner/repo); prompted for when omitted}
        {--name= : The composer name; guessed from the URL when omitted}
        {--repo= : The Composer repository path to serve it from; the root repository when omitted}
        {--token= : A provider access token, for repositories no connected source covers}
        {--no-webhook : Do not create a repository webhook}
        {--no-sync : Do not queue the first sync}';

    protected $description = 'Create a package from a VCS repository URL and queue its first sync';

    public function handle(WebhookRegistrar $registrar): int
    {
        $url = $this->argument('repository');

        if (blank($url) && $this->input->isInteractive() && defined('STDIN') && stream_isatty(STDIN)) {
            $url = text(label: 'Repository URL', required: true, placeholder: 'https://github.com/vendor/package');
        }

        if (blank($url)) {
            $this->components->error('A repository URL is required when the command cannot prompt for one.');

            return self::FAILURE;
        }

        $repository = $this->composerRepository();

        if ($repository === null) {
            return self::FAILURE;
        }

        $package = new Package([
            'repository' => $url,
            'token' => $this->option('token') ?: null,
            'repository_id' => $repository->id,
        ]);

        $name = $this->option('name') ?: $package->suggestedName();

        if (blank($name)) {
            $this->components->error("The composer name cannot be guessed from [{$url}]; pass it with --name.");

            return self::FAILURE;
        }

        $package->name = $name;

        $collision = $repository->packages()
            ->where(fn ($query) => $query->where('name', $name)->orWhere('repository', $url))
            ->first();

        if ($collision instanceof Package) {
            $this->components->error("\"{$collision->name}\" already serves from this Composer repository ({$collision->repository}).");

            return self::FAILURE;
        }

        // The save below refuses this anyway, by throwing; asked here it is an
        // error line rather than a stack trace in somebody's provisioning log.
        $reserved = ReservedVendor::conflictFor($name, $repository->id);

        if ($reserved instanceof ReservedVendor) {
            $this->components->error($reserved->refusal($name));

            return self::FAILURE;
        }

        $package->save();

        $this->components->info("Created {$package->name} in the \"{$repository->name}\" repository.");

        if (! $this->option('no-webhook')) {
            $registrar->register($package);

            if ($reason = $registrar->unmetRequirement($package)) {
                $this->components->warn("This package will not sync itself: {$reason}");
            }
        }

        if (! $this->option('no-sync')) {
            SyncPackageJob::dispatch($package);
            $this->components->info('First sync queued; versions import in the background.');
        }

        return self::SUCCESS;
    }

    private function composerRepository(): ?Repository
    {
        $path = $this->option('repo');

        if (blank($path)) {
            return Repository::default();
        }

        $repository = Repository::query()->where('path', $path)->first();

        if (! $repository instanceof Repository) {
            $this->components->error(sprintf(
                'No Composer repository is served at /r/%s. Existing paths: %s.',
                $path,
                Repository::query()->whereNotNull('path')->orderBy('path')->pluck('path')->implode(', ') ?: '(none)',
            ));

            return null;
        }

        return $repository;
    }
}
