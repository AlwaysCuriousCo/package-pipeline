<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One served dist download. Append-only: rows are written by the download
 * listener and only ever read after that, so there is no updated_at.
 */
#[Fillable(['package_id', 'package_version_id', 'version', 'token_prefix', 'created_at'])]
class Download extends Model
{
    public const ?string UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<PackageVersion, $this>
     */
    public function packageVersion(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class);
    }
}
