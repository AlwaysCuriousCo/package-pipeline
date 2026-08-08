<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Repository;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RepositoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Repository');
    }

    public function view(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('View:Repository');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Repository');
    }

    public function update(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('Update:Repository');
    }

    public function delete(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('Delete:Repository');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Repository');
    }

    public function restore(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('Restore:Repository');
    }

    public function forceDelete(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('ForceDelete:Repository');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Repository');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Repository');
    }

    public function replicate(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('Replicate:Repository');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Repository');
    }
}
