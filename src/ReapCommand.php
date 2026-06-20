<?php

namespace Convoro\Ext\OnAir;

use Convoro\Ext\OnAir\Models\Stream;
use Illuminate\Console\Command;

/**
 * Ends streams that have been "live" longer than 12 hours. This is the pragmatic
 * answer to "the streamer closed their tab / stopped broadcasting" without
 * per-user YouTube/Twitch OAuth: a stream can't stay live forever.
 *
 * Registered to run every 15 minutes by the extension's service provider (via
 * the host scheduler), and can also be run by hand:
 *   php artisan convoro:onair-reap
 */
class ReapCommand extends Command
{
    protected $signature = 'convoro:onair-reap';

    protected $description = 'End OnAir streams that have been live for more than 12 hours';

    public function handle(): int
    {
        $cutoff = now()->subHours(12);

        $stale = Stream::query()
            ->where('status', Stream::STATUS_LIVE)
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stale as $stream) {
            $stream->status = Stream::STATUS_ENDED;
            $stream->ended_at = now();
            $stream->save();
        }

        $this->info("OnAir: ended {$stale->count()} stale stream(s) live past 12h.");

        return self::SUCCESS;
    }
}
