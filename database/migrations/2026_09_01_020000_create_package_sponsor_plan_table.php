<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The sponsorship tiers a package's page offers: each row is one plan
        // the page sells as a tier, and a plan may carry its own entitlements
        // — a perk-bearing tier is just a plan that grants something.
        Schema::create('package_sponsor_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            $table->unique(['package_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_sponsor_plan');
    }
};
