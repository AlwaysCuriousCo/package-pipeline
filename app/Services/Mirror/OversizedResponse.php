<?php

namespace App\Services\Mirror;

use RuntimeException;

/**
 * An upstream answered with more bytes than this registry will accept.
 *
 * Distinct from the transfer simply failing, because it means something
 * different: the upstream is answering, and what it is answering is not worth
 * having. Nothing about it says the upstream is unreachable, so nothing about
 * it should stop the next package from being asked for.
 */
final class OversizedResponse extends RuntimeException {}
