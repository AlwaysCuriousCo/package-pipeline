<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Filament reads this table for the panel's notification bell; the
        // shape is Laravel's own, so nothing here is app-specific.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // JSON rather than text: Filament filters the bell's rows with
            // `data->format`, and on PostgreSQL the `->>` that compiles to
            // has no meaning against a text column — every page of the panel
            // that renders the bell answers 500. MySQL and SQLite happen to
            // tolerate the text column, which is why only one deployment
            // target ever saw it.
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
