# OnAir for Convoro

Per-user live streaming for your [Convoro](https://convoro.co) community. Any
member can go live with a **YouTube** or **Twitch** broadcast — a pulsing
**LIVE** badge then follows their avatar everywhere, a sidebar card lists who's
on air, and a public **/live** directory plus watch pages let everyone tune in.
No streaming infrastructure required.

Free, first-party, MIT-licensed. Requires Convoro core **≥ 1.39.6**.

## Features

- **Go live from the account menu** — pick YouTube or Twitch, paste a video ID
  or channel name, add a title, and you're broadcasting.
- **LIVE badge everywhere** — while you're live, a LIVE badge appears on your
  avatar across the whole forum (driven by core's live-user set).
- **Live now sidebar card** — lists everyone currently streaming; hidden when
  no one is live.
- **Public directory + watch pages** — `/live` shows a grid of live members;
  `/live/{user}` embeds the broadcast in a 16:9 player. Twitch's required
  `parent` is built from the live host server-side.
- **Admin manager** — see who's live and force-end any stream. Who can go live
  is controlled by the `onair.broadcast` permission (on by default).
- **Auto-reap** — streams left "live" for more than 12 hours are ended
  automatically (`convoro:onair-reap`, scheduled every 15 minutes).

## Permissions

`onair.broadcast` is granted to all members by default. Restrict it from
**Admin → Permissions** if you want only certain groups to be able to go live.

## Install

Install from the Convoro Marketplace and enable it. Members can then go live
from the account menu, and the **Live** link in the header opens the directory.
