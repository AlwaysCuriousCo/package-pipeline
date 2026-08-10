<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OutgoingWebhook;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OutgoingWebhookPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OutgoingWebhook');
    }

    public function view(AuthUser $authUser, OutgoingWebhook $webhook): bool
    {
        return $authUser->can('View:OutgoingWebhook');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OutgoingWebhook');
    }

    public function update(AuthUser $authUser, OutgoingWebhook $webhook): bool
    {
        return $authUser->can('Update:OutgoingWebhook');
    }

    public function delete(AuthUser $authUser, OutgoingWebhook $webhook): bool
    {
        return $authUser->can('Delete:OutgoingWebhook');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OutgoingWebhook');
    }

    public function restore(AuthUser $authUser, OutgoingWebhook $webhook): bool
    {
        return $authUser->can('Restore:OutgoingWebhook');
    }

    public function forceDelete(AuthUser $authUser, OutgoingWebhook $webhook): bool
    {
        return $authUser->can('ForceDelete:OutgoingWebhook');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:OutgoingWebhook');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:OutgoingWebhook');
    }

    public function replicate(AuthUser $authUser, OutgoingWebhook $webhook): bool
    {
        return $authUser->can('Replicate:OutgoingWebhook');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:OutgoingWebhook');
    }
}
