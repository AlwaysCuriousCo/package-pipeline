<?php

namespace App\Filament\Resources\Activities\Schemas;

use App\Models\Activity;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Str;

class ActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')
                    ->label('When')
                    ->dateTime(),
                TextEntry::make('event')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Str::headline((string) $state)),
                TextEntry::make('subject_type')
                    ->label('Record type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => class_basename((string) $state)),
                TextEntry::make('subject_id')
                    ->label('Record ID')
                    ->placeholder('-'),
                TextEntry::make('credential')
                    ->label('Made through')
                    ->state(fn (Activity $record): mixed => $record->properties->get('credential'))
                    ->placeholder('The panel or the console — no access token was presented.'),
                Section::make('What changed')
                    ->description('Only attributes the record marks as auditable are recorded — never a token, a password or any other secret. A URL is stored with any credential it carried replaced by [redacted].')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('changes')
                            ->label('After')
                            ->state(fn (Activity $record): array => self::lines($record, 'attributes'))
                            ->listWithLineBreaks()
                            ->fontFamily(FontFamily::Mono)
                            ->placeholder('Nothing recorded.'),
                        TextEntry::make('previous')
                            ->label('Before')
                            ->state(fn (Activity $record): array => self::lines($record, 'old'))
                            // A creation has no "before", and neither does an
                            // event recorded on its own rather than as a diff.
                            ->placeholder('Not applicable — the record did not exist, or the change is not an attribute diff.')
                            ->listWithLineBreaks()
                            ->fontFamily(FontFamily::Mono),
                    ]),
            ]);
    }

    /**
     * One "attribute: value" line per recorded property.
     *
     * @return list<string>
     */
    private static function lines(Activity $record, string $key): array
    {
        // An event recorded on its own — a role, a grant — carries its detail
        // at the top level rather than under `attributes`. `credential` is not
        // part of that detail: it is stamped on every entry and has its own
        // field above.
        $properties = $record->properties->get($key, $key === 'attributes'
            ? $record->properties->except('credential')->all()
            : []);

        if (! is_iterable($properties)) {
            return [];
        }

        $lines = [];

        foreach ($properties as $attribute => $value) {
            $lines[] = $attribute.': '.self::readable($value);
        }

        return $lines;
    }

    private static function readable(mixed $value): string
    {
        return match (true) {
            $value === null => '(none)',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
