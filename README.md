# Virtual Balloon Race

A WordPress + WooCommerce plugin for running virtual balloon races as fundraisers. Supporters buy a balloon entry for a race, customise it (name, colour, helium strategy, and optional paid extras), and after launch the balloons drift across a live map using real wind data. A leaderboard ranks balloons by how far they get from the launch point (or, per race, by distance travelled).

- **Requires:** WordPress 6.0+, PHP 7.4+, WooCommerce 7.0+ (for selling entries)
- **Version:** 1.10.0 (database schema 1.10.0)
- **Licence:** GPL-2.0-or-later

---

## For site admins

### 1. Install

1. Download `virtual-balloon-race-<version>.zip` from the repository's **Releases** page on GitHub.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, then **Install Now** and **Activate**.
3. Make sure WooCommerce is active. Without it the leaderboard and map still work, but entries can't be sold.

**Updating:** upload the newer zip the same way. WordPress notices the plugin is already installed and offers **Replace current with uploaded**. Races, balloons and settings are kept, and any database upgrade runs on the next page load. If the site uses LiteSpeed Cache, run **LiteSpeed Cache → Purge All** afterwards.

**Deleting the plugin** keeps all race data unless **Settings → Data → Delete all race data when the plugin is deleted** is ticked. Its scheduled jobs always stop.

**Translations:** `languages/virtual-balloon-race.pot` is the translation template (refreshed in every release zip). Translate it with Poedit or Loco Translate, or put `virtual-balloon-race-<locale>.mo` in `wp-content/languages/plugins/`.

**Moving from a hand-copied folder** (e.g. `wp-content/plugins/VirtualBaloonRace/`): the zip installs to `wp-content/plugins/virtual-balloon-race/`, which WordPress treats as a different plugin, so don't run both. Deactivate the old one, delete it (the plugin has no uninstall routine, so this removes only its files; races, balloons, settings and pages stay in the database), then upload and activate the zip.

### 2. Check the settings

**Balloon Race → Settings** holds the rules shared by every race:

