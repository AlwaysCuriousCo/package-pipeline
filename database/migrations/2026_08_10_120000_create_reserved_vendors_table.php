<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reserved_vendors', function (Blueprint $table) {
            $table->id();

            // The Composer repository that owns the vendor prefix. Deleting a
            // repository releases its reservations with it — there is nothing
            // left for them to protect.
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();

            // The vendor half of a Composer name ("acme" in "acme/widgets"),
            // stored lowercased because that is the only spelling Composer
            // accepts. Unique across the whole registry rather than per
            // repository: two repositories claiming one vendor is precisely
            // the ambiguity a reservation exists to remove.
            $table->string('vendor', 128)->unique();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reserved_vendors');
    }
};
