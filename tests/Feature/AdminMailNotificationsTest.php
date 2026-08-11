<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use App\Notifications\Concerns\RoutedByAdminNotifier;
use App\Notifications\PackageAbandoned;
use App\Notifications\PackageSyncFailed;
use App\Notifications\PackageVersionsPublished;
use App\Notifications\UnserveablePackageNames;
use App\Services\AdminNotifier;
use App\Services\SyncOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Announcements as email: whether they are sent at all, and to whom.
 *
 * Two switches decide it and they are not interchangeable, which is what most
 * of this file is about. `MAIL_ADMIN_NOTIFICATIONS` is the installation's, off
 * on a fresh install, and no user preference can overrule it. The column is the
 * person's, on by default, and only ever narrows.
 *
 * @see RoutedByAdminNotifier
 * @see User::wantsMailAnnouncements()
 */
class AdminMailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function admin(bool $wantsEmail = true): User
    {
        $user = User::factory()->create(['email_notifications' => $wantsEmail]);
        $user->assignRole(Role::create(['name' => 'maintainer', 'guard_name' => 'web']));

        return $user;
    }

    private function announce(): void
    {
        app(AdminNotifier::class)->send(
            new PackageSyncFailed(Package::factory()->create(), 'GitHub timed out.'),
        );
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertChannels(array $expected, User $user): void
    {
        Notification::assertSentTo(
            $user,
            PackageSyncFailed::class,
            fn (PackageSyncFailed $notification, array $channels): bool => $channels === $expected,
        );
    }

    /**
     * The shipped default. A fresh install runs on MAIL_MAILER=log, where an
     * enabled fan-out would write four announcements to a file and reach
     * nobody — so the bell is the whole of it until somebody says otherwise.
     */
    public function test_no_email_is_sent_while_the_installation_has_not_asked_for_it(): void
    {
        $this->assertFalse(config('registry.notifications.mail'));

        $user = $this->admin();

        $this->announce();

        $this->assertChannels(['database'], $user);
    }

    /**
     * The column defaults to true, so turning the environment setting on is
     * the only step: an installation that enables this and finds nothing
     * happens until every user visits their profile page would read as broken.
     */
    public function test_enabling_it_emails_users_who_have_not_touched_the_setting(): void
    {
        config(['registry.notifications.mail' => true]);

        $user = $this->admin();

        $this->assertTrue($user->email_notifications);

        $this->announce();

        $this->assertChannels(['database', 'mail'], $user);
    }

    public function test_a_user_who_opted_out_keeps_the_bell_and_loses_the_email(): void
    {
        config(['registry.notifications.mail' => true]);

        $user = $this->admin(wantsEmail: false);

        $this->announce();

        $this->assertChannels(['database'], $user);
    }

    /**
     * The two switches are and-ed, and the installation's is the one that
     * cannot be overruled — a preference left on from a period when email was
     * enabled must not resume sending when it is turned back off.
     */
    public function test_a_user_preference_cannot_turn_email_on_by_itself(): void
    {
        config(['registry.notifications.mail' => false]);

        $user = $this->admin(wantsEmail: true);

        $this->announce();

        $this->assertChannels(['database'], $user);
    }

    /**
     * Slack and the outgoing webhooks are routed anonymously, with their one
     * transport named. Nothing about the mail switch may reach them — least of
     * all a `mail` channel appended to a webhook endpoint's delivery.
     */
    public function test_the_anonymous_routes_are_untouched_by_the_mail_switch(): void
    {
        config([
            'registry.notifications.mail' => true,
            'services.slack.notifications.bot_user_oauth_token' => 'xoxb-testing',
            'services.slack.notifications.channel' => '#releases',
        ]);

        $this->announce();

        Notification::assertSentOnDemand(
            PackageSyncFailed::class,
            fn (PackageSyncFailed $notification, array $channels): bool => $channels === ['slack'],
        );
    }

    /**
     * The four announcements each render an email, and each one carries the
     * same headline the bell shows plus a link back into the panel. Asserted
     * together because the trait behind them is shared and a class that forgot
     * to use it fails nowhere else.
     */
    public function test_every_announcement_renders_an_email(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $user = $this->admin();

        $cases = [
            [new PackageSyncFailed($package, 'GitHub timed out.'), 'Could not sync acme/widgets', 'View package'],
            [new PackageAbandoned($package), 'Abandoned acme/widgets', 'View package'],
            [
                new PackageVersionsPublished($package, new SyncOutcome(releases: ['v1.2.0'], total: 1, initialImport: false)),
                'acme/widgets v1.2.0',
                'View package',
            ],
            [new UnserveablePackageNames(['Acme/Widgets']), '1 package cannot be served under the stored name', 'View packages'],
        ];

        foreach ($cases as [$notification, $subject, $actionLabel]) {
            $mail = $notification->toMail($user);

            $this->assertSame($subject, $mail->subject);

            $rendered = (string) $mail->render();

            $this->assertStringContainsString($subject, $rendered);
            $this->assertStringContainsString($actionLabel, $rendered);
            // Every one of these exists to get somebody back into the panel.
            $this->assertStringContainsString('/admin/', $rendered);
            // And to tell them how to stop receiving it.
            $this->assertStringContainsString($user->email, $rendered);
        }
    }

    /**
     * These are all queued and rendered by a worker with no request behind it,
     * so a root-relative href would leave the reader with a link their mail
     * client cannot resolve. Both links in the message have to be absolute:
     * the button into the panel, and the one that turns the emails off.
     */
    public function test_both_links_are_absolute_so_they_survive_the_queue(): void
    {
        $rendered = (string) (new PackageAbandoned(Package::factory()->create()))
            ->toMail($this->admin())
            ->render();

        preg_match_all('/href="([^"]+)"/', $rendered, $matches);

        $this->assertCount(2, $matches[1]);

        foreach ($matches[1] as $href) {
            $this->assertMatchesRegularExpression('#^https?://#', $href);
        }
    }
}
