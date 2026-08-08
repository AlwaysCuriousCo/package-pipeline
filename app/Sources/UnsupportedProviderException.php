<?php

namespace App\Sources;

use RuntimeException;

/**
 * Raised by the stub clients for providers the enum names but nothing
 * implements yet. The message is the roadmap: implement the client, the
 * rest of the app already speaks the contract.
 */
class UnsupportedProviderException extends RuntimeException {}
