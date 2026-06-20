<?php

namespace Convoro\Ext\OnAir;

use App\Models\User;
use App\Support\ExtPage;
use App\Support\Present;
use Convoro\Ext\OnAir\Models\Stream;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * OnAir — first-party Convoro extension (per-user live streaming).
 *
 * Any member with the `onair.broadcast` permission can "go live" by pasting a
 * YouTube video id or Twitch channel. A LIVE badge then appears on their avatar
 * everywhere (via the core `window.Convoro.setLiveUsers` reactive set), a
 * sidebar card lists who is live, and a public directory (/live) + watch page
 * (/live/{user}) embed the broadcast. No streaming infrastructure required.
 *
 * State lives in the `onair_streams` table (one live row per user at a time).
 * Pages are server-rendered through ExtPage so they inherit the forum theme.
 */
class Extension extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerRoutes();

        // Stale-stream reaping: end streams live past 12h. Runnable by hand
        // (`php artisan convoro:onair-reap`) and scheduled every 15 minutes.
        if ($this->app->runningInConsole()) {
            $this->commands([ReapCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(ReapCommand::class)
                ->everyFifteenMinutes()
                ->name('convoro-onair-reap')
                ->withoutOverlapping();
        });
    }

    // --- Helpers -----------------------------------------------------------

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    /** Whether the current user may go live. is_admin bypasses hasPermission in core. */
    private static function canBroadcast(): bool
    {
        return (bool) Auth::user()?->hasPermission('onair.broadcast');
    }

    /** Shape a live stream for the public presence/JSON payloads. */
    private static function present(Stream $s): array
    {
        $user = $s->author;

        return [
            'userId' => (int) $s->user_id,
            'name' => $user?->name,
            'url' => '/live/'.(int) $s->user_id,
            'avatar' => Present::avatar($user),
            'platform' => $s->provider,
            'streamId' => $s->external_id,
            'title' => $s->title,
            'startedAt' => optional($s->started_at)->toIso8601String(),
        ];
    }

    /** End every currently-live stream for a user (used before going live + on end). */
    private static function endLiveFor(int $userId): void
    {
        Stream::query()->where('user_id', $userId)->live()->get()->each(function (Stream $s) {
            $s->status = Stream::STATUS_ENDED;
            $s->ended_at = now();
            $s->save();
        });
    }

    // --- Routes ------------------------------------------------------------

    private function registerRoutes(): void
    {
        // Public presence: who is live right now (drives the avatar LIVE badge,
        // the sidebar card and the directory). Flat, cache-friendly payload.
        Route::middleware('web')->get('/api/ext/onair/live', function () {
            $streams = Stream::query()->live()->with('author')->orderByDesc('started_at')->limit(100)->get();

            return response()->json(
                $streams->map(fn (Stream $s) => self::present($s))
                    ->filter(fn ($row) => $row['name'] !== null)
                    ->values()
            );
        });

        // Authed: the viewer's own broadcast state (for the Go Live / End menu item).
        Route::middleware(['web', 'auth'])->get('/api/ext/onair/me', function (Request $request) {
            $live = Stream::query()->where('user_id', $request->user()->id)->live()->orderByDesc('id')->first();

            return response()->json([
                'canBroadcast' => self::canBroadcast(),
                'live' => (bool) $live,
                'platform' => $live?->provider,
                'streamId' => $live?->external_id,
                'title' => $live?->title,
            ]);
        });

        // Authed: go live (replaces any existing live stream for this user).
        Route::middleware(['web', 'auth'])->post('/api/ext/onair/go-live', function (Request $request) {
            abort_unless(self::canBroadcast(), 403);

            $data = $request->validate([
                'platform' => ['required', 'in:youtube,twitch'],
                'stream_id' => ['required', 'string', 'max:160'],
                'title' => ['nullable', 'string', 'max:200'],
            ]);

            self::endLiveFor($request->user()->id);

            $s = new Stream;
            $s->user_id = $request->user()->id;
            $s->provider = $data['platform'];
            $s->external_id = trim($data['stream_id']);
            $s->title = isset($data['title']) ? trim((string) $data['title']) : null;
            $s->status = Stream::STATUS_LIVE;
            $s->started_at = now();
            $s->save();

            return response()->json(['ok' => true]);
        });

        // Authed: end the viewer's current live stream.
        Route::middleware(['web', 'auth'])->post('/api/ext/onair/end', function (Request $request) {
            self::endLiveFor($request->user()->id);

            return response()->json(['ok' => true]);
        });

        // Public pages: the live directory + per-streamer watch page.
        Route::middleware('web')->group(function () {
            Route::get('/live', fn () => self::directoryPage());
            Route::get('/live/{user}', function (Request $request, int $user) {
                $s = Stream::query()->where('user_id', $user)->live()->with('author')->orderByDesc('id')->first();
                abort_if(! $s || ! $s->author, 404);

                return self::watchPage($request, $s);
            });
        });

        // Admin manager: list live streams + force-end any of them.
        Route::middleware(['web', 'auth', 'admin'])->prefix('admin/ext/onair')->group(function () {
            Route::get('/', fn () => self::adminPage());

            Route::get('/list', function () {
                $streams = Stream::query()->live()->with('author')->orderByDesc('started_at')->get();

                return response()->json($streams->map(function (Stream $s) {
                    return [
                        'id' => (int) $s->id,
                        'userId' => (int) $s->user_id,
                        'name' => $s->author?->name ?? 'Member',
                        'platform' => $s->provider,
                        'streamId' => $s->external_id,
                        'title' => $s->title,
                        'startedAt' => optional($s->started_at)->toIso8601String(),
                    ];
                }));
            });

            Route::post('/{id}/end', function (int $id) {
                $s = Stream::find($id);
                abort_if(! $s, 404);
                $s->status = Stream::STATUS_ENDED;
                $s->ended_at = now();
                $s->save();

                return response()->json(['ok' => true]);
            });
        });
    }

    // --- Public pages ------------------------------------------------------

    private static function directoryPage(): \Inertia\Response
    {
        $streams = Stream::query()->live()->with('author')->orderByDesc('started_at')->limit(100)->get()
            ->filter(fn (Stream $s) => $s->author !== null);

        $cards = '';
        foreach ($streams as $s) {
            $av = Present::avatar($s->author);
            $cards .= '<a class="oa-card" href="/live/'.(int) $s->user_id.'">'
                .'<div class="oa-card-top">'.self::avatarHtml($av).'<span class="oa-pill"><span class="oa-dot"></span>'.self::e(self::t('Live')).'</span></div>'
                .'<div class="oa-card-name">'.self::e($av['name']).'</div>'
                .($s->title ? '<div class="oa-card-title">'.self::e($s->title).'</div>' : '')
                .'<div class="oa-card-foot"><span class="oa-plat">'.self::e(ucfirst($s->provider)).'</span><span class="oa-watch">'.self::e(self::t('Watch')).' →</span></div>'
                .'</a>';
        }

        $grid = $streams->count()
            ? '<div class="oa-grid">'.$cards.'</div>'
            : '<div class="oa-blank"><div class="oa-blank-ico">📺</div><div class="oa-blank-t">'.self::e(self::t('No one is live right now')).'</div>'
                .'<p class="oa-muted">'.self::e(self::t('Check back soon — or go live yourself from the account menu.')).'</p></div>';

        $body = '<div class="oa-wrap"><div class="oa-hero"><div class="oa-eyebrow">OnAir</div>'
            .'<h1 class="oa-h1">'.self::e(self::t('Live now')).'</h1>'
            .'<p class="oa-sub">'.self::e(self::t('Members broadcasting live right now. Click a card to watch.')).'</p></div>'
            .$grid.'</div>';

        return ExtPage::render(self::t('Live now'), $body, self::css());
    }

    private static function watchPage(Request $request, Stream $s): \Inertia\Response
    {
        $av = Present::avatar($s->author);
        $src = $s->embedUrl($request->getHost());

        $body = '<div class="oa-wrap oa-narrow">'
            .'<div class="oa-crumbs"><a href="/live">'.self::e(self::t('Live now')).'</a> <span>/</span> '.self::e($av['name']).'</div>'
            .'<div class="oa-watch-head">'.self::avatarHtml($av, 44)
            .'<div class="oa-watch-meta"><div class="oa-watch-name">'.self::e($av['name'])
            .' <span class="oa-pill"><span class="oa-dot"></span>'.self::e(self::t('Live')).'</span></div>'
            .($s->title ? '<div class="oa-watch-title">'.self::e($s->title).'</div>' : '').'</div></div>'
            .'<div class="oa-frame"><iframe src="'.self::e($src).'" allow="autoplay; fullscreen; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>'
            .'</div>';

        return ExtPage::render($av['name'].' — '.self::t('Live'), $body, self::css());
    }

    // --- Admin -------------------------------------------------------------

    private static function adminPage(): \Inertia\Response
    {
        $body = <<<HTML
        <div class="oa-wrap oa-narrow">
          <div class="oa-hero oa-hero-sm">
            <div class="oa-eyebrow">OnAir</div>
            <h1 class="oa-h1">Live streams</h1>
            <p class="oa-sub">Members go live from the account menu; their broadcasts show in the sidebar, on the <a href="/live" target="_blank">Live now</a> page and with a LIVE badge on their avatar. Who can go live is controlled by the <b>onair.broadcast</b> permission (on by default).</p>
          </div>
          <div class="oa-card-list"><div id="list" class="oa-muted">Loading…</div></div>
        </div>
        HTML;

        return ExtPage::render('OnAir', $body, self::css(), self::adminJs());
    }

    private static function adminJs(): string
    {
        return <<<JS
        function notify(m,k){try{if(window.parent!==window)window.parent.postMessage({type:'convoro:toast',message:m,kind:k||'success'},location.origin);}catch(e){}}
        var esc=function(s){return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});};
        function load(){fetch('/admin/ext/onair/list',{headers:H}).then(function(r){return r.json();}).then(function(rows){
          var el=document.getElementById('list');
          if(!rows.length){el.innerHTML='<p class="oa-muted">No one is live right now.</p>';return;}
          el.innerHTML=rows.map(function(s){
            return '<div class="oa-row"><span class="oa-dot"></span>'
              +'<div class="oa-row-b"><div class="oa-row-t"><a href="/live/'+s.userId+'" target="_blank">'+esc(s.name)+'</a>'+(s.title?' — '+esc(s.title):'')+'</div>'
              +'<div class="oa-row-m">'+esc(s.platform)+' · '+esc(s.streamId)+'</div></div>'
              +'<button class="oa-btn oa-btn-x" data-id="'+s.id+'">Force end</button></div>';
          }).join('');
        });}
        document.getElementById('list').addEventListener('click',function(ev){var b=ev.target.closest('button[data-id]');if(!b)return;
          var id=b.getAttribute('data-id');if(!confirm('Force this stream to end?'))return;
          fetch('/admin/ext/onair/'+id+'/end',{method:'POST',headers:H}).then(function(r){if(r.ok){notify('Stream ended');load();}else notify('Could not end','error');});});
        load();
        JS;
    }

    // --- Shared rendering --------------------------------------------------

    /** A server-side avatar bubble matching the forum's Present::avatar() shape. */
    private static function avatarHtml(array $av, int $size = 38): string
    {
        $style = 'width:'.$size.'px;height:'.$size.'px';
        if (! empty($av['avatar'])) {
            return '<span class="oa-av" style="'.$style.';background-image:url(\''.self::e($av['avatar']).'\')"></span>';
        }
        $color = (int) ($av['color'] ?? 1);

        return '<span class="oa-av oa-av-g'.$color.'" style="'.$style.'">'.self::e($av['initials'] ?? '?').'</span>';
    }

    /** Translate via core's translator (server side: identity fallback to the key). */
    private static function t(string $s): string
    {
        $r = __($s);

        return is_string($r) ? $r : $s;
    }

    private static function css(): string
    {
        return <<<CSS
        .oa-wrap{max-width:900px;margin:0 auto;padding:24px 16px 64px}
        .oa-narrow{max-width:680px}
        .ext-embed .oa-wrap{padding:0}
        .oa-muted{color:rgb(var(--c-muted));font-size:14px}
        .oa-hero{padding:28px 30px;margin-bottom:22px;border-radius:18px;border:1px solid rgb(var(--c-border));
          background:linear-gradient(135deg,rgba(91,91,214,.16),rgba(139,92,246,.10)),rgb(var(--c-surface))}
        .oa-hero-sm{padding:22px 24px}
        .oa-eyebrow{font-size:13px;font-weight:800;letter-spacing:.04em;color:rgb(var(--c-primary));margin-bottom:6px}
        .oa-h1{font-size:1.9rem;font-weight:900;letter-spacing:-.02em;margin:0;color:rgb(var(--c-text))}
        .oa-sub{margin:8px 0 0;color:rgb(var(--c-text-2));font-size:14px;line-height:1.55;max-width:620px}
        .oa-sub a,.oa-crumbs a{color:rgb(var(--c-primary));font-weight:600;text-decoration:none}
        .oa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}
        .oa-card{display:block;background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:16px;padding:16px;
          text-decoration:none;color:inherit;transition:border-color .12s,transform .12s}
        .oa-card:hover{border-color:rgb(var(--c-primary)/.5);transform:translateY(-1px)}
        .oa-card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
        .oa-card-name{font-weight:800;color:rgb(var(--c-text));white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .oa-card-title{font-size:13px;color:rgb(var(--c-text-2));margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .oa-card-foot{display:flex;align-items:center;justify-content:space-between;margin-top:12px;font-size:12px}
        .oa-plat{color:rgb(var(--c-muted));text-transform:capitalize}
        .oa-watch{color:rgb(var(--c-primary));font-weight:700}
        .oa-pill{display:inline-flex;align-items:center;gap:5px;background:#e0245e;color:#fff;font-size:10px;font-weight:800;
          letter-spacing:.05em;padding:3px 8px;border-radius:999px;text-transform:uppercase}
        .oa-dot{width:6px;height:6px;border-radius:50%;background:currentColor;animation:oa-pulse 1.6s ease-in-out infinite}
        .oa-card .oa-pill{color:#fff}.oa-card .oa-dot{background:#fff}
        @keyframes oa-pulse{0%,100%{opacity:1}50%{opacity:.4}}
        .oa-av{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;border-radius:50%;
          background-size:cover;background-position:center;color:#fff;font-weight:700;font-size:14px}
        .oa-av-g1{background:#5b5bd6}.oa-av-g2{background:#0ea5e9}.oa-av-g3{background:#10b981}
        .oa-av-g4{background:#f59e0b}.oa-av-g5{background:#ef4444}.oa-av-g6{background:#8b5cf6}
        .oa-blank{text-align:center;padding:48px 20px;border:1.5px dashed rgb(var(--c-border));border-radius:16px}
        .oa-blank-ico{font-size:40px;margin-bottom:8px}
        .oa-blank-t{font-weight:800;color:rgb(var(--c-text))}
        .oa-crumbs{font-size:13px;color:rgb(var(--c-muted));margin-bottom:14px}
        .oa-crumbs span{margin:0 4px}
        .oa-watch-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
        .oa-watch-name{font-size:1.25rem;font-weight:800;color:rgb(var(--c-text));display:flex;align-items:center;gap:8px}
        .oa-watch-title{font-size:14px;color:rgb(var(--c-text-2));margin-top:2px}
        .oa-frame{position:relative;width:100%;aspect-ratio:16/9;background:#000;border-radius:14px;overflow:hidden;border:1px solid rgb(var(--c-border))}
        .oa-frame iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
        .oa-card-list{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:16px;padding:8px 22px}
        .oa-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid rgb(var(--c-border))}
        .oa-row:last-child{border-bottom:0}
        .oa-row .oa-dot{background:#e0245e}
        .oa-row-b{flex:1;min-width:0}
        .oa-row-t{font-weight:700;color:rgb(var(--c-text));white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .oa-row-t a{color:inherit;text-decoration:none}
        .oa-row-m{font-size:12px;color:rgb(var(--c-muted));margin-top:2px;word-break:break-all}
        .oa-btn{font:inherit;font-size:13px;font-weight:700;padding:8px 14px;border-radius:10px;border:1px solid rgb(var(--c-border));
          background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer}
        .oa-btn-x{color:#e5484d;border-color:transparent;background:rgba(229,72,77,.08)}
        CSS;
    }
}
