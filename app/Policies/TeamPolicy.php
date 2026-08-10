<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Team;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Shield's generated shape, kept by hand like the rest of them.
 *
 * A team holds grants, so managing one is granting access to whatever it
 * holds — which is why this sits with the Users and Roles permissions rather
 * than with the Packages ones, and why nothing here consults a team's own
 * membership: being *in* a team says nothing about being allowed to change it.
 */
class TeamPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Team');
    }

    public function view(AuthUser $authUser, Team $team): bool
    {
        return $authUser->can('View:Team');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Team');
    }

    public function update(AuthUser $authUser, Team $team): bool
    {
        return $authUser->can('Update:Team');
    }

    public function delete(AuthUser $authUser, Team $team): bool
    {
        return $authUser->can('Delete:Team');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Team');
    }

    public function restore(AuthUser $authUser, Team $team): bool
    {
        return $authUser->can('Restore:Team');
    }

    public function forceDelete(AuthUser $authUser, Team $team): bool
    {
        return $authUser->can('ForceDelete:Team');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Team');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Team');
    }

    public function replicate(AuthUser $authUser, Team $team): bool
    {
        return $authUser->can('Replicate:Team');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Team');
    }
}
