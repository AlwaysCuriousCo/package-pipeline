<?php

namespace Tests\Unit;

use App\Support\VersionNormalizer;
use PHPUnit\Framework\TestCase;

class VersionNormalizerTest extends TestCase
{
    private VersionNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new VersionNormalizer;
    }

    public function test_tags_normalize_to_one_spelling(): void
    {
        $this->assertSame('1.2.3', $this->normalizer->version('v1.2.3'));
        $this->assertSame('1.2', $this->normalizer->version('1.2'));
        $this->assertSame('1.2.3.4', $this->normalizer->version('1.2.3.4'));
        $this->assertSame('1.0.0-beta2', $this->normalizer->version('1.0.0-beta.2'));
        $this->assertSame('2.0.0-RC1', $this->normalizer->version('v2.0.0-RC.1'));
        $this->assertSame('1.0.0-alpha3', $this->normalizer->version('1.0.0-alpha3'));
    }

    public function test_dev_versions_pass_through_untouched(): void
    {
        $this->assertSame('dev-main', $this->normalizer->version('dev-main'));
        $this->assertSame('2.x-dev', $this->normalizer->version('2.x-dev'));
    }

    public function test_branches_map_to_packagist_style_dev_versions(): void
    {
        $this->assertSame('dev-main', $this->normalizer->devVersion('main'));
        $this->assertSame('dev-develop', $this->normalizer->devVersion('develop'));
        $this->assertSame('2.x-dev', $this->normalizer->devVersion('2.x'));
        $this->assertSame('2.x-dev', $this->normalizer->devVersion('2'));
        $this->assertSame('2.0.x-dev', $this->normalizer->devVersion('2.0'));
        $this->assertSame('dev-feature/tokens', $this->normalizer->devVersion('feature/tokens'));
    }

    public function test_order_sorts_1_10_above_1_9(): void
    {
        $this->assertGreaterThan(
            0,
            strcmp($this->normalizer->order('1.10.0'), $this->normalizer->order('1.9.0')),
        );
    }

    public function test_order_ranks_stability_correctly(): void
    {
        $versions = ['1.0.0-alpha1', '1.0.0-beta2', '1.0.0-beta10', '1.0.0-RC2', '1.0.0'];

        $ordered = collect($versions)
            ->sortByDesc(fn (string $version): string => $this->normalizer->order($version))
            ->values()
            ->all();

        $this->assertSame(
            ['1.0.0', '1.0.0-RC2', '1.0.0-beta10', '1.0.0-beta2', '1.0.0-alpha1'],
            $ordered,
        );
    }

    public function test_order_handles_v_prefixes_and_partial_versions(): void
    {
        $this->assertSame(
            $this->normalizer->order('1.2.0'),
            $this->normalizer->order('v1.2'),
        );
    }

    public function test_dev_versions_keep_their_own_order_string(): void
    {
        $this->assertSame('dev-main', $this->normalizer->order('dev-main'));
        $this->assertSame('2.x-dev', $this->normalizer->order('2.x-dev'));
    }

    public function test_unparseable_input_sorts_beneath_every_real_version(): void
    {
        $this->assertLessThan(
            0,
            strcmp($this->normalizer->order('not-a-version'), $this->normalizer->order('0.0.1')),
        );
    }
}
