<?php

use App\Models\Package;
use App\Notifications\UnserveablePackageNames;
use App\Services\AdminNotifier;
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
                        $conflicts[] = $name;

                        // Left named as it was. Renaming it would collide with
                        // the unique index; deleting it would unpublish
                        // versions and archives on the strength of a guess.
                        // Both rows are real to somebody, and which of them the
                        // registry should keep is a question with an owner —
                        // this only makes sure that owner can find it.
                        //
                        // The notice is Package's rather than this file's, and
                        // PackageSynchronizer writes the same one on every sync
                        // from the same condition. Written here alone it lasted
                        // an hour: syncError() answers null for a package that
                        // synced cleanly and finalize() stores that answer
                        // unconditionally, so the first scheduled run after the
                        // deploy erased the only thing pointing at the row.
                        //
                        // `updated_at` deliberately stands still: nothing about
                        // what this package serves has changed, and the /p2
                        // validators are cut from that column.
                        DB::table('packages')->where('id', $package->id)->update([
                            'sync_error' => Package::unserveableNameNotice($name),
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
     * Say what could not be normalized, everywhere somebody might be.
     *
     * The `sync_error` above is what the panel badges, and it now holds — but
     * it holds passively, and a package that answers 404 on both of its
     * endpoints should not wait for the next person to open the packages list.
     * A deploy running unattended in CI has nobody reading the two lines below
     * either, which is what the notification is for: the bell, and the Slack
     * channel an installation that has one is already watching.
     *
     * Rescued, because none of this is worth failing a deploy over. The
     * notification is queued on every connection but `sync`, and on that one it
     * is an outbound Slack call inside `php artisan migrate` — a channel that
     * has gone away must not leave a migration batch half-applied. Whatever
     * happens here, the log line and the panel notice have already landed.
     *
     * @param  list<string>  $conflicts  names as stored
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

        rescue(fn () => app(AdminNotifier::class)->send(new UnserveablePackageNames($conflicts)));
    }

    /**
     * Not reversible, and not pretending to be. The casing a name was stored
     * in is exactly the information this threw away, and it was never
     * information the registry could serve.
     */
    public function down(): void {}
};
