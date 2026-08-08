<?php

namespace Tests\Feature;

use App\Filament\Pages\ApiTokens;
use App\Models\Token;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokensPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->superAdmin()->create();

        $this->actingAs($this->user);
    }

    public function test_a_token_is_created_and_its_plain_text_shown_once(): void
    {
        $component = Livewire::test(ApiTokens::class)
            ->callAction('create', [
                'name' => 'CI deploy',
                'abilities' => ['repository:read'],
            ])
            ->assertHasNoActionErrors();

        $plain = $component->get('plainTextToken');

        $this->assertIsString($plain);
        $this->assertStringStartsWith('pp_', $plain);
        $component->assertSee($plain);

        $token = Token::findByPlainText($plain);

        $this->assertNotNull($token);
        $this->assertSame('CI deploy', $token->name);
        $this->assertSame(['repository:read'], $token->abilities);
        $this->assertTrue($token->tokenable->is($this->user));

        // Only the hash is stored.
        $this->assertNotSame($plain, $token->token);
        $this->assertSame(substr($plain, 0, 8), $token->token_prefix);
    }

    public function test_the_table_lists_only_the_users_own_tokens(): void
    {
        $mine = Token::factory()->for($this->user, 'tokenable')->create();
        $theirs = Token::factory()->create();

        Livewire::test(ApiTokens::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_revoking_a_token_soft_deletes_it_and_stops_authentication(): void
    {
        $plain = 'pp_'.str_repeat('x', 40);

        $token = Token::factory()->for($this->user, 'tokenable')->create([
            'token' => hash('sha256', $plain),
        ]);

        $this->assertNotNull(Token::findByPlainText($plain));

        Livewire::test(ApiTokens::class)
            ->callAction(TestAction::make('delete')->table($token));

        $this->assertSoftDeleted('access_tokens', ['id' => $token->id]);
        $this->assertNull(Token::findByPlainText($plain));
    }
}
