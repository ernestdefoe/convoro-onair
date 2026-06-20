<?php

namespace Convoro\Ext\OnAir\Events;

use Convoro\Ext\OnAir\Models\Stream;

/**
 * Fired when a member's stream goes live (any provider — YouTube, Twitch, or an
 * OnAir+ RTMP ingest). Add-ons listen to this to react, e.g. OnAir+ notifies the
 * streamer's followers.
 */
class StreamStarted
{
    public function __construct(public Stream $stream) {}
}
