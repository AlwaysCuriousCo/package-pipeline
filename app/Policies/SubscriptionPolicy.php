<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Subscription;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Shield's generated shape, kept by hand like the rest of them.
 *
 * Billing permissions are commercial power — a plan decides what is for
 * sale, a subscription decides who has paid access — so they are handed out
 * like Update:Team rather than bundled into anything package-shaped.
 */
class SubscriptionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Subscription');
    }

    public function view(AuthUser $authUser, Subscription $model): bool
    {
        return $authUser->can('View:Subscription');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Subscription');
    }

    public function update(AuthUser $authUser, Subscription $model): bool
    {
        return $authUser->can('Update:Subscription');
    }

    public function delete(AuthUser $authUser, Subscription $model): bool
    {
        return $authUser->can('Delete:Subscription');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Subscription');
    }

    public function restore(AuthUser $authUser, Subscription $model): bool
    {
        return $authUser->can('Restore:Subscription');
    }

    public function forceDelete(AuthUser $authUser, Subscription $model): bool
    {
        return $authUser->can('ForceDelete:Subscription');
    }
}
