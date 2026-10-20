# Virtual Balloon Race

A WordPress + WooCommerce plugin for running virtual balloon races as fundraisers. Supporters buy a balloon entry for a race, customise it (name, colour, helium strategy, and optional paid extras), and after launch the balloons drift across a live map using real wind data. A leaderboard ranks balloons by distance travelled.

- **Requires:** WordPress 6.0+, PHP 7.4+, WooCommerce 7.0+ (for selling entries)
- **Version:** 1.4.0 (database schema 1.4.0)
- **Licence:** GPL-2.0-or-later

---

## For site admins

### 1. Install

1. Copy the plugin folder to `wp-content/plugins/` and activate **Virtual Balloon Race**.
2. Make sure WooCommerce is active. Without it the leaderboard and map still work, but entries can't be sold.

### 2. Check the settings

**Balloon Race → Settings** holds the rules shared by every race:

| Setting | What it does |
| --- | --- |
| Auto-update interval | How often balloons move: off, 5 min, 30 min, hourly, 6-hourly or daily |
| Base speed, helium multipliers, random variance | How far balloons travel per hour. Max Height is faster, Eco-Glide slower |
| Default launch point | Pre-filled when you create a race. Search a place or click the map |
| Live wind / per-balloon wind / wind influence | Real wind from Open-Meteo (free, no key). Per-balloon wind makes paths split apart as balloons spread |
| Default heading | Direction balloons travel if wind data isn't available |
| Burst chances | Max Height burst chance per day, plus storm bursts above a wind threshold (Eco-Glide is immune) |
| Map | Default zoom, how often the public map refreshes, trail length |
| Shop add-ons | Turn extras on or off and set their prices |
| Teams | Off by default. **Enable teams**, the **default scoring** (average distance, total distance or best balloon) and the **default teams** (name and colour) that new races start with |

> **Timing:** WordPress only runs scheduled jobs when someone visits the site. On a quiet site, updates can arrive late, though balloons still catch up on the next run. For on-time updates, add a real server cron that runs `wp vbr tick` every few minutes.

### 3. Create a race

**Balloon Race → Races → Add race**

| Field | Meaning |
| --- | --- |
| Name | Shown on the shop, map and leaderboard |
| Entries open | When people can start buying. Leave blank to open straight away |
| Launch | Entries close and balloons start moving |
| Ends | Optional. Balloons stop and results become final. Leave blank to finish by hand |
| Launch point | Where balloons start. Search or click the map |

A race goes through four stages on its own:

| Stage | Shop | Balloons |
| --- | --- | --- |
| **Upcoming** | Closed ("entries open on …") | — |
| **Entries open** | Open | Wait at the launch point |
| **Flying** | Closed | Move with the wind on every update |
| **Finished** | Closed | Stopped; final leaderboard |

Each race's page has **Launch now** and **Finish now** buttons for event-day control. A race can only be deleted while it has no balloons.

You can schedule several races. Usually only one runs at a time, but overlapping races work too.

#### Teams (optional)

