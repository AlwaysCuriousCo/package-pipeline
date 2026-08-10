<?php

namespace App\Support;

use App\Models\Concerns\LogsGrantChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A many-to-many relation that files an audit entry when its membership moves.
 *
 * Laravel has no model event for a pivot write. `attach()` and `detach()` go
 * straight to the query builder — `newPivotStatement()->insert(...)` and
 * `newPivotQuery()->delete()` — and neither `sync()` nor `toggle()` adds one on
 * top; there is no `pivotAttached` anywhere in the framework to listen for. The
 * relation object is therefore the seam, and it is a complete one: `sync()`,
 * `syncWithoutDetaching()`, `syncWithPivotValues()` and `toggle()` all reach
 * the database through the two methods below, so overriding them covers every
 * caller including Filament's relationship selects, which sync.
 *
 * What it records is the same shape LogRoleChange records, for the same
 * reason: a grant is a pivot row, the attribute diff that catches every other
 * change cannot see it, and "who could reach this package in March" is a
 * question asked long after the row was written.
 *
 * @see LogsGrantChanges for which models install this
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, Pivot>
 */
class AuditedBelongsToMany extends BelongsToMany
{
    /**
     * @param  mixed  $ids
     * @param  array<string, mixed>  $attributes
     * @param  bool  $touch
     */
    public function attach($ids, array $attributes = [], $touch = true): void
    {
        // Logged after the write, so a duplicate-key failure files nothing.
        parent::attach($ids, $attributes, $touch);

        $this->record('grant_added', $this->parseIds($ids));
    }

    /**
     * @param  mixed  $ids
     * @param  bool  $touch
     */
    public function detach($ids = null, $touch = true): int
    {
        // Read before the delete, and read from the pivot rather than from the
        // argument: `detach()` accepts ids that were never attached, and a
        // bare `detach()` means "all of them" and names none.
        $removed = $this->attachedIds($ids === null ? null : $this->parseIds($ids));

        $detached = parent::detach($ids, $touch);

        $this->record('grant_removed', $removed);

        return $detached;
    }

    /**
     * The ids of the rows this relation currently holds, optionally narrowed
     * to the ones a caller named.
     *
     * @param  list<mixed>|null  $ids
     * @return list<mixed>
     */
    private function attachedIds(?array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $query = $this->newPivotQuery();

        if ($ids !== null) {
            $query->whereIn($this->getQualifiedRelatedPivotKeyName(), $ids);
        }

        return $query->pluck($this->relatedPivotKey)->all();
    }

    /**
     * File one entry per call rather than one per record: a form save that
     * swaps four repositories for five is one decision somebody made, and five
     * rows would read as five.
     *
     * @param  list<mixed>  $ids
     */
    private function record(string $event, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        activity('audit')
            ->performedOn($this->parent)
            ->event($event)
            ->withProperties([
                // The relation as the panel names it — "packages", "teams" —
                // because the pivot table name says nothing about which side
                // of it the change was made from.
                'grant' => $this->getRelationName(),
                'records' => $this->names($ids),
            ])
            ->log($event);
    }

    /**
     * The granted records by name.
     *
     * Names rather than ids, for the reason LogRoleChange gives: an id means
     * nothing once the row is renamed or deleted, and deletion is exactly the
     * sort of tidying that happens between a change and the question about it.
     * A record that has already gone keeps its id, which is at least a handle
     * on the rest of the log.
     *
     * @param  list<mixed>  $ids
     * @return list<string>
     */
    private function names(array $ids): array
    {
        $named = $this->getRelated()->newQuery()
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (Model $record): array => [
                (string) $record->getKey() => (string) ($record->getAttribute('name') ?? $record->getAttribute('email') ?? "#{$record->getKey()}"),
            ]);

        return array_map(
            fn (mixed $id): string => $named->get((string) $id, "#{$id}"),
            $ids,
        );
    }
}
