<?php

namespace Convoro\Ext\OnAir\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A member's live stream. Per-user: each member can have at most one `live`
 * stream at a time (going live ends any previous one). The watch page and the
 * presence endpoint read these; reaping ends ones that have been live too long.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $external_id
 * @property string|null $title
 * @property string|null $embed_url
 * @property string $status
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $ended_at
 */
class Stream extends Model
{
    public const STATUS_LIVE = 'live';
    public const STATUS_ENDED = 'ended';

    protected $table = 'onair_streams';

    // Mass-assignment is locked down; controllers set attributes explicitly.
    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    /** Scope: only currently-live streams. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_LIVE);
    }

    /**
     * The iframe `src` for this stream's embed.
     *
     * YouTube needs only the video id. Twitch's embed REQUIRES a `parent=<host>`
     * matching the page hostname — which the server only knows from the request —
     * so the caller passes the live host for twitch.
     */
    /** Whether this stream plays via HLS (RTMP ingest) rather than an iframe embed. */
    public function isHls(): bool
    {
        return $this->provider === 'rtmp' && ! empty($this->embed_url);
    }

    public function embedUrl(?string $host = null): string
    {
        // RTMP streams (OnAir+) carry a ready-made HLS .m3u8 playback URL.
        if ($this->provider === 'rtmp') {
            return (string) $this->embed_url;
        }

        if ($this->provider === 'twitch') {
            $parent = $host ?: 'localhost';

            return 'https://player.twitch.tv/?channel='.rawurlencode($this->external_id)
                .'&parent='.rawurlencode($parent).'&muted=true';
        }

        // YouTube video id.
        return 'https://www.youtube.com/embed/'.rawurlencode($this->external_id).'?rel=0';
    }
}
