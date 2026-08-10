<?php

namespace App\Listeners;

use Spatie\Permission\Contracts\Role;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\PermissionRegistrar;

/**
 * Records a role being granted or taken away.
 *
 * Roles are a pivot, not a column, so the attribute diff that catches every
 * other change here sees nothing — and a role is the single biggest change
 * anyone can make to an account: `super_admin` is exactly the permission list
 * that lets somebody delete every package in the registry.
 *
 * Spatie's events are the only seam every path goes through. The panel's user
 * form syncs the relationship, the invite action calls syncRoles(),
 * `admin:create` and `user:add` assign from the console, and a first SSO
 * sign-in can grant the source's default role — all of them land here.
 * Enabled in config/permission.php, which ships with events off.
 */
class LogRoleChange
{
    public function handle(RoleAttachedEvent|RoleDetachedEvent $event): void
    {
        $roles = $this->roleNames($event->rolesOrIds);

        if ($roles === []) {
            return;
        }

        $granted = $event instanceof RoleAttachedEvent;

        activity('audit')
            ->performedOn($event->model)
            ->event($granted ? 'role_granted' : 'role_revoked')
            ->withProperties(['roles' => $roles])
            ->log($granted ? 'role_granted' : 'role_revoked');
    }

    /**
     * The role names behind whatever the event carried.
     *
     * Spatie documents the payload as "ids, or models, or a collection —
     * inspect before using", and its own HasRoles passes ids. Names are what
     * the log has to hold either way: a role id means nothing once the role
     * is renamed or deleted, which is exactly the sort of tidying that
     * happens between a change and the question about it.
     *
     * @return list<string>
     */
    private function roleNames(mixed $rolesOrIds): array
    {
        $names = [];
        $ids = [];

        foreach (is_iterable($rolesOrIds) ? $rolesOrIds : [$rolesOrIds] as $role) {
            match (true) {
                $role instanceof Role => $names[] = (string) $role->name,
                is_string($role) => $names[] = $role,
                default => $ids[] = $role,
            };
        }

        if ($ids !== []) {
            $names = [...$names, ...app(PermissionRegistrar::class)->getRoleClass()::query()
                ->whereKey($ids)
                ->pluck('name')
                ->all()];
        }

        return array_values(array_unique(array_map(strval(...), $names)));
    }
}
