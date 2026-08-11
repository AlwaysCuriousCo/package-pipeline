<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use App\Notifications\PackageSyncFailed;
use App\Services\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who hears about an event, decided once for every caller.
 *
 * The fan-out itself is exercised end to end by PackageSyncNotificationTest;
 * what is asserted here is the part no sync reaches — a Slack configuration
 * that is only half filled in, and an installation whose admin list is not
 * the simple one.
 */
class AdminNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function announce(): void
    {
        app(AdminNotifier::class)->send(
            new PackageSyncFailed(Package::factory()->create(), 'GitHub timed out.'),
        );
    }

    /**
     * Half a Slack configuration is the state an installation is in while
     * someone is still setting it up, and routing to a channel with no token
     * behind it is an exception thrown out of an unrelated job.
     */
    public function test_a_channel_with_no_token_behind_it_is_not_posted_to(): void
    {
        config([
            'services.slack.notifications.bot_user_oauth_token' => null,
            'services.slack.notifications.channel' => '#releases',
        ]);

        $this->announce();

        Notification::assertNotSentTo(new AnonymousNotifiable, PackageSyncFailed::class);
    }

    public function test_a_token_with_no_channel_has_nowhere_to_go(): void
    {
        config([
            'services.slack.notifications.bot_user_oauth_token' => 'xoxb-testing',
            'services.slack.notifications.channel' => null,
        ]);

        $this->announce();

        Notification::assertNotSentTo(new AnonymousNotifiable, PackageSyncFailed::class);
    }

    /**
     * Roles are how the recipient list is drawn, so an account holding two of
     * them must not be told twice — a join would do exactly that.
     */
    public function test_an_account_holding_two_roles_is_notified_once(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::create(['name' => 'maintainer', 'guard_name' => 'web']));
        $user->assignRole(Role::create(['name' => 'auditor', 'guard_name' => 'web']));

        $this->announce();

        Notification::assertSentToTimes($user, PackageSyncFailed::class, 1);
    }

    /**
     * A registry run by a queue worker and a Slack channel has no panel user
     * to address, and the failure still has to reach somebody.
     */
    public function test_an_installation_with_no_admins_at_all_still_reaches_slack(): void
    {
        config([
            'services.slack.notifications.bot_user_oauth_token' => 'xoxb-testing',
            'services.slack.notifications.channel' => '#releases',
        ]);

        $this->assertSame(0, User::query()->count());

        $this->announce();

        Notification::assertSentOnDemand(
            PackageSyncFailed::class,
            fn (PackageSyncFailed $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['slack'] === '#releases',
        );
    }
}
