<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The grant pivots, indexed in the direction they are read.
 *
 * Asserted rather than assumed because the mistake is invisible from every
 * other angle: the queries are correct, the tests pass, and a pivot indexed
 * only by the column nobody filters on simply scans. These tables are on the
 * Composer hot path — User::packageGrants() and repositoryGrants() run once
 * per metadata request and once per dist request for a scoped client — so a
 * later edit that drops one of these has to fail something.
 *
 * @see database/migrations/2026_08_10_220000_index_grants_by_their_readers.php
 */
class GrantIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function grantIndexes(): array
    {
        return [
            'team membership by user' => ['team_user', ['user_id', 'team_id']],
            'package grants by team' => ['package_team', ['team_id', 'package_id']],
            'repository grants by team' => ['repository_team', ['team_id', 'repository_id']],
            'package grants by user' => ['package_user', ['user_id', 'package_id']],
            'repository grants by user' => ['repository_user', ['user_id', 'repository_id']],
            'advisories by package' => ['package_advisories', ['package_id']],
        ];
    }

    /**
     * @param  list<string>  $columns
     */
    #[DataProvider('grantIndexes')]
    public function test_the_pivot_is_indexed_by_the_column_it_is_queried_on(string $table, array $columns): void
    {
        $indexes = array_map(
            fn (array $index): array => array_map(strval(...), $index['columns']),
            Schema::getIndexes($table),
        );

        // Leading column first is the whole point: a composite index is only
        // usable from its front, which is why the unique pair already on each
        // of these tables does not answer these queries.
        $this->assertContains(
            $columns,
            $indexes,
            "{$table} has no index starting at ".implode(', ', $columns).'.',
        );
    }
}
