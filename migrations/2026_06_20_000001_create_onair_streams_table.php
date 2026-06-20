<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('onair_streams')) {
            return;
        }

        Schema::create('onair_streams', function (Blueprint $table) {
            $table->id();
            // core users.id is INT UNSIGNED — match it for the FK column.
            $table->unsignedInteger('user_id')->index();
            $table->string('provider', 20);                 // 'youtube' | 'twitch'
            $table->string('external_id', 160);             // youtube video id | twitch channel
            $table->string('title', 200)->nullable();
            $table->string('status', 20)->default('live');  // 'live' | 'ended'
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // Fast "who is live right now" lookups for the polling presence.
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onair_streams');
    }
};
