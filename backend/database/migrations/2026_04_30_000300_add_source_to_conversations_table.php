<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('source')->default('whatsapp')->after('status');
            $table->index(['tenant_id', 'source', 'status'], 'conversations_tenant_source_status_index');
        });

        DB::table('conversations')
            ->whereNull('source')
            ->update(['source' => 'whatsapp']);
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_tenant_source_status_index');
            $table->dropColumn('source');
        });
    }
};
