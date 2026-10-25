<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('glitter_connect_endpoints') && ! Schema::hasColumn('glitter_connect_endpoints', 'signature_version')) {
            Schema::table('glitter_connect_endpoints', function (Blueprint $table): void {
                $table->string('signature_version', 2)->default('v0')->comment('수신 웹훅 서명 계약 버전');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('glitter_connect_endpoints') && Schema::hasColumn('glitter_connect_endpoints', 'signature_version')) {
            Schema::table('glitter_connect_endpoints', function (Blueprint $table): void {
                $table->dropColumn('signature_version');
            });
        }
    }
};
