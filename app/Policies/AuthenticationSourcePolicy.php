<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuthenticationSource;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AuthenticationSourcePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AuthenticationSource');
    }

    public function view(AuthUser $authUser, AuthenticationSource $authenticationSource): bool
    {
        return $authUser->can('View:AuthenticationSource');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AuthenticationSource');
    }

    public function update(AuthUser $authUser, AuthenticationSource $authenticationSource): bool
    {
        return $authUser->can('Update:AuthenticationSource');
    }

    public function delete(AuthUser $authUser, AuthenticationSource $authenticationSource): bool
    {
        return $authUser->can('Delete:AuthenticationSource');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AuthenticationSource');
    }

    public function restore(AuthUser $authUser, AuthenticationSource $authenticationSource): bool
    {
        return $authUser->can('Restore:AuthenticationSource');
    }

    public function forceDelete(AuthUser $authUser, AuthenticationSource $authenticationSource): bool
    {
        return $authUser->can('ForceDelete:AuthenticationSource');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AuthenticationSource');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AuthenticationSource');
    }

    public function replicate(AuthUser $authUser, AuthenticationSource $authenticationSource): bool
    {
        return $authUser->can('Replicate:AuthenticationSource');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AuthenticationSource');
    }
}
