<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Shield's generated shape, kept by hand like the rest of them.
 *
 * Billing permissions are commercial power — a plan decides what is for
 * sale, a subscription decides who has paid access — so they are handed out
 * like Update:Team rather than bundled into anything package-shaped.
 */
class InvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Invoice');
    }

    public function view(AuthUser $authUser, Invoice $model): bool
    {
        return $authUser->can('View:Invoice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Invoice');
    }

    public function update(AuthUser $authUser, Invoice $model): bool
    {
        return $authUser->can('Update:Invoice');
    }

    public function delete(AuthUser $authUser, Invoice $model): bool
    {
        return $authUser->can('Delete:Invoice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Invoice');
    }

    public function restore(AuthUser $authUser, Invoice $model): bool
    {
        return $authUser->can('Restore:Invoice');
    }

    public function forceDelete(AuthUser $authUser, Invoice $model): bool
    {
        return $authUser->can('ForceDelete:Invoice');
    }
}
