<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('glitter_connect_connections')) Schema::create('glitter_connect_connections', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('provider', 80)->default('generic'); $table->string('status', 24)->default('active')->index(); $table->json('config')->nullable(); $table->timestamps();
        });
        if (! Schema::hasTable('glitter_connect_credentials')) Schema::create('glitter_connect_credentials', function (Blueprint $table) {
            $table->id(); $table->foreignId('connection_id')->constrained('glitter_connect_connections')->cascadeOnDelete(); $table->string('key', 80); $table->text('secret_encrypted'); $table->timestamps(); $table->unique(['connection_id', 'key']);
        });
        if (! Schema::hasTable('glitter_connect_endpoints')) Schema::create('glitter_connect_endpoints', function (Blueprint $table) {
            $table->id(); $table->foreignId('connection_id')->nullable()->constrained('glitter_connect_connections')->nullOnDelete(); $table->string('endpoint_key', 64)->unique(); $table->string('name', 120); $table->text('secret_encrypted'); $table->string('status', 24)->default('active')->index(); $table->unsignedInteger('max_payload_bytes')->default(262144); $table->timestamps();
        });
        if (! Schema::hasTable('glitter_connect_subscriptions')) Schema::create('glitter_connect_subscriptions', function (Blueprint $table) {
            $table->id(); $table->foreignId('connection_id')->constrained('glitter_connect_connections')->cascadeOnDelete(); $table->string('event_type', 160)->index(); $table->text('target_url'); $table->string('status', 24)->default('active')->index(); $table->json('config')->nullable(); $table->timestamps();
        });
        if (! Schema::hasTable('glitter_connect_events')) Schema::create('glitter_connect_events', function (Blueprint $table) {
            $table->id(); $table->string('event_id', 100); $table->string('event_type', 160)->index(); $table->string('source', 160); $table->string('subject', 255)->nullable(); $table->timestamp('occurred_at')->nullable(); $table->timestamp('received_at'); $table->string('correlation_id', 100)->nullable()->index(); $table->unsignedInteger('schema_version')->default(1); $table->json('data'); $table->json('metadata')->nullable(); $table->string('status', 24)->default('accepted')->index(); $table->timestamps(); $table->unique(['source', 'event_id']);
        });
        if (! Schema::hasTable('glitter_connect_deliveries')) Schema::create('glitter_connect_deliveries', function (Blueprint $table) {
            $table->id(); $table->foreignId('event_id')->constrained('glitter_connect_events')->cascadeOnDelete(); $table->foreignId('subscription_id')->nullable()->constrained('glitter_connect_subscriptions')->nullOnDelete(); $table->string('status', 24)->default('pending')->index(); $table->unsignedInteger('attempt_count')->default(0); $table->timestamp('next_attempt_at')->nullable()->index(); $table->timestamp('lease_expires_at')->nullable()->index(); $table->unsignedSmallInteger('response_code')->nullable(); $table->text('last_error')->nullable(); $table->string('idempotency_key', 160)->unique(); $table->timestamp('delivered_at')->nullable(); $table->timestamps();
        });
        if (! Schema::hasTable('glitter_connect_attempts')) Schema::create('glitter_connect_attempts', function (Blueprint $table) {
            $table->id(); $table->foreignId('delivery_id')->constrained('glitter_connect_deliveries')->cascadeOnDelete(); $table->unsignedInteger('attempt_number'); $table->unsignedSmallInteger('response_code')->nullable(); $table->string('outcome', 24); $table->text('error_redacted')->nullable(); $table->unsignedInteger('duration_ms')->nullable(); $table->timestamp('created_at')->useCurrent(); $table->unique(['delivery_id', 'attempt_number']);
        });
        if (! Schema::hasTable('glitter_connect_inbound_receipts')) Schema::create('glitter_connect_inbound_receipts', function (Blueprint $table) {
            $table->id(); $table->foreignId('endpoint_id')->constrained('glitter_connect_endpoints')->cascadeOnDelete(); $table->string('idempotency_key', 160); $table->foreignId('event_id')->nullable()->constrained('glitter_connect_events')->nullOnDelete(); $table->timestamp('received_at'); $table->unique(['endpoint_id', 'idempotency_key'], 'gc_inbound_idem_uq');
        });
        $this->ensureUniqueIndex('glitter_connect_inbound_receipts', ['endpoint_id', 'idempotency_key'], 'gc_inbound_idem_uq');
        if (! Schema::hasTable('glitter_connect_replay_requests')) Schema::create('glitter_connect_replay_requests', function (Blueprint $table) {
            $table->id(); $table->foreignId('delivery_id')->constrained('glitter_connect_deliveries')->cascadeOnDelete(); $table->unsignedBigInteger('requested_by')->nullable(); $table->timestamp('requested_at'); $table->string('status', 24)->default('queued'); $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['glitter_connect_replay_requests', 'glitter_connect_inbound_receipts', 'glitter_connect_attempts', 'glitter_connect_deliveries', 'glitter_connect_events', 'glitter_connect_subscriptions', 'glitter_connect_endpoints', 'glitter_connect_credentials', 'glitter_connect_connections'] as $table) {
            if (Schema::hasTable($table)) Schema::drop($table);
        }
    }

    private function ensureUniqueIndex(string $tableName, array $columns, string $indexName): void
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            $index = $index instanceof stdClass ? (array) $index : $index;
            if (! is_array($index)) continue;
            $normalized = [];
            foreach ($index as $key => $value) $normalized[strtolower((string) $key)] = $value;
            if (! array_key_exists('columns', $normalized) || ! array_key_exists('unique', $normalized)) continue;
            if ((bool) $normalized['unique'] && array_values((array) $normalized['columns']) === $columns) return;
        }
        Schema::table($tableName, fn (Blueprint $blueprint) => $blueprint->unique($columns, $indexName));
    }
};
