<?php

namespace App\Services\Mirror;

use RuntimeException;

/**
 * A destination this registry will not make an outbound request to.
 *
 * Thrown rather than returned false because it happens in two places that
 * cannot share a return type — before a fetch, and from inside the HTTP
 * client's own stack on a redirect — and because the reason is worth carrying:
 * an operator staring at a refused archive needs to know it was refused for
 * resolving to 10.0.0.5, not that it "failed".
 */
final class EgressRefused extends RuntimeException {}
