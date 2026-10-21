<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GitHubAppRegistration;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GitHubAppRegistrationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:GitHubAppRegistration');
    }

    public function view(AuthUser $authUser, GitHubAppRegistration $gitHubAppRegistration): bool
    {
        return $authUser->can('View:GitHubAppRegistration');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GitHubAppRegistration');
    }

    public function update(AuthUser $authUser, GitHubAppRegistration $gitHubAppRegistration): bool
    {
        return $authUser->can('Update:GitHubAppRegistration');
    }

    public function delete(AuthUser $authUser, GitHubAppRegistration $gitHubAppRegistration): bool
    {
        return $authUser->can('Delete:GitHubAppRegistration');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:GitHubAppRegistration');
    }

    public function restore(AuthUser $authUser, GitHubAppRegistration $gitHubAppRegistration): bool
    {
        return $authUser->can('Restore:GitHubAppRegistration');
    }

    public function forceDelete(AuthUser $authUser, GitHubAppRegistration $gitHubAppRegistration): bool
    {
        return $authUser->can('ForceDelete:GitHubAppRegistration');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:GitHubAppRegistration');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:GitHubAppRegistration');
    }

    public function replicate(AuthUser $authUser, GitHubAppRegistration $gitHubAppRegistration): bool
    {
        return $authUser->can('Replicate:GitHubAppRegistration');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:GitHubAppRegistration');
    }
}
