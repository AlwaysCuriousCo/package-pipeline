<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The migration that widens `notifications.data` to json converts the whole
 * column in one statement, and it is not the last migration in its batch — so a
 * row the cast cannot read would abort the deploy halfway through, with an
 * engine error naming a fragment of a value and no way to find the row it came
 * from.
 *
 * The conversion itself only happens on MySQL and PostgreSQL; the suite runs on
 * SQLite, where the column is text either way and `up()` returns before it. So
 * what is asserted here is the precondition, called directly.
 */
class NotificationDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_08_10_200000_convert_notification_data_to_json.php');
    }

    /**
     * @return string the id it was inserted under
     */
    private function insertNotification(string $data): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\PackageSyncFailed',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => 1,
            'data' => $data,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_rows_laravel_wrote_pass_the_guard(): void
    {
        $this->insertNotification(json_encode(['format' => 'filament', 'title' => 'Synced']));

        $this->migration()->guardUnreadableRows();

        $this->addToAssertionCount(1);
    }

    public function test_an_empty_table_passes_the_guard(): void
    {
        $this->migration()->guardUnreadableRows();

        $this->addToAssertionCount(1);
    }

    public function test_a_row_that_is_not_a_document_is_refused_by_id(): void
    {
        $this->insertNotification(json_encode(['format' => 'filament']));
        $broken = $this->insertNotification('not a document');

        $this->expectException(RuntimeException::class);
        // The whole point: the operator is told which row to repair, and that
        // the schema is still where it was.
        $this->expectExceptionMessage($broken);

        $this->migration()->guardUnreadableRows();
    }

    /**
     * The other shape a legacy text column can hold and json cannot read.
     */
    public function test_an_empty_row_is_refused(): void
    {
        $broken = $this->insertNotification('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($broken);

        $this->migration()->guardUnreadableRows();
    }

    /**
     * A registry that got this wrong got it wrong in bulk; the message stays
     * readable rather than pasting the table into the terminal.
     */
    public function test_the_message_caps_how_many_rows_it_names(): void
    {
        for ($row = 0; $row < 25; $row++) {
            $this->insertNotification('not a document');
        }

        try {
            $this->migration()->guardUnreadableRows();
            $this->fail('The guard should have refused 25 unreadable rows.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('25 row(s)', $exception->getMessage());
            $this->assertStringContainsString('(and 5 more)', $exception->getMessage());
        }
    }
}
