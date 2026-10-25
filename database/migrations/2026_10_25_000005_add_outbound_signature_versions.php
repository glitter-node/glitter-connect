<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('glitter_connect_subscriptions')
            && ! Schema::hasColumn('glitter_connect_subscriptions', 'outbound_signature_version')) {
            Schema::table('glitter_connect_subscriptions', function (Blueprint $table): void {
                $table->string('outbound_signature_version', 2)->default('v0')->comment('Outbound webhook signature contract version');
            });
        }

        if (Schema::hasTable('glitter_connect_deliveries')
            && ! Schema::hasColumn('glitter_connect_deliveries', 'outbound_signature_version')) {
            Schema::table('glitter_connect_deliveries', function (Blueprint $table): void {
                $table->string('outbound_signature_version', 2)->default('v0')->comment('Signature version captured when delivery was created');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('glitter_connect_deliveries')
            && Schema::hasColumn('glitter_connect_deliveries', 'outbound_signature_version')) {
            Schema::table('glitter_connect_deliveries', function (Blueprint $table): void {
                $table->dropColumn('outbound_signature_version');
            });
        }

        if (Schema::hasTable('glitter_connect_subscriptions')
            && Schema::hasColumn('glitter_connect_subscriptions', 'outbound_signature_version')) {
            Schema::table('glitter_connect_subscriptions', function (Blueprint $table): void {
                $table->dropColumn('outbound_signature_version');
            });
        }
    }
};
