<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeployToken;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DeployTokenPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DeployToken');
    }

    public function view(AuthUser $authUser, DeployToken $deployToken): bool
    {
        return $authUser->can('View:DeployToken');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DeployToken');
    }

    public function update(AuthUser $authUser, DeployToken $deployToken): bool
    {
        return $authUser->can('Update:DeployToken');
    }

    public function delete(AuthUser $authUser, DeployToken $deployToken): bool
    {
        return $authUser->can('Delete:DeployToken');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DeployToken');
    }

    public function restore(AuthUser $authUser, DeployToken $deployToken): bool
    {
        return $authUser->can('Restore:DeployToken');
    }

    public function forceDelete(AuthUser $authUser, DeployToken $deployToken): bool
    {
        return $authUser->can('ForceDelete:DeployToken');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DeployToken');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DeployToken');
    }

    public function replicate(AuthUser $authUser, DeployToken $deployToken): bool
    {
        return $authUser->can('Replicate:DeployToken');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DeployToken');
    }
}
