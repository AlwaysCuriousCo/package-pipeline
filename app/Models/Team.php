<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group of people that holds package and repository grants.
 *
 * The per-user grants this sits beside scale linearly with headcount:
 * onboarding somebody means re-granting whatever the last person was granted,
 * and the list of who can reach what lives nowhere but in the pivot tables. A
 * team is the same grants held once, by a name that says why.
 *
 * It adds reach and never removes it. A user's effective access is their own
 * grants *plus* their teams' — a user in no team is unaffected by any of this,
 * and removing somebody from a team takes back only what the team gave.
 *
 * @see Package::scopeVisibleToUser() where that union is applied
 * @see docs/teams.md
 */
#[Fillable(['name', 'description'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, LogsAuditableChanges;

    /**
     * The team's identity. Its grants and membership are relations rather than
     * attributes and are not audited here; what changes them is the panel,
     * and what a team reaches is answerable from the pivots at any time.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['name'];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Individual packages every member of this team may see.
     *
     * @return BelongsToMany<Package, $this>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class);
    }

    /**
     * Repositories every member of this team may see wholesale.
     *
     * @return BelongsToMany<Repository, $this>
     */
    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class);
    }
}
