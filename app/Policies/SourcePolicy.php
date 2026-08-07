<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Source;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SourcePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Source');
    }

    public function view(AuthUser $authUser, Source $source): bool
    {
        return $authUser->can('View:Source');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Source');
    }

    public function update(AuthUser $authUser, Source $source): bool
    {
        return $authUser->can('Update:Source');
    }

    public function delete(AuthUser $authUser, Source $source): bool
    {
        return $authUser->can('Delete:Source');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Source');
    }

    public function restore(AuthUser $authUser, Source $source): bool
    {
        return $authUser->can('Restore:Source');
    }

    public function forceDelete(AuthUser $authUser, Source $source): bool
    {
        return $authUser->can('ForceDelete:Source');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Source');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Source');
    }

    public function replicate(AuthUser $authUser, Source $source): bool
    {
        return $authUser->can('Replicate:Source');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Source');
    }
}
