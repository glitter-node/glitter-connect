<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('glitter_connect_inbound_receipts') && ! Schema::hasColumn('glitter_connect_inbound_receipts', 'body_sha256')) {
            Schema::table('glitter_connect_inbound_receipts', function (Blueprint $table): void {
                $table->char('body_sha256', 64)->nullable()->comment('원본 요청 본문의 SHA-256 해시');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('glitter_connect_inbound_receipts') && Schema::hasColumn('glitter_connect_inbound_receipts', 'body_sha256')) {
            Schema::table('glitter_connect_inbound_receipts', function (Blueprint $table): void {
                $table->dropColumn('body_sha256');
            });
        }
    }
};
