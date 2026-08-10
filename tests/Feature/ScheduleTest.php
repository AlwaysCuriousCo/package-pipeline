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
     * The documented deployment is several app containers behind one database,
     * so a sweep without these would run once per container.
     */
    public function test_every_task_is_guarded_for_multi_instance_deployments(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertTrue($event->onOneServer, "{$event->command} is not restricted to one server.");
        }

        foreach (['packages:sync', 'archives:clean'] as $command) {
            $this->assertTrue($this->event($command)->withoutOverlapping, "{$command} may overlap itself.");
        }
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
