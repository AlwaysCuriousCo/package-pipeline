<?php

use App\Models\Package;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Fold every stored package name to the lowercase spelling Composer asks
     * in, so the equality behind `/p2` and `/dist` finds it on every engine.
     *
     * @see Package::normalizeName() for why the name is canonical
     *      lowercase and what was broken while it was not
     */
    public function up(): void
    {
        $conflicts = [];

        // The query builder rather than the model, deliberately. Nothing here
        // needs the model's derivation — this is `mb_strtolower` and a
        // uniqueness question — and going through it would fire a saving hook
        // that re-links sources, re-derives `repository_path` and can *throw*
        // over a vendor reservation made long after these rows were written. A
        // backfill has no business failing a deploy over a rule that governs
        // what may be introduced.
        DB::table('packages')
            ->select(['id', 'repository_id', 'name'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $packages) use (&$conflicts): void {
                foreach ($packages as $package) {
                    $name = (string) $package->name;
                    $normalized = mb_strtolower($name);

                    if ($normalized === $name) {
                        continue;
                    }

                    if ($this->taken($package->repository_id, $normalized, $package->id)) {
                        $conflicts[] = "#{$package->id} \"{$name}\"";

                        // Left named as it was. Renaming it would collide with
                        // the unique index; deleting it would unpublish
                        // versions and archives on the strength of a guess.
                        // Both rows are real to somebody, and which of them the
                        // registry should keep is a question with an owner —
                        // this only makes sure that owner can find it.
                        //
                        // `updated_at` deliberately stands still: nothing about
                        // what this package serves has changed, and the /p2
                        // validators are cut from that column.
                        DB::table('packages')->where('id', $package->id)->update([
                            'sync_error' => "This package is named \"{$name}\", but another package in the same "
                                ."Composer repository already publishes \"{$normalized}\". Composer names are "
                                .'lowercase, so this one cannot be fetched from /p2 or /dist and cannot be '
                                .'renamed to match without colliding. Delete whichever of the two is obsolete.',
                        ]);

                        continue;
                    }

                    // `updated_at` *does* move here, and has to. The name is
                    // rendered into the /p2 body and into every dist URL in it,
                    // and both the ETag and the payload cache key are cut from
                    // this column — so a rename that left it alone would go on
                    // serving the old document under the old name until
                    // something unrelated touched the row.
                    //
                    // Stored archives are unaffected: `archive_path` holds a
                    // whole path, so files written under the old name's prefix
                    // stay exactly where their rows say they are, and
                    // `archives:clean` still claims them.
                    DB::table('packages')->where('id', $package->id)->update([
                        'name' => $normalized,
                        'updated_at' => now(),
                    ]);
                }
            });

        if ($conflicts !== []) {
            $this->report($conflicts);
        }
    }

    /**
     * Whether another package in the same Composer repository already holds the
     * normalized name.
     *
     * Only ever true on a case-sensitive engine: MySQL's default collation
     * makes the (repository_id, name) unique index reject the second spelling
     * outright, so the pair cannot have been created there in the first place.
     * `id != ` covers the other side of that — on MySQL this equality matches
     * the row being examined.
     */
    private function taken(mixed $repositoryId, string $name, mixed $id): bool
    {
        return DB::table('packages')
            ->where('repository_id', $repositoryId)
            ->where('name', $name)
            ->where('id', '!=', $id)
            ->exists();
    }

    /**
     * Say what could not be normalized, twice over.
     *
     * The note written to `sync_error` above is what an admin sees in the
     * panel, but the next successful sync clears that column — so the durable
     * record is here, and the deploy's own output carries it while somebody is
     * still watching.
     *
     * @param  list<string>  $conflicts
     */
    private function report(array $conflicts): void
    {
        $message = 'Some package names could not be normalized to lowercase because another package in the '
            .'same Composer repository already publishes the lowercase name. These are unserveable through '
            .'/p2 and /dist and need one of each pair deleted by hand: '.implode(', ', $conflicts);

        Log::warning($message);

        if (defined('STDERR')) {
            fwrite(STDERR, "\n  WARNING: {$message}\n\n");
        }
    }

    /**
     * Not reversible, and not pretending to be. The casing a name was stored
     * in is exactly the information this threw away, and it was never
     * information the registry could serve.
     */
    public function down(): void {}
};
