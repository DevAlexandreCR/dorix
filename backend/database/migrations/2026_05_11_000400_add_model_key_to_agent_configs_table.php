<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_configs', function (Blueprint $table) {
            $table->string('model_key')->nullable()->after('model');
        });

        DB::table('agent_configs')
            ->select(['id', 'model'])
            ->orderBy('id')
            ->chunkById(100, function ($configs): void {
                foreach ($configs as $config) {
                    $modelKey = match (trim((string) $config->model)) {
                        'gpt-5-mini', 'gpt-5.4-mini' => 'balanced',
                        'gpt-5.1', 'gpt-5.5' => 'high_accuracy',
                        'gpt-5.4-nano' => 'savings',
                        default => null,
                    };

                    if ($modelKey === null) {
                        continue;
                    }

                    DB::table('agent_configs')
                        ->where('id', $config->id)
                        ->update(['model_key' => $modelKey]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('agent_configs', function (Blueprint $table) {
            $table->dropColumn('model_key');
        });
    }
};
