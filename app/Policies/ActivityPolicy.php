<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Activity;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Reading the audit log is a permission; writing to it is not a thing anybody
 * can be granted.
 *
 * Shield derives the same twelve permissions for every resource, so the Roles
 * screen offers Create/Update/Delete for this one too. They are refused here
 * regardless of who holds them: an entry the people it records could edit is
 * an entry nobody could read as evidence, and that has to be a property of the
 * app rather than of how carefully a role was ticked.
 *
 * **What this buys is that the log cannot be altered through the app** — no
 * more, and it is worth being exact about the difference. There is no hash
 * chain, no signature and no append-only storage; the rows are ordinary rows,
 * and App\Models\Activity prunes them on a retention policy. Anyone with
 * database or shell access rewrites the table freely, and on a self-hosted
 * single box that is the same person who administers the panel. An HMAC would
 * not change that — its key would sit in the `.env` beside the database — so
 * tamper-*evidence*, as opposed to tamper-resistance against the app's own
 * users, is a deployment decision: ship the entries somewhere the registry's
 * administrator cannot reach. The README says as much under "Roles and
 * permissions".
 *
 * Stated as explicit `false` rather than left out: Filament allows an action
 * whose policy method is simply absent, so an absent method here would have
 * meant precisely the opposite of what it looks like.
 *
 * The read side is a flat permission check on purpose, and not the same shape
 * as PackagePolicy: holding it is registry-wide and is narrowed by no grant,
 * team or `Unscoped:Package`. ActivityResource's docblock says why, and the
 * Roles screen says so to the person granting it.
 */
class ActivityPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Activity');
    }

    public function view(AuthUser $authUser, Activity $activity): bool
    {
        return $authUser->can('View:Activity');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, Activity $activity): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Activity $activity): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Activity $activity): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function replicate(AuthUser $authUser, Activity $activity): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
