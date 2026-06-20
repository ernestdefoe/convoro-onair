<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('onair_streams') && ! Schema::hasColumn('onair_streams', 'embed_url')) {
            Schema::table('onair_streams', function (Blueprint $table) {
                // RTMP streams (OnAir+) store a ready-made HLS .m3u8 playback URL.
                $table->string('embed_url', 600)->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('onair_streams') && Schema::hasColumn('onair_streams', 'embed_url')) {
            Schema::table('onair_streams', function (Blueprint $table) {
                $table->dropColumn('embed_url');
            });
        }
    }
};
