<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Joaopaulolndev\FilamentEditProfile\Pages\EditProfilePage;
use Tests\TestCase;

/**
 * The profile page as an installation that has turned announcement emails on
 * sees it.
 *
 * A class of its own because of when the setting is read. The panel is
 * configured once, as its provider boots, and that is where the decision to
 * register the email-notifications section is made — so a `config()` call
 * inside a test is always too late. The environment variable is set before
 * `parent::setUp()` builds the application instead, which is the only point
 * early enough, and it is a whole-class property rather than a per-test one.
 *
 * @see AdminPanelProvider
 */
class ProfileEmailNotificationsEnabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('MAIL_ADMIN_NOTIFICATIONS=true');
        $_ENV['MAIL_ADMIN_NOTIFICATIONS'] = 'true';
        $_SERVER['MAIL_ADMIN_NOTIFICATIONS'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('MAIL_ADMIN_NOTIFICATIONS');
        unset($_ENV['MAIL_ADMIN_NOTIFICATIONS'], $_SERVER['MAIL_ADMIN_NOTIFICATIONS']);

        parent::tearDown();
    }

    public function test_the_section_appears_on_the_profile_page(): void
    {
        $this->assertTrue(config('registry.notifications.mail'));

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(EditProfilePage::getUrl())
            ->assertSuccessful()
            ->assertSee('Email notifications')
            ->assertSee("Email me the registry's announcements");
    }
}
