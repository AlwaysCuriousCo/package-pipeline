<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What an upstream answered for one package name, so the same question
        // is not asked again on the next request. This is the whole of the
        // mirror's metadata cache: no row exists until a Composer client asks
        // for a name, and every row can be deleted at any time without losing
        // anything the upstream would not hand back.
        Schema::create('mirrored_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('upstream_id')->constrained('repository_upstreams')->cascadeOnDelete();

            // The Composer name, lowercased. Composer asks for releases and
            // branches as two separate documents (`vendor/name.json` and
            // `vendor/name~dev.json`) and an upstream may have one and not the
            // other, so the flavour is part of the identity rather than two
            // columns on one row.
            $table->string('name');
            $table->boolean('is_dev');

            // The upstream's document, stored exactly as it arrived. Rewriting
            // the dist URLs before storing would make the bytes we hold
            // disagree with the validators we hold them under, and would bake
            // this repository's current mount into a cache that outlives it —
            // so the rewrite happens on the way out instead.
            //
            // Null is the negative cache: the upstream has no such package.
            // That has to be remembered too, or a name nobody publishes costs
            // an upstream round trip on every resolve of every project that
            // mentions it. See the much shorter TTL it is held under.
            $table->longText('payload')->nullable();

            // A digest of the payload, which is what the served ETag is cut
            // from. Content-derived on purpose: revalidation moves fetched_at
            // on every TTL expiry, and an ETag built from *that* would tell
            // every client its copy was stale each hour for bytes that never
            // changed.
            $table->string('digest', 32)->nullable();

            // The upstream's own validators, replayed to it as If-None-Match
            // and If-Modified-Since. These are the upstream's strings and mean
            // nothing to our clients; they exist so that a revalidation of an
            // unchanged package costs a 304 with no body.
            $table->string('upstream_etag')->nullable();
            $table->string('upstream_last_modified')->nullable();

            // When the upstream was last asked anything about this name —
            // moved by a 304 as much as by a new body, because both are the
            // upstream confirming what we hold. This is what the TTL is
            // measured from.
            $table->timestamp('fetched_at');

            // When the bytes last actually changed, which is what clients are
            // told as Last-Modified. Null for a negative entry.
            $table->timestamp('changed_at')->nullable();

            // When this was last served. Retention is measured on use rather
            // than on age: a package every build in the company installs
            // should never be evicted, however long ago it was first cached,
            // and one pulled in once by a spike should not live forever.
            $table->timestamp('used_at');

            $table->timestamps();

            $table->unique(['upstream_id', 'name', 'is_dev']);

            // Both halves of a lookup: `name` alone is how a repository with
            // several upstreams finds every candidate row in one query.
            $table->index('name');
            $table->index('used_at');
        });

        // An upstream archive that has been fetched, verified and stored.
        //
        // Deliberately not a package_versions row. A version row is a thing
        // this registry publishes — it carries a source, a sync history, an
        // audit trail and a place in the panel — and a mirrored zip is none of
        // those: it is a byte-for-byte copy of somebody else's release, held
        // only so the second install does not go to the internet for it.
        // Folding the two together would put every cached transitive
        // dependency of every project into the package list.
        Schema::create('mirrored_archives', function (Blueprint $table) {
            $table->id();

            $table->foreignId('upstream_id')->constrained('repository_upstreams')->cascadeOnDelete();

            $table->string('name');

            // The upstream's `dist.reference` — a commit sha in practice, and
            // the segment the dist URL is addressed by. A tag and a branch
            // pointing at one commit share an archive, which is why this is
            // keyed by reference rather than by version.
            $table->string('reference');

            // Where the zip landed on the dist disk, under a prefix nothing
            // that reconciles *published* archives ever lists. See
            // ArchiveStore for why the two live apart.
            $table->string('path');

            // The sha1 the upstream published for this archive, which the
            // bytes were checked against before this row was written. A
            // mismatch is refused rather than stored, so a row existing is
            // itself the claim that the file matched.
            $table->string('shasum', 40);

            // Kept so `mirror:prune` can report the disk it is about to
            // reclaim without stat-ing every object on an S3 bucket first.
            $table->unsignedBigInteger('size');

            $table->timestamp('used_at');

            $table->timestamps();

            $table->unique(['upstream_id', 'name', 'reference']);

            $table->index('used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mirrored_archives');
        Schema::dropIfExists('mirrored_packages');
    }
};
