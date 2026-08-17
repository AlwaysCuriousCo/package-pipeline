<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\PackageResource;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\User;
use App\Notifications\PackageSyncFailed;
use App\Notifications\PackageVersionsPublished;
use App\Services\SyncOutcome;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Zipball;
use Tests\TestCase;

class PackageSyncNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tags = ['v1.0.0'];

    /** @var list<string> */
    private array $branches = ['main'];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Syncing stores an archive per version on the dist disk.
        Storage::fake(config('filesystems.dists'));

        // Stubs are registered once and read the refs at request time, because
        // a second Http::fake() only ever adds to the first — and these tests
        // are about a repository that changes between two syncs.
        Http::fake([
            'api.github.com/repos/acme/widgets/tags*' => fn (): PromiseInterface => Http::response($this->refs($this->tags)),
            'api.github.com/repos/acme/widgets/branches*' => fn (): PromiseInterface => Http::response($this->refs($this->branches)),
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'type' => 'library',
            ]),
            'api.github.com/repos/acme/widgets/commits/*' => Http::response([
                'commit' => ['committer' => ['date' => '2026-02-01T12:00:00Z']],
            ]),
            'api.github.com/repos/acme/widgets/zipball/*' => fn () => Http::response(Zipball::bytes(), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);
    }

    /**
     * What the repository publishes from now on.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $branches
     */
    private function publishing(array $tags = ['v1.0.0'], array $branches = ['main']): void
    {
        $this->tags = $tags;
        $this->branches = $branches;
    }

    /**
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    private function refs(array $names): array
    {
        return array_map(
            fn (string $name): array => ['name' => $name, 'commit' => ['sha' => sha1($name)]],
            $names,
        );
    }

    private function package(): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'acme/widgets-placeholder',
            'repository' => 'https://github.com/acme/widgets',
        ]);
    }

    private function sync(Package $package): void
    {
        $this->app->call([new SyncPackageJob($package), 'handle']);
    }

    public function test_a_first_import_is_announced_with_a_summary(): void
    {
        User::factory()->superAdmin()->create();

        $this->publishing(tags: ['v1.0.0', 'v1.1.0']);

        $this->sync($this->package());

        Notification::assertSentTo(
            User::query()->get(),
            PackageVersionsPublished::class,
            fn (PackageVersionsPublished $notification): bool => $notification->outcome->initialImport
                && $notification->outcome->total === 3,
        );
    }

    public function test_a_new_tag_notifies_with_the_version_it_published(): void
    {
        User::factory()->superAdmin()->create();

        $package = $this->package();

        $this->publishing(tags: ['v1.0.0']);
        $this->sync($package);

        Notification::fake();

        $this->publishing(tags: ['v1.0.0', 'v1.1.0']);
        $this->sync($package);

        Notification::assertSentTo(
            User::query()->get(),
            PackageVersionsPublished::class,
            // The tag v1.1.0 publishes as its normalized spelling.
            fn (PackageVersionsPublished $notification): bool => $notification->outcome->releases === ['1.1.0']
                && ! $notification->outcome->initialImport,
        );
    }

    /**
     * A dev branch moves on every commit. Syncing it is the point; being told
     * about it is not.
     */
    public function test_a_branch_moving_syncs_quietly(): void
    {
        User::factory()->superAdmin()->create();

        $package = $this->package();

        $this->publishing(tags: ['v1.0.0'], branches: ['main']);
        $this->sync($package);

        Notification::fake();

        $this->publishing(tags: ['v1.0.0'], branches: ['main', 'feature/new-thing']);
        $this->sync($package);

        $this->assertSame(3, $package->versions()->count());

        Notification::assertNothingSent();
    }

    public function test_a_sync_that_changes_nothing_says_nothing(): void
    {
        User::factory()->superAdmin()->create();

        $package = $this->package();

        $this->publishing();
        $this->sync($package);

        Notification::fake();

        $this->publishing();
        $this->sync($package);

        Notification::assertNothingSent();
    }

    public function test_a_permanently_failed_sync_is_announced(): void
    {
        User::factory()->superAdmin()->create();

        (new SyncPackageJob($this->package()))->failed(new RuntimeException('GitHub timed out.'));

        Notification::assertSentTo(
            User::query()->get(),
            PackageSyncFailed::class,
            fn (PackageSyncFailed $notification): bool => str_contains($notification->reason, 'GitHub timed out.'),
        );
    }

    /**
     * A package that cannot sync cannot sync hourly. One expired credential
     * across a few hundred packages used to be tens of thousands of exhausted
     * jobs a day, each writing a row per admin and posting to Slack — which
     * throws when it rate-limits, so those deliveries retried and amplified the
     * burst that caused them.
     */
    public function test_the_same_failure_is_announced_once_however_often_it_recurs(): void
    {
        User::factory()->superAdmin()->create();

        $package = $this->package();

        foreach (range(1, 5) as $ignored) {
            (new SyncPackageJob($package))->failed(new RuntimeException('Bad credentials.'));
        }

        Notification::assertSentToTimes(User::query()->first(), PackageSyncFailed::class, 1);
    }

    /**
     * "The credential expired" and "the repository was deleted" are not one
     * incident, and the second must not be swallowed because the first is open.
     */
    public function test_a_failure_for_a_new_reason_is_announced_again(): void
    {
        User::factory()->superAdmin()->create();

        $package = $this->package();

        (new SyncPackageJob($package))->failed(new RuntimeException('Bad credentials.'));
        (new SyncPackageJob($package))->failed(new RuntimeException('Repository not found.'));

        Notification::assertSentToTimes(User::query()->first(), PackageSyncFailed::class, 2);
    }

    public function test_a_package_that_recovers_can_be_announced_again(): void
    {
        User::factory()->superAdmin()->create();

        $package = $this->package();

        (new SyncPackageJob($package))->failed(new RuntimeException('GitHub timed out.'));

        // The sync that works again clears the state the announcement is
        // suppressed by, so the next outage is news rather than a repeat.
        $this->publishing();
        $this->sync($package);

        (new SyncPackageJob($package))->failed(new RuntimeException('GitHub timed out.'));

        Notification::assertSentToTimes(User::query()->first(), PackageSyncFailed::class, 2);
    }

    public function test_slack_is_notified_when_a_channel_is_configured(): void
    {
        config([
            'services.slack.notifications.bot_user_oauth_token' => 'xoxb-testing',
            'services.slack.notifications.channel' => '#releases',
        ]);

        $this->publishing();

        $this->sync($this->package());

        Notification::assertSentOnDemand(
            PackageVersionsPublished::class,
            fn (PackageVersionsPublished $notification, array $channels, AnonymousNotifiable $notifiable): bool => $channels === ['slack']
                && $notifiable->routes['slack'] === '#releases',
        );
    }

    public function test_slack_is_left_alone_when_it_is_not_configured(): void
    {
        config([
            'services.slack.notifications.bot_user_oauth_token' => null,
            'services.slack.notifications.channel' => null,
        ]);

        $this->publishing();

        $this->sync($this->package());

        Notification::assertNotSentTo(new AnonymousNotifiable, PackageVersionsPublished::class);
    }

    /**
     * The bell reads the database channel; Slack must not also land there.
     */
    public function test_a_user_is_notified_through_the_panels_bell(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->publishing();

        $this->sync($this->package());

        Notification::assertSentTo(
            $user,
            PackageVersionsPublished::class,
            fn (PackageVersionsPublished $notification, array $channels): bool => $channels === ['database'],
        );
    }

    /**
     * The bell lives inside the panel, so an account that cannot open the
     * panel has no way to read what is put there.
     */
    public function test_a_user_without_a_role_is_left_out(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $bystander = User::factory()->create();

        $this->publishing();

        $this->sync($this->package());

        Notification::assertSentTo($admin, PackageVersionsPublished::class);
        Notification::assertNotSentTo($bystander, PackageVersionsPublished::class);
    }

    /**
     * The stored payload is what the bell renders, so what it says about the
     * release has to be in there rather than worked out at display time.
     */
    public function test_the_stored_notification_names_the_versions_and_links_to_the_package(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $message = (new PackageVersionsPublished(
            $package,
            new SyncOutcome(releases: ['v1.1.0'], total: 4),
        ))->toDatabase(User::factory()->create());

        $this->assertStringContainsString('acme/widgets v1.1.0', $message['title']);
        $this->assertStringContainsString('v1.1.0', $message['body']);
        $this->assertStringContainsString(
            PackageResource::getUrl('view', ['record' => $package]),
            json_encode($message['actions'], JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * A package with a hundred tags arriving at once is a fact, not a list.
     */
    public function test_a_first_import_is_summarised_rather_than_listed(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets', 'latest_version' => 'v3.1.0']);

        $message = (new PackageVersionsPublished(
            $package,
            new SyncOutcome(releases: ['v1.0.0', 'v2.0.0'], initialImport: true, total: 47),
        ))->toDatabase(User::factory()->create());

        $this->assertStringContainsString('Imported acme/widgets', $message['title']);
        $this->assertStringContainsString('47 versions', $message['body']);
        $this->assertStringContainsString('v3.1.0', $message['body']);
    }
}
