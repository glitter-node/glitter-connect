<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('glitter_connect_connections')
            && ! Schema::hasColumn('glitter_connect_connections', 'signature_required')) {
            Schema::table('glitter_connect_connections', function (Blueprint $table): void {
                $table->boolean('signature_required')->default(false)->comment('Require signed outbound deliveries');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('glitter_connect_connections')
            && Schema::hasColumn('glitter_connect_connections', 'signature_required')) {
            Schema::table('glitter_connect_connections', function (Blueprint $table): void {
                $table->dropColumn('signature_required');
            });
        }
    }
};
