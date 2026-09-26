<?php

namespace App\Console\Commands;

use App\Enums\TokenAbility;
use App\Models\DeployToken;
use App\Models\Token;
use App\Support\ClaudeSkill as Skill;
use Illuminate\Console\Command;

/**
 * Builds the Claude Skill for a deploy token, for an organisation that wants
 * one shared skill rather than each person downloading their own from API
 * tokens in the panel. Whatever the deploy token is granted is what Claude
 * sees.
 *
 * @see Skill
 * @see docs/claude-ai.md
 */
class ClaudeSkill extends Command
{
    protected $signature = 'claude:skill
        {--deploy=claude : The deploy token to issue for, creating it if missing}
        {--expires-days=90 : Expire the token after this many days; 0 for never}
        {--path= : Where to write the zip; package-pipeline-skill.zip in the current directory when omitted}';

    protected $description = 'Build a Claude Skill zip that teaches Claude to find and install packages from this registry';

    public function handle(): int
    {
        $deploy = DeployToken::query()->firstOrCreate(['name' => (string) $this->option('deploy')]);

        $days = (int) $this->option('expires-days');

        $new = Token::issue(
            $deploy,
            'Claude skill',
            [TokenAbility::RepositoryRead, TokenAbility::ApiRead],
            $days > 0 ? now()->addDays($days)->endOfDay() : null,
        );

        $path = $this->option('path') ?: getcwd().'/'.Skill::FILENAME;

        if (@file_put_contents($path, Skill::zip($new->plainText)) === false) {
            $this->components->error("Could not open [{$path}] for writing.");

            return self::FAILURE;
        }

        $this->components->info("Wrote {$path}. It contains a live token — treat the file as a secret.");

        if (! $deploy->isScoped()) {
            $this->components->warn("Deploy token \"{$deploy->name}\" has no grants, so Claude can see the whole registry. Grant it only what Claude needs under Deploy tokens in the panel.");
        }

        $this->components->bulletList([
            'Allow '.parse_url((string) config('app.url'), PHP_URL_HOST).' under Organization settings → Capabilities → Package managers and specific domains',
            'Upload the zip under Settings → Capabilities → Skills',
        ]);

        return self::SUCCESS;
    }
}
