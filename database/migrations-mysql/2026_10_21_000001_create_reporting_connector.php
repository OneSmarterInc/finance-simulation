<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U3 layer 1 (RP1 to RP3, decided 19 October 2026): the read-only reporting
 * connector that the family dashboard (and later the U7 brief) signs in to.
 *
 * reporting_clients      OAuth clients: Claude's published identity (a Client ID
 *                        Metadata Document URL) or one registered through DCR
 * reporting_codes        one-use authorization codes, kept as hashes, ten minutes
 * reporting_connections  one per admin sign-in: access and refresh tokens as hashes,
 *                        the refresh token rotating on every use; Revoke ends it
 * reporting_calls        which tool was called and how long it took, never the rows
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('client_id_hash', 64)->unique();
            $table->string('client_id', 500);
            $table->string('kind', 10);
            $table->string('name', 200)->nullable();
            $table->json('redirect_uris');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reporting_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code_hash', 64)->unique();
            $table->foreignUuid('client_id')->constrained('reporting_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('redirect_uri', 500);
            $table->string('code_challenge', 128);
            $table->string('scope', 100);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('reporting_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('reporting_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 100);
            $table->string('access_hash', 64)->unique();
            $table->timestamp('access_expires_at');
            $table->string('refresh_hash', 64)->unique();
            $table->timestamp('refresh_expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('reporting_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('connection_id')->constrained('reporting_connections')->cascadeOnDelete();
            $table->string('tool', 60);
            $table->string('status', 20);
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('ms')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_calls');
        Schema::dropIfExists('reporting_connections');
        Schema::dropIfExists('reporting_codes');
        Schema::dropIfExists('reporting_clients');
    }
};
