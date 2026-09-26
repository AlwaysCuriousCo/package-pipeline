<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\Package;
use App\Models\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class ClaudeSkillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_skill_carries_a_read_only_token_that_lists_packages(): void
    {
        Package::factory()->create(['name' => 'acme/widgets']);
        $path = tempnam(sys_get_temp_dir(), 'skill').'.zip';

        $this->artisan('claude:skill', ['--path' => $path])->assertSuccessful();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $skill = $zip->getFromName('package-pipeline/SKILL.md');
        $zip->close();
        unlink($path);

        $this->assertStringStartsWith("---\nname: package-pipeline\n", $skill);
        $this->assertSame(
            [TokenAbility::RepositoryRead->value, TokenAbility::ApiRead->value],
            Token::query()->sole()->abilities,
        );

        preg_match('/TOKEN=(pp_\w+)/', $skill, $token);

        $this->withToken($token[1])->getJson('/api/v1/packages')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'acme/widgets')
            ->assertJsonPath('data.0.ecosystem', 'composer');
    }
}
