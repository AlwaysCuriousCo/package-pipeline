<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\ReservedVendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A vendor prefix one Composer repository owns.
 *
 * This is the server half of a dependency-confusion defence. A consuming
 * project that lists this registry *and* packagist.org resolves a name from
 * whichever repository answers for it; if anyone can publish `acme/anything`
 * here, then a mistake — or a compromised credential — can put a package under
 * a vendor prefix that a whole organisation trusts. Reserving `acme` says: this
 * registry serves that vendor from exactly one repository, and nothing else may
 * introduce a name under it — including a package added to a second repository,
 * which is introducing the name there. @see Package::serveFrom()
 *
 * It bounds *where* a name may appear, not *who* may publish. Who is still the
 * grant system's answer — a principal has to be able to write into the owning
 * repository as well, which is checked separately and unchanged.
 *
 * The consumer side matters at least as much and this cannot do it: Composer
 * has to be told to treat this registry as canonical for the vendor, or a
 * public package of the same name can still win. See docs/dependency-confusion.md.
 */
#[Fillable(['vendor'])]
class ReservedVendor extends Model
{
    /** @use HasFactory<ReservedVendorFactory> */
    use HasFactory, LogsAuditableChanges;

    /**
     * All of it. A reservation is two facts, and which vendor moved to which
     * repository is the whole of what an investigator would need.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['vendor', 'repository_id'];
    }

    protected static function booted(): void
    {
        // Composer names are lowercase, and a reservation typed in mixed case
        // would silently protect nothing.
        static::saving(fn (self $reservation) => $reservation->vendor = self::normalize((string) $reservation->vendor));
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * The vendor prefix as it is stored and compared.
     *
     * Accepts what an operator is likely to type: the bare vendor, the pattern
     * form Composer uses in `exclude` (`acme/*`), or a whole package name — all
     * of which mean the same claim.
     */
    public static function normalize(string $vendor): string
    {
        return mb_strtolower(trim(Str::before(trim($vendor), '/')));
    }

    /**
     * The reservation that stands in the way of publishing `$name` in the given
     * repository, or null when nothing does.
     *
     * Null for an unreserved vendor — reservation is opt-in, and a registry
     * that has never used it behaves exactly as it did before — and null for
     * the repository that owns it, which is the one place the name belongs.
     */
    public static function conflictFor(string $name, ?int $repositoryId): ?self
    {
        $vendor = self::normalize($name);

        if ($vendor === '') {
            return null;
        }

        $reservation = static::query()->where('vendor', $vendor)->first();

        return $reservation instanceof self && $reservation->repository_id !== $repositoryId
            ? $reservation
            : null;
    }

    /**
     * Why a name was refused, in the words the panel, the API, the upload
     * endpoint and a failed sync all show.
     */
    public function refusal(string $name): string
    {
        return "The vendor \"{$this->vendor}\" is reserved by the \"{$this->repository->name}\" Composer repository, "
            ."so \"{$name}\" cannot be published here. Publish it there, or release the reservation.";
    }
}
