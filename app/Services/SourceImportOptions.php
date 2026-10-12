<?php

namespace App\Services;

/** Repository picker results attached to one HTTP request, never a later grant check. */
final class SourceImportOptions
{
    /** @var array<string, array<string, string>> */
    public array $inline = [];

    /** @var array<string, list<string>> */
    public array $likely = [];
}
