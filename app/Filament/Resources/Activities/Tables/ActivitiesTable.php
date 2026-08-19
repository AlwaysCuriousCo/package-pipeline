<?php

namespace App\Filament\Resources\Activities\Tables;

use App\Models\Activity;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ActivitiesTable
{
    /**
     * How each recorded event reads in the list.
     */
    private const EVENTS = [
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'restored' => 'Restored',
        'role_granted' => 'Role granted',
        'role_revoked' => 'Role revoked',
        'grant_added' => 'Access granted',
        'grant_removed' => 'Access revoked',
        'serving_added' => 'Served from repository',
        'serving_removed' => 'Removed from repository',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // The subject and causer are morphs the columns below read
            // through; without this the list is two queries per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['subject', 'causer']))
            ->emptyStateHeading('Nothing recorded yet')
            ->emptyStateDescription('Changes to packages, tokens, users, roles, teams, grants, sources and repositories are recorded here as they happen.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->description(fn (Activity $record): ?string => $record->created_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('causer')
                    ->label('Who')
                    ->state(fn (Activity $record): ?string => self::causer($record))
                    // Which credential, under the name of who holds it: an
                    // account and that account's CI token are the same person
                    // and very different news.
                    ->description(fn (Activity $record): ?string => self::credential($record))
                    // A change made by the console, a webhook or the scheduler
                    // has no signed-in user behind it, and saying so is more
                    // useful than an empty cell.
                    ->placeholder('System')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHasMorph(
                        'causer',
                        '*',
                        fn (Builder $query) => $query->where('name', 'like', "%{$search}%"),
                    )),
                TextColumn::make('event')
                    ->label('Action')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::EVENTS[$state] ?? Str::headline((string) $state))
                    ->color(fn (?string $state): string => match ($state) {
                        'created', 'restored' => 'success',
                        'deleted', 'role_revoked', 'grant_removed' => 'danger',
                        'role_granted', 'grant_added', 'serving_added', 'serving_removed' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('subject_type')
                    ->label('Record type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => class_basename((string) $state))
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Record')
                    ->state(fn (Activity $record): ?string => self::subject($record))
                    // Deleting the record is the change most worth auditing,
                    // so the row has to still make sense once it is gone.
                    ->placeholder('Deleted')
                    ->limit(60),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Action')
                    ->options(self::EVENTS)
                    ->multiple(),
                SelectFilter::make('subject_type')
                    ->label('Record type')
                    ->options(self::subjectTypes(...))
                    ->multiple(),
                Filter::make('unattributed')
                    ->label('Not made by a signed-in user')
                    ->query(fn (Builder $query): Builder => $query->whereNull('causer_id')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * The record types the log actually holds entries for, named as the panel
     * names them.
     *
     * A `distinct` over a column with no index of its own, on a table two years
     * deep — and the filter form is rebuilt on every Livewire round trip, so
     * sorting a column or turning a page paid for it again. What it returns is
     * the set of audited model classes, which changes when this app gains one
     * and not otherwise, so an hour of staleness costs a filter option
     * appearing an hour after the first entry of a brand new type.
     *
     * @return array<string, string>
     */
    private static function subjectTypes(): array
    {
        return cache()->remember('filament:activity-log:subject-types', now()->addHour(), fn (): array => Activity::query()
            ->distinct()
            ->whereNotNull('subject_type')
            ->pluck('subject_type', 'subject_type')
            ->map(fn (string $type): string => class_basename($type))
            ->all());
    }

    /**
     * Who made the change, by name where the account still exists.
     */
    private static function causer(Activity $record): ?string
    {
        $causer = $record->causer;

        if ($causer === null) {
            return null;
        }

        return $causer->getAttribute('name') ?? $causer->getAttribute('email') ?? class_basename($causer::class)." #{$causer->getKey()}";
    }

    /**
     * The access token the change was made through, where it was made through
     * one rather than from a signed-in session.
     */
    private static function credential(Activity $record): ?string
    {
        $credential = $record->properties->get('credential');

        return is_string($credential) ? $credential : null;
    }

    /**
     * The record the change was made to, named as its own resource names it.
     */
    private static function subject(Activity $record): ?string
    {
        $subject = $record->subject;

        if ($subject === null) {
            return null;
        }

        return $subject->getAttribute('name')
            ?? $subject->getAttribute('title')
            ?? "#{$subject->getKey()}";
    }
}
