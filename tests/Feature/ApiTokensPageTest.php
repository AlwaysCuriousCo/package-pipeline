<?php

namespace Tests\Feature;

use App\Filament\Pages\ApiTokens;
use App\Models\Token;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
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

    /**
     * A panel user whose role can read packages and administer nothing.
     */
    private function scopedUser(): User
    {
        $role = Role::create(['name' => 'developer', 'guard_name' => 'web']);
        $role->givePermissionTo(['ViewAny:Package', 'View:Package']);

        return tap(User::factory()->create())->assignRole($role);
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

    /**
     * The page is reachable by anybody who can sign in, so the abilities it
     * offers have to stop where the role does — otherwise a checkbox issues a
     * credential that does what the panel refuses.
     */
    public function test_a_role_that_cannot_delete_packages_is_not_offered_the_delete_ability(): void
    {
        $this->actingAs($this->scopedUser());

        Livewire::test(ApiTokens::class)
            ->mountAction('create')
            ->assertSchemaComponentExists(
                'abilities',
                checkComponentUsing: fn (CheckboxList $field): bool => array_keys($field->getOptions())
                    === ['repository:read', 'repository:write', 'api:read', 'api:write'],
            );
    }

    /**
     * And the checkbox list is not the enforcement: Livewire state comes from
     * the client, which can post an option the page never rendered.
     */
    public function test_an_ability_the_role_may_not_hold_is_refused_on_submit(): void
    {
        $user = $this->scopedUser();

        $this->actingAs($user);

        Livewire::test(ApiTokens::class)
            ->callAction('create', [
                'name' => 'sneaky',
                'abilities' => ['repository:read', 'api:delete'],
            ])
            ->assertHasActionErrors(['abilities']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_role_that_may_delete_packages_is_offered_the_delete_ability(): void
    {
        Livewire::test(ApiTokens::class)
            ->callAction('create', [
                'name' => 'housekeeping',
                'abilities' => ['api:delete'],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(['api:delete'], $this->user->tokens()->sole()->abilities);
    }

    public function test_a_user_with_no_token_permission_revokes_their_own_token(): void
    {
        $user = $this->scopedUser();
        $token = Token::factory()->for($user, 'tokenable')->create();

        $this->actingAs($user);

        Livewire::test(ApiTokens::class)
            ->callAction(TestAction::make('delete')->table($token))
            ->assertNotified('Token revoked');

        $this->assertSoftDeleted($token);
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

    public function test_the_page_points_at_deploy_tokens_for_those_who_may_see_them(): void
    {
        Livewire::test(ApiTokens::class)
            ->assertSeeHtml('deploy token</a>');

        $this->actingAs($this->scopedUser());

        Livewire::test(ApiTokens::class)
            ->assertSee('Ask an admin for a deploy token')
            ->assertDontSeeHtml('deploy token</a>');
    }
}
