<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared records both ways (fbs-suites-mcp plan 2026-11-18-every-app-two-way-sync; contract fbs-record.v1). `app_fbs_changes`: every
 * change People made (received_from null: to send) or took (forward_status: People is the `person` center and passes it on);
 * `app_fbs_versions`: when each shared field of a record was last set, so the later change wins. Additive only (rule 23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_fbs_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('app_companies')->cascadeOnDelete();
            $table->string('change_id', 26);
            $table->string('type', 20);
            $table->string('external_id');
            $table->json('fields');
            $table->timestamp('changed_at', 6);
            $table->json('changed_by');
            $table->string('origin_app', 10);
            $table->string('received_from', 10)->nullable(); // null: made in People
            $table->string('status', 12); // accepted | superseded | held | refused | pending (to send) | none (no linked app takes it)
            $table->string('forward_status', 12)->nullable(); // a taken change People passes on: pending | accepted | none
            $table->json('targets')->nullable(); // "app:tenant" => answer
            $table->timestamps();

            $table->unique(['company_id', 'change_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'forward_status']);
        });

        Schema::create('app_fbs_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('app_companies')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('external_id');
            $table->json('versions'); // field => {at, change_id}
            $table->timestamps();

            $table->unique(['company_id', 'type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_fbs_versions');
        Schema::dropIfExists('app_fbs_changes');
    }
};
