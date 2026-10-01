<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repository_upstreams', function (Blueprint $table) {
            // Composer name patterns this upstream may answer for, such as
            // `livewire/flux*`. Null keeps every existing upstream answering
            // for anything, as it always has.
            $table->json('packages')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repository_upstreams', function (Blueprint $table) {
            $table->dropColumn('packages');
        });
    }
};
