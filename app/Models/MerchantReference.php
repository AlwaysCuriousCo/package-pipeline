<?php

namespace App\Models;

use App\Enums\MerchantProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The mapping between a local row and its counterpart at a merchant.
 *
 * The local catalog is the source of truth; this is how a pushed Plan
 * remembers its Stripe Product id, and how an incoming webhook naming a
 * Stripe object finds the local row it is about. One local row can be known
 * to several merchants at once — that is the portability story in one table.
 */
#[Fillable(['referenceable_type', 'referenceable_id', 'merchant', 'external_id', 'synced_at'])]
class MerchantReference extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant' => MerchantProvider::class,
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function referenceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The local row a merchant's object id refers to, or null when the
     * merchant is talking about something this app never synced.
     */
    public static function resolve(MerchantProvider $merchant, string $externalId): ?Model
    {
        return static::query()
            ->where('merchant', $merchant)
            ->where('external_id', $externalId)
            ->first()
            ?->referenceable;
    }

    /**
     * Record (or refresh) the mapping for a local row at a merchant.
     */
    public static function remember(Model $local, MerchantProvider $merchant, string $externalId): self
    {
        return static::query()->updateOrCreate(
            [
                'referenceable_type' => $local->getMorphClass(),
                'referenceable_id' => $local->getKey(),
                'merchant' => $merchant,
            ],
            ['external_id' => $externalId, 'synced_at' => now()],
        );
    }

    /** The external id a local row is known by at a merchant, if it is. */
    public static function externalId(Model $local, MerchantProvider $merchant): ?string
    {
        return static::query()
            ->where('referenceable_type', $local->getMorphClass())
            ->where('referenceable_id', $local->getKey())
            ->where('merchant', $merchant)
            ->value('external_id');
    }
}
