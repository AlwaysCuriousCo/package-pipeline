<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\UpstreamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another Composer repository this one mirrors packages from on demand.
 *
 * A repository with no upstream rows behaves exactly as this registry always
 * has: it serves what it publishes and 404s for everything else. Adding one —
 * usually packagist.org — turns the repository into a single URL a consuming
 * project can resolve its *whole* dependency graph through, public packages
 * included, which is the point: the graph then survives packagist.org having a
 * bad day, and every byte of it is cached on infrastructure the operator owns.
 *
 * Mirroring is strictly on-demand caching. Nothing is fetched until a Composer
 * client asks for a name this repository does not publish itself, and a local
 * package always wins — see MirrorService for why that rule is unconditional.
 *
 * @see docs/mirroring.md
 */
#[Fillable(['name', 'url', 'token', 'enabled', 'position'])]
class Upstream extends Model
{
    /** @use HasFactory<UpstreamFactory> */
    use HasFactory, LogsAuditableChanges;

    protected $table = 'repository_upstreams';

    /**
     * The column default alone leaves a freshly created row holding null in
     * memory, and whether the upstream is live is read straight off the record
     * the panel just created.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
        'position' => 0,
    ];

    /**
     * Where an upstream points and whether it is live — the two facts that
     * decide what a consumer's `composer update` reaches out to. The token is
     * excluded by the trait's second gate anyway; it is left out here as well
     * so the omission is stated rather than relied upon.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['name', 'url', 'enabled', 'repository_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A URL with a trailing slash and one without are the same upstream,
        // and every path below joins onto it — so it is normalised once here
        // rather than defended against everywhere it is used.
        static::saving(fn (self $upstream) => $upstream->url = rtrim(trim((string) $upstream->url), '/'));
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * An absolute URL on this upstream.
     */
    public function url(string $suffix = ''): string
    {
        return $this->url.$suffix;
    }
}
