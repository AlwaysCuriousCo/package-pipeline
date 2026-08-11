<?php

namespace App\Enums;

use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\AuthenticateComposer;
use App\Models\Package;
use App\Models\Token;
use App\Models\User;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Gate;

/**
 * What an access token may do.
 *
 * Two families, deliberately disjoint. The `repository:*` abilities govern the
 * Composer protocol — resolving metadata, downloading dists, publishing an
 * artifact — which is what a consuming project's composer.json and a release
 * pipeline present. The `api:*` abilities govern the management API, which
 * describes and administers the registry rather than serving it.
 *
 * Keeping them apart is the point: a token pasted into every developer's
 * `auth.json` so `composer install` works must not, by the same act, be able to
 * enumerate the registry's administration or delete a package out from under
 * the projects that depend on it. Nothing here is implied by anything else —
 * each endpoint names the one ability it needs, exactly as the upload endpoint
 * has always required `repository:write` without accepting `repository:read`.
 *
 * @see AuthenticateComposer
 * @see AuthenticateApi
 */
enum TokenAbility: string implements HasLabel
{
    case RepositoryRead = 'repository:read';
    case RepositoryWrite = 'repository:write';
    case ApiRead = 'api:read';
    case ApiWrite = 'api:write';
    case ApiDelete = 'api:delete';

    /**
     * The abilities this user may put on a token they issue for themselves.
     *
     * A personal access token is its owner reaching the registry through a
     * machine, so it must never become a way around their role: what the panel
     * refuses them, a token they issued has to refuse too.
     *
     * Most of what is here is not a role's decision at all. What a read returns,
     * and which repositories an upload or a sync may touch, is settled by the
     * grants the token inherits from its owner — a question every panel user
     * has an answer to — so those stay on offer to everybody who reaches the
     * page. Deleting is the exception and the reason this exists: the panel
     * refuses it outright to a role without Delete:Package, and a checkbox must
     * not be a second way to ask.
     *
     * This only decides what may be *issued*. A token outlives the role that
     * issued it, so every endpoint asks again, per request and per record.
     *
     * @see Token::mayDeletePackage()
     *
     * @return list<self>
     */
    public static function issuableBy(User $user): array
    {
        // A list already: the only case this drops is the last one.
        return array_filter(
            self::cases(),
            fn (self $ability): bool => $ability !== self::ApiDelete
                // Asked with an unsaved package because no record is named
                // yet, and none has to be: PackagePolicy::delete() answers
                // from the permission alone, never from the row.
                || Gate::forUser($user)->allows('delete', new Package),
        );
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::RepositoryRead => 'Composer read — install packages',
            self::RepositoryWrite => 'Composer write — publish artifact uploads',
            self::ApiRead => 'API read — list packages, versions and repositories',
            self::ApiWrite => 'API write — create packages and trigger syncs',
            self::ApiDelete => 'API delete — delete packages',
        };
    }
}
