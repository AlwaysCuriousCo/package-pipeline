<?php

namespace Tests\Feature;

use App\Filament\Livewire\EmailNotificationsForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe(): void
    {
        $this->withoutExceptionHandling();

        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        Livewire::test(EmailNotificationsForm::class)
            ->set('data.email_notifications', true)
            ->call('updateEmailNotifications');
    }
}
