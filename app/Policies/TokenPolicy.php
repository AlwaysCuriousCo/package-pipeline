<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Token;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TokenPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Token');
    }

    public function view(AuthUser $authUser, Token $token): bool
    {
        return $authUser->can('View:Token');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Token');
    }

    public function update(AuthUser $authUser, Token $token): bool
    {
        return $authUser->can('Update:Token');
    }

    public function delete(AuthUser $authUser, Token $token): bool
    {
        return $authUser->can('Delete:Token');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Token');
    }

    public function restore(AuthUser $authUser, Token $token): bool
    {
        return $authUser->can('Restore:Token');
    }

    public function forceDelete(AuthUser $authUser, Token $token): bool
    {
        return $authUser->can('ForceDelete:Token');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Token');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Token');
    }

    public function replicate(AuthUser $authUser, Token $token): bool
    {
        return $authUser->can('Replicate:Token');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Token');
    }
}
