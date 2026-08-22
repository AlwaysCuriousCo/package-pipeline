<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BillingCustomer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Shield's generated shape, kept by hand like the rest of them.
 *
 * Billing permissions are commercial power — a plan decides what is for
 * sale, a subscription decides who has paid access — so they are handed out
 * like Update:Team rather than bundled into anything package-shaped.
 */
class BillingCustomerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:BillingCustomer');
    }

    public function view(AuthUser $authUser, BillingCustomer $model): bool
    {
        return $authUser->can('View:BillingCustomer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:BillingCustomer');
    }

    public function update(AuthUser $authUser, BillingCustomer $model): bool
    {
        return $authUser->can('Update:BillingCustomer');
    }

    public function delete(AuthUser $authUser, BillingCustomer $model): bool
    {
        return $authUser->can('Delete:BillingCustomer');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:BillingCustomer');
    }

    public function restore(AuthUser $authUser, BillingCustomer $model): bool
    {
        return $authUser->can('Restore:BillingCustomer');
    }

    public function forceDelete(AuthUser $authUser, BillingCustomer $model): bool
    {
        return $authUser->can('ForceDelete:BillingCustomer');
    }
}
