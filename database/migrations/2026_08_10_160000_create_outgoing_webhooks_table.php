<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where this registry posts to when something happens, as opposed to
        // the `webhook_*` columns on `packages`, which are how a provider posts
        // to us. Deliberately not per-package or per-repository: an endpoint is
        // an installation-wide subscription, because the things it subscribes
        // to are facts about the registry and a deploy pipeline that only
        // wanted one package can filter on the payload it is sent.
        Schema::create('outgoing_webhooks', function (Blueprint $table) {
            $table->id();

            // What an operator calls it in the panel. The URL is often a long
            // opaque token-bearing thing nobody can tell apart at a glance.
            $table->string('name');
            $table->string('url');

            // Encrypted at rest like every other credential here. Nullable
            // because an endpoint on a private network may legitimately want
            // no signature — and because storing an empty string would make
            // "unsigned" and "signed with nothing" the same value.
            $table->text('secret')->nullable();

            // Which events this endpoint wants, as the enum's wire values. A
            // JSON column rather than a pivot table: the set is small, fixed,
            // and only ever read whole — a join would buy nothing and the
            // fan-out reads every row on every event anyway.
            $table->json('events');

            $table->boolean('active')->default(true);

            // The last delivery's outcome, so the panel can answer "is this
            // thing working?" without a deliveries table. One row per delivery
            // would be the fastest-growing table in the schema after
            // `downloads`, needs its own pruning, and answers a question an
            // operator asks about the endpoint rather than about any one POST.
            $table->timestamp('last_delivered_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->text('last_error')->nullable();

            // The difference between "blipped once" and "has been dead for a
            // week", which a single status cannot express. Reset by any
            // delivery that succeeds.
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_webhooks');
    }
};
