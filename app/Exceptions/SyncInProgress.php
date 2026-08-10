<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Another sync already owns the package, so this one must not start.
 *
 * Its own type, rather than a plain failure, because a console command has to
 * tell an operator which of the two it hit: nothing went wrong, but nothing
 * was done either.
 */
class SyncInProgress extends RuntimeException {}
