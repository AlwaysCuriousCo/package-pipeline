<?php

namespace App\Models\Concerns;

use App\Listeners\LogRoleChange;
use App\Support\AuditedBelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Records who was given reach, and to what.
 *
 * LogsAuditableChanges diffs attributes, and reach is not an attribute: a team
 * granted a whole repository, an account added to that team, and a deploy token
 * pointed at one package are all pivot rows. The scenario this exists for is an
 * account added to a team that holds the internal repository, used, and removed
 * again — three writes that moved nothing the attribute diff can see, and
 * afterwards a log that reads as though nothing happened.
 *
 * Applied to the *holders* of grants — the model on the owning side of the
 * pivot — rather than to the packages and repositories being granted, because
 * that is the side every screen edits from, and it is the side the question is
 * asked about ("what could this account reach").
 *
 * Every `belongsToMany` on a model using this is audited, with no allowlist to
 * keep in step. These models hold nothing else many-to-many; a relation added
 * to one later is by definition another thing it reaches, and would want
 * recording for the same reason. Roles are unaffected — Spatie declares them
 * `morphToMany`, and LogRoleChange has covered them since before this existed.
 *
 * @see AuditedBelongsToMany for why the relation is the seam
 * @see LogRoleChange for the same problem one pivot over
 */
trait LogsGrantChanges
{
    /**
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @param  string|class-string<Model>  $table
     * @param  string  $foreignPivotKey
     * @param  string  $relatedPivotKey
     * @param  string  $parentKey
     * @param  string  $relatedKey
     * @param  string|null  $relationName
     * @return BelongsToMany<TRelatedModel, TDeclaringModel>
     */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ) {
        return new AuditedBelongsToMany($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }
}
