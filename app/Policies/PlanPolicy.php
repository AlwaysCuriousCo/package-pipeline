<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Plan;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Shield's generated shape, kept by hand like the rest of them.
 *
 * Billing permissions are commercial power — a plan decides what is for
 * sale, a subscription decides who has paid access — so they are handed out
 * like Update:Team rather than bundled into anything package-shaped.
 */
class PlanPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Plan');
    }

    public function view(AuthUser $authUser, Plan $model): bool
    {
        return $authUser->can('View:Plan');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Plan');
    }

    public function update(AuthUser $authUser, Plan $model): bool
    {
        return $authUser->can('Update:Plan');
    }

    public function delete(AuthUser $authUser, Plan $model): bool
    {
        return $authUser->can('Delete:Plan');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Plan');
    }

    public function restore(AuthUser $authUser, Plan $model): bool
    {
        return $authUser->can('Restore:Plan');
    }

    public function forceDelete(AuthUser $authUser, Plan $model): bool
    {
        return $authUser->can('ForceDelete:Plan');
    }
}