| Setting | What it does |
| --- | --- |
| Flight mode | How quickly balloons lose lift, and so how far a race goes (each race can choose its own): **Drift** (true to life: fast on launch day, then lands within about a day and a half), **Soar** (default: slows gradually, speed halves every 36 hours of flight), **Jetstream** (never slows, today's long-distance behaviour). The Settings cards estimate the leader's distance after 1 day, 3 days and a week at the current base speed |
| Auto-update interval | How often balloons move: off, 5 min, 30 min, hourly, 6-hourly or daily |
| Base speed, helium multipliers, random variance | How far balloons travel per hour. Max Height is faster, Eco-Glide slower |
| Default launch point | Pre-filled when you create a race. Search a place or click the map |
| Live wind / per-balloon wind / wind influence | Real wind from Open-Meteo (free, no key). Per-balloon wind makes paths split apart as balloons spread |
| Default heading | Direction balloons travel if wind data isn't available |
| Burst chances | Max Height burst chance per day, plus storm bursts above a wind threshold (Eco-Glide is immune) |
| Map and leaderboard | Default zoom, how often the public map refreshes, trail length, and **leaderboard entries per page** (10 by default; visitors can change it) |
| Shop add-ons | Turn extras on or off and set their prices |
| Demo race | **Create a demo race**: 30 made-up balloons, launched three days ago and flown on real wind, ending in four days, so the map and leaderboard have something to show. Demo entrants have no email addresses or orders, so nobody is emailed. **Keep it running** (on by default): once a day, a demo race with less than a day left is replaced by a fresh one with as many balloons. **Remove demo races** deletes them. Also `wp vbr demo [--balloons=30]` and `wp vbr demo --remove` |
| Data | **Delete all race data when the plugin is deleted** (off by default). Deleting the plugin from Plugins then removes its races, balloons, teams and settings; pages, products, orders and uploaded logos are kept either way. Deactivating never deletes anything |
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
| Entry limit | Optional. The most balloons the race can have. Paid balloons take places, and so do unpaid orders still in progress (bank transfer on hold, or awaiting payment from the last day). When full, the shop shows "Sorry, … is sold out", and baskets can't hold more entries than there are places left. With 10 or fewer left, the product page shows "Only N places left!". Refunded or cancelled entries free their places. Blank means no limit |
| Flight mode | Use the default from Settings, or pick Drift, Soar or Jetstream for this race |
| Winner decided by | **Distance from launch** (default): straight-line distance from where the balloon started, as in real balloon races. **Distance travelled**: the whole trail added up, so wandering balloons score more. Team scores use the same measure |

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

A fresh install creates a ready-to-sell **Balloon Race Entry** product (£3, published, any race taking entries) as soon as WooCommerce is active. It can only be bought while a race is taking entries, so it's safe to leave published. Change its name, price, description and picture like any product. Upgrades never add a product. **Settings → Pages and products** lists your entry products and offers **Create entry product** if there are none.

To sell a particular race separately: open the race (**Balloon Race → Races → Edit**) and click **Create entry product**. This makes a draft product locked to that race and opens it so you can set the price and publish.

Or create one by hand:

1. **Products → Add New**, and set **Product data** to **Balloon race entry**. Entries are always virtual, so there's no shipping.
2. Set the price (e.g. £3) on the **General** tab.
3. On the **Balloon race** tab, choose which race it sells:
   - **Any race taking entries** (default): buyers enter whichever race is open. If several are open, they pick one.
   - **A specific race**: the product only sells entries for that race.

The race's edit screen lists every product that sells its entries.

When no race is taking entries, the product can't be added to the basket and shows when entries next open. If a race launches while an entry for it is still in someone's basket, the entry is removed with a message.

> **Upgrading from 1.2:** products marked with the old "Balloon Entry" tick box or `balloon-entry` tag are converted to the new type automatically. Products that weren't simple products (e.g. variable) can't be converted; a notice in admin lists them.

On balloon entry products, the product images are replaced by a **live balloon preview**: a balloon on a sky background that follows the buyer's choices (colour, uploaded picture, balloon name, team and helium strategy). To keep the normal product images, add `add_filter( 'vbr_product_preview', '__return_false' );`.

Buyers fill in:

- **Balloon name**, **colour** and **helium strategy** (Balanced is free; Max Height and Eco-Glide cost the "tactical strategy" price)
- Optional **custom launch point** (+£2 by default): search or click a map to start from their school or hometown
- Optional **logo or picture** (+£2 by default): PNG, JPG or WebP up to 1 MB. It fills the balloon on the map; very wide or tall pictures are shown whole instead of cropped. The product page's balloon preview shows it as soon as it's chosen

Entries are sold **one balloon per basket line**: there's no quantity box on the product page or in the basket, because each balloon has its own name, colour and picture. After adding a balloon, the buyer sees "“Sky Rocket” is in your basket" with **Design another balloon** and **View basket**; the form keeps their race, team and colour and clears the name for the next one. Identical balloons can still be added as separate lines.

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
| `[balloon_leaderboard]` | `race` (as above). `per_page`: entries per page (default from Settings, 10; `all` for everything; `limit` is the older name). `map_url`: where names link (default: the tracking page) |
| `[balloon_teams]` | Team standings: rank, team, balloons, score, leading balloon. `race` (as above), `title`. Shows nothing when teams are off or the race has none |
| `[balloon_races]` | Links to every race on the same page. `title` (default "All races"). `min` (default 2): hidden when there are fewer races |

On the map, clicking a balloon (or its name in the list or leaderboard) highlights its trail and fades the others. Click the map background to clear the highlight.

#### What entrants see

- **Their own balloon.** Logged-in entrants see their balloons marked **Yours**. The map opens on their best-placed balloon, the leaderboard pins **Your balloons** with each one's position, and both have an **All balloons / My balloons** toggle. A balloon counts as theirs if they bought it while logged in, or if the order's email matches their account email or billing email (so guest orders count once they register).
- **Guests** get **Track your balloon** links on the order confirmation page and in their order emails.
- **Search.** The map list and the leaderboard both search by balloon name, owner or team. Leaderboard search covers every balloon in the race, not just the page being shown.
- **Paging.** The leaderboard shows 10 balloons per page by default, with page links and a **Show … per page** dropdown (10, 25, 50, 100 or All). A visitor's choice is remembered on their device. Search and **My balloons** page their results too. The admin leaderboard pages the same way, with its own dropdown.
- **Owner names** are shown publicly as first name and last initial ("Luke H."), on the map, the leaderboard and the REST API. Search only matches that short form. Admin screens and the CSV keep full names.

#### Refunds and cancellations

Balloons follow their orders:

- **Cancelled, refunded or failed order:** all of its balloons are **withdrawn**. They disappear from the map, leaderboard, team scores and API, and stop moving, but stay in the admin leaderboard and CSV marked *Withdrawn (refunded)*, *(order cancelled)* or *(payment failed)*.
- **One line refunded** (in WooCommerce's refund screen, with that line's quantity or total): just that balloon is withdrawn. A refund of an amount with no lines selected (e.g. a goodwill partial refund) leaves balloons alone.
- **Order back to Processing or Completed, or a refund deleted:** the balloons come back where they were.
- **By hand:** **Withdraw** / **Reinstate** on the admin leaderboard, e.g. for a duplicate or an unsuitable name. A balloon withdrawn by hand stays out even if its order changes.

Customers see withdrawn balloons in **My Account → My balloons** with the reason, and get no tracking link for them.

#### Emails to entrants

Two WooCommerce emails go to everyone with a balloon in a race (one email per person, listing all their balloons; withdrawn balloons are left out):

- **Balloon race: launched**, when the race launches (at its launch time, or **Launch now**): each balloon with a **Track it** link, and when the race ends.
- **Balloon race: final results**, when the race finishes (at its end time, or **Finish now**): each balloon's position, distance from launch (or travelled) and its team's finishing position, with a link to the final leaderboard.

Switch either off, or change its subject, heading and closing text, in **WooCommerce → Settings → Emails**. They use the shop's email header, footer and colours. Each race's screen shows whether its emails have gone out and to how many people. Emails are queued with WooCommerce's Action Scheduler and sent in the background, so large races don't slow the site. The plugin checks for launches and finishes hourly, after every movement update, and straight away on **Launch now** / **Finish now**. Upgrading to 1.8.0 marks races that have already launched or finished as emailed, so nothing old is sent. Theme overrides: copy `templates/emails/vbr-balloon-launched.php` (or `vbr-balloon-results.php`, and the `plain/` versions) to `yourtheme/woocommerce/emails/`.

### 6. During the race

**Balloon Race → Leaderboard** (pick the race at the top):

- See every balloon, its owner's email, distance and status
- **Advance now** moves the race by a chosen number of hours, or by a fixed km range
- **Mark burst / Undo burst** and **Withdraw / Reinstate** for individual balloons
- **Export CSV** of the race's balloons and donors (with a Withdrawn column)

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
  class-vbr-flight-modes.php    Flight modes (Drift, Soar, Jetstream): lift decay, landing, Settings cards
  class-vbr-demo.php            Demo races (Settings → Demo race, wp vbr demo)
  class-vbr-housekeeping.php    Daily clean-up of abandoned logo uploads; basket check for removed logos
  class-vbr-race-emails.php     Launch and results emails: when to send, who to, background queue
  emails/                       The two WooCommerce email classes (templates in templates/emails/)
  class-vbr-order-sync.php      Withdraws / reinstates balloons when orders are refunded, cancelled or fail
  class-vbr-team-entries.php    Team choice on the product page → basket → order → team_id on balloons
  class-vbr-monetization.php    Paid add-ons: strategy fee, custom launch, logo upload
  class-vbr-product-preview.php Live balloon preview in place of the product images
  class-vbr-cli.php             wp vbr …
assets/vendor/leaflet/          Leaflet 1.9.4 (BSD-2-Clause), bundled: no third-party CDN
assets/js/balloon.js            Balloon drawing (SVG) shared by the map and product page
assets/js/map.js                Public Leaflet map
assets/js/product.js            Product page launch picker + logo preview
assets/js/admin-settings.js     Launch-point picker (Settings and race form)
assets/js/admin-teams.js        Team list editor (Settings and race form)
```

### Data

- `{prefix}vbr_races`: one row per race. All times are stored in **UTC**. Stages are calculated from the clock and never stored.
- `{prefix}vbr_teams`: one row per team, with `race_id`, `name`, `color`, `sort`. Races have `team_scoring` ('' = the Settings default).
- Flight modes (`class-vbr-flight-modes.php`): each balloon stores `flight_hours`; a tick converts its hours into full-speed-equivalent hours by integrating the mode's half-life decay over the tick (so the update interval doesn't change the result), and Drift stops balloons after 36 flight hours. Races have `flight_mode` ('' = the Settings default).
- Distances: `distance_km` is the whole trail added up; `from_start_km` is the straight-line distance from the balloon's start (its custom launch point, else the race's), updated on every tick. Races have `ranking` ('' or `from_start` = distance from launch, `travelled` = distance travelled), and leaderboards, team scores and REST rank by that column.
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
| `GET /wp-json/vbr/v1/races` | `{ current, races: [{ id, name, slug, status, status_label, entries_open, launch_at, end_at, launch, entry_limit, places_left }] }` (`entry_limit` and `places_left` are null when there's no limit) |
| `GET /wp-json/vbr/v1/balloons?race=<id\|slug\|current>` | `{ race, launch, updated_at, next_at, balloons: [{ id, rank, name, owner, color, hex, helium, distance, burst, lat, lng, logo, logo_fit, team, trail, from_start, travelled }], teams: [{ id, rank, name, color, balloons, score, leader }], scoring, ranking }`. `distance` is the measure the race ranks by. `teams` is empty and `team` null when the race doesn't use teams |

Emails and order details are never included, and owners are first name + initial. Responses are sent with no-cache headers, including LiteSpeed's.

### WP-CLI

```
wp vbr tick                              # run due updates for every flying race (use from a system cron)
wp vbr tick --race=<id|slug> --hours=6   # simulate hours for one race
wp vbr races                             # list races, stages and balloon counts
wp vbr status                            # schedule + last tick per race
wp vbr demo [--balloons=30]              # create a demo race with made-up balloons
wp vbr demo --remove                     # remove demo races
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
| `vbr_default_entry_product` | filter | Name, price and descriptions of the product a fresh install creates (`array( name, price, short_description, description )`) |
| `vbr_product_preview` | filter | Return false to keep the product images instead of the live balloon preview (`$show, $product`) |
| `vbr_public_owner_name` | filter | Public owner name, after shortening to first name + initial (`$name, $balloon`) |
| `vbr_after_tick` | action | Runs after a race moves (`$summary`) |

### Automated checks (GitHub Actions)

Every pull request and push to `main` runs:

- **PHP syntax** on PHP 7.4, 8.0 and 8.3, and **WordPress Coding Standards** (`.github/workflows/php_check.yml`). Coding-standard errors fail the check; warnings are reported only.
- **PHPStan** static analysis at level 5 with WordPress and WooCommerce stubs (`phpstan.neon.dist`). Run it locally with `composer install` then `composer phpstan`. `composer.json` holds development tools only; the plugin itself has no Composer dependencies, and `vendor/` isn't committed. `phpstan-baseline.neon` is empty; if the level is raised, record existing findings there so only new ones fail.
- **Fresh install smoke test** (`.github/workflows/smoke_test.yml`): installs the latest WordPress and WooCommerce, then `tests/smoke/run.sh` checks both activation orders (plugin before and after WooCommerce), the default page and product, a race end to end (purchasability, order → balloon, a tick, REST, teams), refunds and cancellations (line refund, refund deleted, goodwill refund, cancel and reopen, hand withdrawal, failed payment), the launch and results emails (one per person, withdrawn balloons left out, sent once, switched off in WooCommerce), housekeeping (abandoned logos, the basket check), entry limits (paid and unpaid places, sold out, basket limits, places freed), deleting the plugin with "Delete all race data" off and on and that the plugin logs no PHP errors. To run it by hand: set `WP_PATH` to a throwaway WordPress with WooCommerce and this plugin (as `plugins/virtual-balloon-race`) copied in, then `bash tests/smoke/run.sh`. It empties that site's database.

### WordPress.org readme and donations

`readme.txt` is the plugin's WordPress.org listing (description, FAQ, **External services**, changelog). Keep `Stable tag` and the changelog in step with the version when releasing.

Donations go to https://buymeacoffee.com/lumaha: a **Donate** link on the Plugins screen and a short "Support this plugin" note at the foot of Settings (`VBR_Support::DONATE_URL` in `includes/class-vbr-support.php`, or the `vbr_donate_url` filter; empty hides both), `Donate link:` in `readme.txt`, and GitHub's Sponsor button (`.github/FUNDING.yml`).

### Releasing a version

1. On a branch, bump the version in the plugin header (`Version:`) and `VBR_VERSION` in `virtual-balloon-race.php` (and `VBR_DB_VERSION` if the schema changed). Merge it.
2. Tag `main` and push the tag: `git tag v1.7.0 && git push origin v1.7.0`.
3. The **Release** workflow (`.github/workflows/release.yml`) checks the tag matches the plugin version, builds the zip with `.github/scripts/build-zip.sh` and publishes a GitHub release with the zip attached and notes listing the merged pull requests.

`.distignore` lists the files left out of the zip (tests, CI, Composer and PHPStan files, TODO, the plan). Every pull request also builds the zip and offers it as a download (**Checks → Release → Artifacts**, kept 7 days), and the fresh-install smoke test installs from that same zip. Build one locally with `bash .github/scripts/build-zip.sh` (output in `dist/`).

### Testing locally

Any WordPress with WooCommerce will do (for example [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) or the official Docker images). Copy the plugin in with the same exclusions as the zip:

```
rsync -rlt --delete --exclude-from=.distignore --exclude dist ./ /path/to/wp-content/plugins/virtual-balloon-race/
wp vbr status
wp vbr demo --balloons=10
```

`scp -r` into an existing folder creates a nested copy; use rsync. The fresh-install smoke test runs locally too: `WP_PATH=/path/to/wordpress bash tests/smoke/run.sh` (see the comments at its top).

---

## Known gaps

See [`race_settings_and_map_9db2297a.plan.md`](race_settings_and_map_9db2297a.plan.md) for the full plan and status. In short, not built yet:

- Movement settings (speed, interval, bursts) apply to all races, not per race
- Logo upload needs the standard product form (themes using AJAX add-to-cart on single products won't send the file)
- Balloons can drift over sea; there's no land awareness
- The public leaderboard still sends every balloon in the race to the browser (so search can find any of them) and pages them there; very large races (thousands of entries) make a heavy page
- Matching balloons by email trusts the account and billing emails, which WooCommerce doesn't verify. Someone who sets their email to another person's sees that person's balloons marked as theirs (balloon name and short owner name only)
