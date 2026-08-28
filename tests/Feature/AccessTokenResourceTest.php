<?php

namespace Tests\Feature;

use App\Filament\Resources\AccessTokens\Pages\ListAccessTokens;
use App\Models\DeployToken;
use App\Models\Token;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccessTokenResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_list_shows_every_principals_tokens_and_revokes_one(): void
    {
        $personal = Token::factory()->for(User::factory()->create(['name' => 'Tim']), 'tokenable')->create(['name' => 'laptop']);
        $machine = Token::factory()->for(DeployToken::factory()->create(['name' => 'ci']), 'tokenable')->create(['name' => 'ci']);

        Livewire::test(ListAccessTokens::class)
            ->assertCanSeeTableRecords([$personal, $machine])
            ->assertSee('Tim')
            ->assertSee('Deploy token')
            ->searchTable($personal->token_prefix)
            ->assertCanSeeTableRecords([$personal])
            ->assertCanNotSeeTableRecords([$machine])
            ->searchTable('')
            ->callAction(TestAction::make('delete')->table($personal))
            ->assertNotified('Token revoked')
            ->assertCanNotSeeTableRecords([$personal])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$personal]);

        $this->assertSoftDeleted($personal);
    }
}
