<?php

namespace Convoro\Ext\OnAir;

use App\Support\ExtPage;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * OnAir (Lite) — first-party Convoro extension.
 *
 * Embeds a live YouTube or Twitch broadcast in the forum sidebar with a pulsing
 * LIVE badge while you're on air. Settings-only (no table); the frontend builds
 * the embed so Twitch's required `parent` can use the live hostname. The admin
 * page is server-rendered through ExtPage so it inherits the forum theme.
 */
class Extension extends ServiceProvider
{
    public function boot(): void
    {
        // Public: current stream state for the forum widget.
        Route::middleware('web')->get('/api/ext/onair/current', fn () => response()->json(self::current()));

        // Admin: settings page + save.
        Route::middleware(['web', 'auth', 'admin'])->prefix('admin/ext/onair')->group(function () {
            Route::get('/', fn () => self::adminPage());
            Route::post('/', function (Request $request) {
                $data = $request->validate([
                    'live' => ['boolean'],
                    'platform' => ['required', 'in:youtube,twitch'],
                    'stream_id' => ['nullable', 'string', 'max:120'],
                    'title' => ['nullable', 'string', 'max:160'],
                ]);
                Settings::setMany([
                    'onair.live' => $request->boolean('live'),
                    'onair.platform' => $data['platform'],
                    'onair.stream_id' => trim((string) ($data['stream_id'] ?? '')),
                    'onair.title' => $data['title'] ?? '',
                ]);

                return response()->json(['ok' => true]);
            });
        });
    }

    public static function current(): array
    {
        $id = trim((string) Settings::get('onair.stream_id', ''));

        return [
            'live' => (bool) Settings::get('onair.live', false) && $id !== '',
            'platform' => (string) Settings::get('onair.platform', 'youtube'),
            'streamId' => $id,
            'title' => (string) (Settings::get('onair.title') ?: 'Live now'),
        ];
    }

    private static function adminPage(): \Inertia\Response
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $live = Settings::get('onair.live') ? 'checked' : '';
        $platform = (string) Settings::get('onair.platform', 'youtube');
        $sid = $e(Settings::get('onair.stream_id'));
        $title = $e(Settings::get('onair.title'));
        $sel = fn ($a, $b) => $a === $b ? 'selected' : '';

        $body = <<<HTML
        <div class="oa-wrap">
          <div class="oa-hero">
            <div>
              <div class="oa-eyebrow">OnAir</div>
              <h1 class="oa-h1">Go live in your community</h1>
              <p class="oa-sub">Embed a YouTube or Twitch broadcast with a pulsing LIVE badge in the sidebar — no streaming infrastructure required.</p>
            </div>
          </div>
          <div class="oa-card">
            <label class="oa-chk"><input type="checkbox" id="live" {$live}> <span>🔴 We're live — show the player now</span></label>
            <label class="oa-f">Platform</label>
            <select id="platform"><option value="youtube" {$sel($platform,'youtube')}>YouTube</option><option value="twitch" {$sel($platform,'twitch')}>Twitch</option></select>
            <label class="oa-f">Stream ID</label>
            <input id="stream_id" value="{$sid}" placeholder="YouTube video ID or Twitch channel name">
            <p class="oa-hint">YouTube: the video ID from the watch URL (…/watch?v=<b>ID</b>). Twitch: your channel name.</p>
            <label class="oa-f">Title</label>
            <input id="title" value="{$title}" placeholder="Live now">
            <div class="oa-status" id="msg">Changes save automatically</div>
          </div>
        </div>
        HTML;

        $css = <<<CSS
        .oa-wrap{max-width:640px;margin:0 auto;padding:24px 16px 64px}
        .ext-embed .oa-wrap{padding:0}
        .oa-hero{padding:26px 28px;margin-bottom:20px;border-radius:18px;border:1px solid rgb(var(--c-border));
          background:linear-gradient(135deg,rgba(91,91,214,.16),rgba(139,92,246,.10)),rgb(var(--c-surface))}
        .oa-eyebrow{font-size:13px;font-weight:800;letter-spacing:.04em;color:rgb(var(--c-primary));margin-bottom:6px}
        .oa-h1{font-size:1.7rem;font-weight:900;letter-spacing:-.02em;margin:0;color:rgb(var(--c-text))}
        .oa-sub{margin:8px 0 0;color:rgb(var(--c-text-2));font-size:14px;line-height:1.5}
        .oa-card{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:16px;padding:22px}
        .oa-f{display:block;font-size:13px;font-weight:600;color:rgb(var(--c-text-2));margin:16px 0 5px}
        .oa-card input:not([type=checkbox]),.oa-card select{width:100%;font:inherit;font-size:14px;padding:10px 12px;border-radius:10px;
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface-2));color:rgb(var(--c-text))}
        .oa-card input:focus,.oa-card select:focus{outline:none;border-color:rgb(var(--c-primary))}
        .oa-chk{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:600;color:rgb(var(--c-text));cursor:pointer}
        .oa-chk input{width:18px;height:18px;accent-color:rgb(var(--c-primary))}
        .oa-hint{color:rgb(var(--c-muted));font-size:12px;margin:5px 0 0}
        .oa-status{margin-top:18px;font-size:13px;color:rgb(var(--c-muted))}
        CSS;

        $js = <<<JS
        var msg=document.getElementById('msg'),IDLE='Changes save automatically',t=null;
        function notify(message,kind){try{if(window.parent!==window)window.parent.postMessage({type:'convoro:toast',message:message,kind:kind||'success'},location.origin);}catch(e){}}
        function collect(){return{live:document.getElementById('live').checked,platform:document.getElementById('platform').value,stream_id:document.getElementById('stream_id').value,title:document.getElementById('title').value};}
        function save(){msg.style.color='';msg.textContent='Saving…';
          fetch('/admin/ext/onair',{method:'POST',headers:H,body:JSON.stringify(collect())}).then(function(r){
            msg.textContent=r.ok?'Saved ✓':'Error — will retry';notify(r.ok?'Settings saved':"Couldn't save",r.ok?'success':'error');
            setTimeout(function(){if(msg.textContent==='Saved ✓')msg.textContent=IDLE;},1800);
          }).catch(function(){msg.textContent='Error — will retry';notify("Couldn't save",'error');});}
        function debounced(){if(t)clearTimeout(t);t=setTimeout(save,700);}
        ['stream_id','title'].forEach(function(id){document.getElementById(id).addEventListener('input',debounced);});
        ['live','platform'].forEach(function(id){document.getElementById(id).addEventListener('change',save);});
        JS;

        return ExtPage::render('OnAir', $body, $css, $js);
    }
}
