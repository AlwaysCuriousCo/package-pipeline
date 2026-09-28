<?php

namespace App\Services\Mirror;

use RuntimeException;

/**
 * An upstream said "not found" at a URL nobody confirmed it serves.
 *
 * The answer to a guessed layout: when packages.json could not be read, the
 * /p2 template is only a guess, and a 404 from it may mean the package is
 * missing or that the upstream does not speak v2 at all. Neither half is
 * known, so the package is not recorded as missing — and the upstream is not
 * put in backoff either, because it is answering, and other names may
 * resolve through the same guess.
 */
final class UnconfirmedAbsence extends RuntimeException {}
