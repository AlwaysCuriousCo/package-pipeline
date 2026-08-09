<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PackageAdvisory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Advisories are curated from the package they belong to, so they borrow that
 * package's permissions instead of introducing their own.
 *
 * Deliberately not a Shield-generated policy: Shield derives permissions from
 * panel resources, and advisories have no resource of their own — a
 * hand-written set of `*:PackageAdvisory` permissions would exist only as rows
 * an admin has to remember to grant alongside the package ones they already
 * did. "May edit the package" is the same decision as "may say the package is
 * vulnerable", one screen apart.
 *
 * Which advisories a user can reach is a separate question, answered upstream
 * by PackageResource's visibleToUser() scoping: a package outside their grants
 * has no page for this relation manager to appear on.
 */
class PackageAdvisoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('View:Package');
    }

    public function view(AuthUser $authUser, PackageAdvisory $advisory): bool
    {
        return $authUser->can('View:Package');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Package');
    }

    public function update(AuthUser $authUser, PackageAdvisory $advisory): bool
    {
        return $authUser->can('Update:Package');
    }

    public function delete(AuthUser $authUser, PackageAdvisory $advisory): bool
    {
        return $authUser->can('Update:Package');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Package');
    }
}
