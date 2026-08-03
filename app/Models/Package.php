<?php

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['repository', 'latest_version', 'name', 'description', 'type'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /**
     * The distinct package types currently in use, keyed by value.
     *
     * `type` is a free-text column, so this powers the form's suggestions and
     * the table filter without hard-coding a vocabulary.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return static::query()
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type', 'type')
            ->all();
    }
}
