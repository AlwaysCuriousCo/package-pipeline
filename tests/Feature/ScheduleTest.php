<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The maintenance schedule. Syncing is webhook-driven, but the app promises a
 * "next sync" in more than one place — a failed import's sync_error, a package
 * whose webhook registration never took — and these are the tasks that keep
 * those promises.
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $command): Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail("No scheduled command matching \"{$command}\".");
    }

    public function test_packages_are_synced_hourly_through_the_queue(): void
    {
        $event = $this->event('packages:sync');

        $this->assertSame('0 * * * *', $event->expression);

        // Without --queue the scheduler would run every package's sync inline,
        // in its own process, outside every lock SyncPackageJob carries.
        $this->assertStringContainsString('--queue', (string) $event->command);
    }

    public function test_archives_are_cleaned_daily(): void
    {
        $this->assertSame('10 3 * * *', $this->event('archives:clean')->expression);
    }

    /**
     * The sync stopped asking the dist disk about every stored version — one
     * HEAD request per version per package per hour — so this is the only
     * thing left that would ever notice a row outliving its archive.
     */
    public function test_archives_are_audited_daily(): void
    {
        $this->assertSame('20 3 * * *', $this->event('archives:audit')->expression);
    }

    /**
     * The only bound on what the mirror costs in disk: nothing else ever
     * deletes a cached upstream archive, and a full dist disk takes the
     * private packages down along with the mirrored ones.
     */
    public function test_the_mirror_cache_is_pruned_daily(): void
    {
        $this->assertSame('15 3 * * *', $this->event('mirror:prune')->expression);
    }

    public function test_the_append_only_tables_are_pruned_daily(): void
    {
        $notifications = $this->event('model:prune');

        $this->assertSame('30 3 * * *', $notifications->expression);
        $this->assertStringContainsString(Notification::class, (string) $notifications->command);
        // The audit log grows on every audited change and nothing ever
        // deletes an entry, which is the same mistake with a longer memory.
        $this->assertStringContainsString(Activity::class, (string) $notifications->command);

        $this->assertSame('40 3 * * *', $this->event('queue:prune-batches')->expression);
    }

    /**
     * The largest table in the schema, and the last one to get a bound. What
     * it prunes is the per-download detail; the lifetime counters are tallied
     * before the rows go, so nothing the panel shows moves.
     */
    public function test_download_history_is_pruned_daily(): void
    {
        $this->assertSame('50 3 * * *', $this->event('downloads:prune')->expression);
    }

    /**
     * The database cache store expires an entry only when its key is read, and
     * this app supersedes entries rather than invalidating them — so the keys
     * it leaves behind are precisely the ones nothing will read again.
     */
    public function test_expired_cache_entries_are_swept_daily(): void
    {
        $this->assertSame('45 3 * * *', $this->event('cache:prune')->expression);
    }

    /**
     * The documented deployment is several app containers behind one database,
     * so a sweep without these would run once per container.
     */
    public function test_every_task_is_guarded_for_multi_instance_deployments(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertTrue($event->onOneServer, "{$event->command} is not restricted to one server.");
        }

        foreach (['packages:sync', 'archives:clean', 'mirror:prune'] as $command) {
            $this->assertTrue($this->event($command)->withoutOverlapping, "{$command} may overlap itself.");
        }
    }

    /**
     * Laravel releases an overlap mutex on SIGTERM and SIGINT and holds it for
     * a day otherwise, so a container killed outright — OOM, SIGKILL, a node
     * that went away — leaves the lock behind with nothing running under it. At
     * the default that skips 23 hourly syncs; sized to the work it costs one
     * run.
     */
    public function test_no_task_can_hold_its_overlap_lock_for_a_day(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (! $event->withoutOverlapping) {
                continue;
            }

            $this->assertLessThan(
                240 + 1,
                $event->expiresAt,
                "{$event->command} holds its overlap lock far longer than it can be running.",
            );
        }

        // The hourly one is the case that matters most, because it is the only
        // task a stale lock can skip more than once.
        $this->assertSame(15, $this->event('packages:sync')->expiresAt);
    }

    public function test_pruning_clears_read_and_long_stale_notifications(): void
    {
        $user = User::factory()->create();

        // The key is a real uuid column, which PostgreSQL enforces and the
        // other two engines do not, so the rows are labelled beside the key
        // rather than in it.
        $ids = collect(['kept-unread', 'kept-just-read', 'pruned-read', 'pruned-ancient'])
            ->mapWithKeys(fn (string $label): array => [$label => (string) Str::orderedUuid()]);

        $user->notifications()->createMany([
            ['id' => $ids['kept-unread'], 'type' => 'Test', 'data' => [], 'created_at' => now()->subDays(10)],
            ['id' => $ids['kept-just-read'], 'type' => 'Test', 'data' => [], 'read_at' => now()->subDay(), 'created_at' => now()->subDays(10)],
            ['id' => $ids['pruned-read'], 'type' => 'Test', 'data' => [], 'read_at' => now()->subDays(31), 'created_at' => now()->subDays(40)],
            ['id' => $ids['pruned-ancient'], 'type' => 'Test', 'data' => [], 'created_at' => now()->subDays(91)],
        ]);

        $this->artisan('model:prune', ['--model' => [Notification::class]])->assertSuccessful();

        $labels = $ids->flip();

        $this->assertSame(
            ['kept-just-read', 'kept-unread'],
            DB::table('notifications')
                ->pluck('id')
                ->map(fn (string $id): string => (string) $labels->get($id))
                ->sort()
                ->values()
                ->all(),
        );
    }
}
