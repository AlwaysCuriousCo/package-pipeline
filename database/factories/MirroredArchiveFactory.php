<?php

namespace Database\Factories;

use App\Models\MirroredArchive;
use App\Models\Upstream;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MirroredArchive>
 */
class MirroredArchiveFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reference = str_repeat('c', 40);

        return [
            'upstream_id' => Upstream::factory(),
            'name' => 'vendor/mirrored',
            'reference' => $reference,
            'path' => "mirror/1/vendor/mirrored/{$reference}.zip",
            'shasum' => sha1('mirrored-zip-bytes'),
            'size' => 1024,
            'used_at' => now(),
        ];
    }
}
