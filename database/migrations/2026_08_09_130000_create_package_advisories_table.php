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
        Schema::create('package_advisories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            // The identifier Composer echoes back, and the only handle a
            // consuming project has for silencing one advisory:
            // `composer audit --ignore <id>` and the `audit.ignore` config key
            // both key on it. Unique registry-wide rather than per package,
            // because that is the scope the consumer's ignore list lives in —
            // two advisories sharing an id would be silenced together.
            $table->string('advisory_id')->unique();

            // Where this record came from. Null means an admin typed it into
            // the panel; a feed importer would stamp its own name here (say
            // "FriendsOfPHP/security-advisories" or "GitHub"), so the two can
            // coexist in one table and a re-import can replace only its own
            // rows without touching what was recorded by hand. Also what
            // Composer prints as the advisory's source name.
            $table->string('source')->nullable();

            $table->string('title');

            // A Composer constraint, not a version: an advisory covers a range
            // ("<1.2.3|>=2.0,<2.0.4"), and Composer parses this to decide
            // whether the version actually installed is affected. Stored as
            // written so the string a human reasoned about is the string that
            // gets matched.
            $table->string('affected_versions');

            // Both optional in Composer's advisory shape, and genuinely absent
            // for an in-house package: a private package rarely has a CVE, and
            // there may be nowhere public to link to.
            $table->string('cve')->nullable();
            $table->string('link')->nullable();

            // One of low/medium/high/critical, which is the vocabulary
            // `composer audit --ignore-severity` accepts. Nullable because
            // Composer treats an unrated advisory as reportable rather than as
            // harmless, which is the right default for one recorded in a hurry.
            $table->string('severity')->nullable();

            // Required by Composer for a *full* advisory: an advisory missing
            // title, source or date is only a "partial" one, and `composer
            // audit` refuses to load those outright. So this is not nullable —
            // a row without it would be published as something no audit can
            // read.
            $table->timestamp('reported_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_advisories');
    }
};
