<?php

namespace Tests\Feature;

use App\Filament\Livewire\EmailNotificationsForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Joaopaulolndev\FilamentEditProfile\Pages\EditProfilePage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The per-user half of the switch, on the profile page.
 *
 * @see \App\Filament\Livewire\EmailNotificationsForm
 */
class ProfileEmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->superAdmin()->create();

        $this->actingAs($this->user);
    }

    /**
     * A toggle that governs nothing is worse than no toggle, so the section is
     * left off the page entirely while the installation has announcement
     * emails switched off — which is the shipped default, and therefore the
     * suite's.
     *
     * The enabled case is ProfileEmailNotificationsEnabledTest, which has to
     * set the environment before the app boots: the panel reads this setting
     * once, while it is being configured, and no config() call inside a test
     * happens early enough to change what it decided.
     */
    public function test_the_section_is_absent_while_the_installation_has_email_off(): void
    {
        $this->assertFalse(config('registry.notifications.mail'));

        $this->get(EditProfilePage::getUrl())
            ->assertSuccessful()
            // The page itself is still there, with the sections that always apply.
            ->assertSee('Update Password')
            ->assertDontSee('Email notifications');
    }

    public function test_the_toggle_shows_the_users_current_preference(): void
    {
        $this->user->update(['email_notifications' => false]);

        Livewire::test(EmailNotificationsForm::class)
            ->assertSet('data.email_notifications', false)
            // The address the emails would go to, so nobody has to guess which
            // of their accounts is being talked about.
            ->assertSee($this->user->email);
    }

    public function test_opting_out_is_saved(): void
    {
        $this->assertTrue($this->user->email_notifications);

        Livewire::test(EmailNotificationsForm::class)
            ->set('data.email_notifications', false)
            ->call('updateEmailNotifications')
            ->assertHasNoErrors()
            ->assertNotified();

        $this->assertFalse($this->user->refresh()->email_notifications);
    }

    public function test_opting_back_in_is_saved(): void
    {
        $this->user->update(['email_notifications' => false]);

        Livewire::test(EmailNotificationsForm::class)
            ->set('data.email_notifications', true)
            ->call('updateEmailNotifications')
            ->assertHasNoErrors();

        $this->assertTrue($this->user->refresh()->email_notifications);
    }

    /**
     * The form writes one column and is reached by anyone who can open the
     * panel, so it must not be a way to edit the account's identity — a
     * `name` or `email` posted alongside the toggle is ignored.
     */
    public function test_the_form_writes_nothing_but_the_preference(): void
    {
        $before = $this->user->only(['name', 'email']);

        Livewire::test(EmailNotificationsForm::class)
            ->set('data.email_notifications', false)
            ->set('data.email', 'attacker@example.com')
            ->set('data.name', 'Someone else')
            ->call('updateEmailNotifications');

        $this->assertSame($before, $this->user->refresh()->only(['name', 'email']));
    }
}
