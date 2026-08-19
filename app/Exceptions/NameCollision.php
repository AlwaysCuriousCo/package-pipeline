<?php

namespace App\Exceptions;

use App\Models\Package;
use RuntimeException;

/**
 * A package was about to be served from a repository that already answers for
 * its name.
 *
 * One Composer repository serves one package per name — everything on the
 * Composer surface resolves a name inside a repository, and two rows sharing
 * one there is a lookup with no right answer. The pivot's
 * (repository_id, package_name) unique index is the backstop; this is what the
 * paths that add a package to a repository raise first, so the refusal reads as
 * a decision rather than a query error.
 *
 * @see Package::serveFrom()
 */
class NameCollision extends RuntimeException {}