With **Enable teams** ticked in Settings, each race has its own teams on its race screen: new races start with the default teams, and you can rename, recolour, add or remove them (a team with balloons can't be removed). **Add default teams** fills a race that has none, and **Team scoring** can override the default for that race. A race with no teams runs without them.

When a race has teams, buyers must pick one. Team standings appear on the tracking page, the leaderboard and map show each balloon's team, and the map can be filtered to one team. Scores: **average** and **total** use every balloon in the team (burst balloons count at the distance they reached); **best** is the team's furthest balloon. Switching teams off hides them everywhere but keeps them.

### 4. Set up the balloon product

The quickest way: open the race (**Balloon Race → Races → Edit**) and click **Create entry product**. This makes a draft product locked to that race and opens it so you can set the price and publish.

Or create one by hand:

1. **Products → Add New**, and set **Product data** to **Balloon race entry**. Entries are always virtual, so there's no shipping.
2. Set the price (e.g. £3) on the **General** tab.
3. On the **Balloon race** tab, choose which race it sells:
   - **Any race taking entries** (default): buyers enter whichever race is open. If several are open, they pick one.
   - **A specific race**: the product only sells entries for that race.

The race's edit screen lists every product that sells its entries.

When no race is taking entries, the product can't be added to the basket and shows when entries next open. If a race launches while an entry for it is still in someone's basket, the entry is removed with a message.

> **Upgrading from 1.2:** products marked with the old "Balloon Entry" tick box or `balloon-entry` tag are converted to the new type automatically. Products that weren't simple products (e.g. variable) can't be converted; a notice in admin lists them.

Buyers fill in:

- **Balloon name**, **colour** and **helium strategy** (Balanced is free; Max Height and Eco-Glide cost the "tactical strategy" price)
- Optional **custom launch point** (+£2 by default): search or click a map to start from their school or hometown
- Optional **logo or picture** (+£2 by default): PNG, JPG or WebP up to 1 MB. It fills the balloon on the map; very wide or tall pictures are shown whole instead of cropped. The product page previews it on a balloon in the chosen colour

A balloon is created when its order reaches **Processing** or **Completed**.

### 5. The race tracking page

Activating the plugin creates a **Balloon Race** page (`/balloon-race/`) with the live map, team standings (when teams are on), the leaderboard and a list of races. It's ordinary page content (three shortcode blocks), so you can edit it, add text or rearrange it.

**Balloon Race → Settings → Pages**:

- **Race tracking page**: choose which page is the tracking page. Leaderboard names, My Account and order emails link to it.
- **Create tracking page**: shown when none is set (for example after the page was deleted).
- **Regenerate page content**: resets the page to the default map, leaderboard and race list. Your edits to that page are lost.

One page serves every race. `/balloon-race/` shows the **current** race (the one flying, or else the next one taking entries, or else the next upcoming one, or else the last one finished). `/balloon-race/?race=summer-2026` shows a particular race, and the race list links to each one. Links like `/balloon-race/?race=summer-2026&balloon=12` open with that balloon selected.

Logged-in customers also get **My Account → My balloons**: each balloon they've entered, in every race, with its position, distance and a **Track** link.

#### Shortcodes

You can still build your own pages:

```
[balloon_map]
[balloon_leaderboard]
[balloon_races]
```

| Shortcode | Attributes |
| --- | --- |
| `[balloon_map]` | `race` = ID, slug or `current`. Without it, the page's `?race=` is used, else the current race. `height` (default `520px`). `list="no"` hides the side list |
| `[balloon_leaderboard]` | `race` (as above). `limit` (default 50). `map_url`: where names link (default: the tracking page) |
| `[balloon_teams]` | Team standings: rank, team, balloons, score, leading balloon. `race` (as above), `title`. Shows nothing when teams are off or the race has none |
| `[balloon_races]` | Links to every race on the same page. `title` (default "All races"). `min` (default 2): hidden when there are fewer races |

On the map, clicking a balloon (or its name in the list or leaderboard) highlights its trail and fades the others. Click the map background to clear the highlight.

#### What entrants see

- **Their own balloon.** Logged-in entrants see their balloons marked **Yours**. The map opens on their best-placed balloon, the leaderboard pins **Your balloons** with each one's position, and both have an **All balloons / My balloons** toggle. A balloon counts as theirs if they bought it while logged in, or if the order's email matches their account email or billing email (so guest orders count once they register).
- **Guests** get **Track your balloon** links on the order confirmation page and in their order emails.
- **Search.** The map list and the leaderboard both search by balloon name or owner. Leaderboard search covers every balloon in the race, not just the top `limit` rows.
- **Owner names** are shown publicly as first name and last initial ("Luke H."), on the map, the leaderboard and the REST API. Search only matches that short form. Admin screens and the CSV keep full names.

### 6. During the race

**Balloon Race → Leaderboard** (pick the race at the top):

- See every balloon, its owner's email, distance and status
- **Advance now** moves the race by a chosen number of hours, or by a fixed km range
- **Mark burst / Restore** for individual balloons
- **Export CSV** of the race's balloons and donors

---

## For developers

### Layout

```
virtual-balloon-race.php        Bootstrap: constants, includes, activation, WP-CLI
includes/
  class-vbr-install.php         Schema (dbDelta), version upgrades, Multisite installs
  class-vbr-races.php           Race model: stages, lookups, save/delete, time helpers
  class-vbr-balloons.php        Balloon table helpers, colours/strategies, trails, asset versions
  class-vbr-settings.php        Settings screen + VBR_Settings::get(); registers Leaflet
  class-vbr-cron.php            Scheduling (Action Scheduler or WP-Cron) and the tick
  class-vbr-weather.php         Open-Meteo wind, batched by ~11 km grid cell, cached in transients
  class-vbr-geo.php             Great-circle destination / distance
  class-vbr-map.php             REST API + [balloon_map]
  class-vbr-frontend.php        [balloon_leaderboard], [balloon_races] + frontend assets
  class-vbr-pages.php           Tracking page: create on install, Settings → Pages, tracking_url()
  class-vbr-account.php         My Account → My balloons
  class-vbr-admin.php           Leaderboard admin page (per race)
  class-vbr-admin-races.php     Races admin page
  class-vbr-product-type.php    "Balloon race entry" product type, its admin tab, 1.2 → 1.3 product migration
  class-wc-product-balloon-entry.php  The product class (always virtual)
  class-vbr-woocommerce.php     Balloon product fields, cart, order → balloon rows
  class-vbr-race-entries.php    Race choice, purchasability, basket guard, race_id on balloons
  class-vbr-teams.php           Teams: per-race lists, scoring, standings, the team editor
  class-vbr-team-entries.php    Team choice on the product page → basket → order → team_id on balloons
  class-vbr-monetization.php    Paid add-ons: strategy fee, custom launch, logo upload
  class-vbr-cli.php             wp vbr …
assets/js/balloon.js            Balloon drawing (SVG) shared by the map and product page
assets/js/map.js                Public Leaflet map
assets/js/product.js            Product page launch picker + logo preview
assets/js/admin-settings.js     Launch-point picker (Settings and race form)
assets/js/admin-teams.js        Team list editor (Settings and race form)
```

### Data

- `{prefix}vbr_races`: one row per race. All times are stored in **UTC**. Stages are calculated from the clock and never stored.
- `{prefix}vbr_teams`: one row per team, with `race_id`, `name`, `color`, `sort`. Races have `team_scoring` ('' = the Settings default).
- `{prefix}vbr_balloons`: one row per balloon, with `race_id`, `team_id`, current `latitude`/`longitude`, `start_lat`/`start_lng`, `trail_json` (the most recent `[lat, lng]` points, capped by the trail-length setting), `logo_attachment_id`, `has_custom_start`, `distance_km` and `is_burst`.
- Settings: the `vbr_settings` option. Tracking page: `vbr_tracking_page_id`. Last tick per race: the `vbr_tick_summaries` option.
- Upgrades run on `plugins_loaded` whenever `vbr_db_version` is older than `VBR_DB_VERSION`. Upgrading from 1.1 creates a first race and moves existing balloons into it. Steps that need WooCommerce or rewrite rules (product conversion, default pages, flushing rewrites) are queued in `vbr_pending_setup` and run on the next `init`.

### How a tick works

For each **Flying** race, a scheduled tick simulates the time since that race's last tick, counting from launch, stopping at the end time, and capped at 3× the interval. For each balloon still flying:

1. Look up the wind for the balloon's grid cell. If that fails, use the wind at the launch point, then the default heading.
2. Travel bearing = wind direction + 180° (Open-Meteo reports where wind comes *from*), ±12° random jitter.
3. Distance = base speed × helium multiplier × hours × wind factor × (1 ± variance). The wind factor is a clamped blend of base speed and real wind speed.
4. Move to the new position, add it to the trail, then roll for bursts. Daily rates are converted to the tick length: `1 − (1 − daily)^(days)`.

Uncached grid cells are fetched in batches (up to 50 per request), and a failed lookup is remembered for 2 minutes. A lock stops two ticks running at once.

### REST API (public, no auth)

| Route | Returns |
| --- | --- |
| `GET /wp-json/vbr/v1/races` | `{ current, races: [{ id, name, slug, status, status_label, entries_open, launch_at, end_at, launch }] }` |
| `GET /wp-json/vbr/v1/balloons?race=<id\|slug\|current>` | `{ race, launch, updated_at, next_at, balloons: [{ id, rank, name, owner, color, hex, helium, distance, burst, lat, lng, logo, logo_fit, team, trail }], teams: [{ id, rank, name, color, balloons, score, leader }], scoring }`. `teams` is empty and `team` null when the race doesn't use teams |

Emails and order details are never included, and owners are first name + initial. Responses are sent with no-cache headers, including LiteSpeed's.

### WP-CLI

```
wp vbr tick                              # run due updates for every flying race (use from a system cron)
wp vbr tick --race=<id|slug> --hours=6   # simulate hours for one race
wp vbr races                             # list races, stages and balloon counts
wp vbr status                            # schedule + last tick per race
```

### Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `vbr_is_balloon_product` | filter | Treat other products as balloon entries (`$is_entry, $product_id`) |
| `vbr_product_data_panel` | action | Add fields to the product's **Balloon race** tab |
| `vbr_new_balloon_row` | filter | Change a balloon row before insert (`$row, $order_item, $order`) |
| `vbr_helium_option_label` | filter | Helium option text on the product page |
| `vbr_product_fields_before` / `vbr_product_fields_after` | action | Add fields inside the balloon box |
| `vbr_color_hex` | filter | Map colours for balloon colour names |
| `vbr_public_owner_name` | filter | Public owner name, after shortening to first name + initial (`$name, $balloon`) |
| `vbr_after_tick` | action | Runs after a race moves (`$summary`) |

### Testing on the dev site

Commands run inside the WordPress Docker container:

```
docker exec -u www-data -w /var/www/html wordpress wp vbr status
docker exec -i wordpress php -l < includes/class-vbr-races.php
```

Deploy with `rsync` into the existing plugin folder (`scp -r` into an existing folder creates a nested copy).

---

## Known gaps

See [`race_settings_and_map_9db2297a.plan.md`](race_settings_and_map_9db2297a.plan.md) for the full plan and status. In short, not built yet:

- Refunded or cancelled orders don't remove their balloons
- Abandoned logo uploads are tagged (`_vbr_logo_upload`) but not cleaned up
- Movement settings (speed, interval, bursts) apply to all races, not per race
- No entry limits per race, and no emails to entrants (launch, results)
- No uninstall cleanup, no translation template (`.pot`), no automated test suite
- Logo upload needs the standard product form (themes using AJAX add-to-cart on single products won't send the file)
- Balloons can drift over sea; there's no land awareness
- The leaderboard renders every balloon in the race (rows past `limit` start hidden), so very large races make a long page
- Matching balloons by email trusts the account and billing emails, which WooCommerce doesn't verify. Someone who sets their email to another person's sees that person's balloons marked as theirs (balloon name and short owner name only)
