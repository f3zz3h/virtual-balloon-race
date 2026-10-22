=== Virtual Balloon Race ===
Contributors: f3zz3h
Donate link: https://buymeacoffee.com/lumaha
Tags: fundraising, balloon race, charity, woocommerce, school
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run a virtual balloon race fundraiser: sell entries through WooCommerce and watch the balloons drift across a live map on real winds.

== Description ==

A balloon race without the balloons: no litter, no harm to wildlife, and supporters can follow their balloon live. Virtual Balloon Race is made for schools, PTAs, clubs and charities.

Supporters buy an entry in your WooCommerce shop, name their balloon, choose its colour and helium strategy, and can add their own logo or launch point. When the race launches, every balloon drifts across a live map on real wind data, and a leaderboard ranks them by how far they get from the launch point.

= For supporters =

* A live preview of their balloon on the product page as they choose its colour, name, team and logo
* A live map with each balloon's trail; tap a balloon to follow it
* A leaderboard with search and paging, team standings, and "My balloons" for logged-in entrants
* Emails when the race launches and when it finishes, with each balloon's final position
* My Account → My balloons, and "Track your balloon" links on order pages and emails

= For organisers =

* Schedule races: entries open, launch, end; or press Launch now / Finish now on the day
* A tracking page and a ready-to-sell "Balloon Race Entry" product are created for you
* Paid extras: tactical helium strategy, custom launch point, logo upload
* Optional teams (e.g. houses or classes), scored by average, total or best distance
* Optional entry limits, with "Only N places left!" and "Sold out" in the shop
* Three flight modes: Drift (true to life), Soar (the default) and Jetstream (maximum distance)
* Refunded, cancelled or failed orders take their balloons out of the race automatically
* Withdraw or reinstate balloons by hand, mark bursts, advance the race, export a CSV
* Public names are shown as first name and last initial

The map uses [Leaflet](https://leafletjs.com/) (bundled with the plugin) and OpenStreetMap. Wind comes from [Open-Meteo](https://open-meteo.com/). No API keys are needed; busier or commercial sites can add keys for paid map and weather providers. See **External services** below.

== Installation ==

1. Install and activate WooCommerce (needed to sell entries; the map and leaderboard work without it).
2. In **Plugins → Add New Plugin**, upload the plugin zip, then activate **Virtual Balloon Race**.
3. The plugin creates a **Balloon Race** page (map, team standings, leaderboard, race list) and a **Balloon Race Entry** product (£3). Change the product's name, price and picture like any product.
4. Go to **Balloon Race → Races → Add race**: set when entries open, the launch time, an optional end time and the launch point.
5. Check **Balloon Race → Settings**: flight mode, how often balloons move, add-on prices, teams, and more.

For on-time updates on a quiet site, add a real server cron that runs `wp vbr tick` every few minutes (WordPress only runs scheduled jobs when someone visits).

== Frequently Asked Questions ==

= Does it need WooCommerce? =

To sell entries, yes. Without WooCommerce, the map and leaderboard still work for balloons you add.

= Where does the wind come from? =

From Open-Meteo's free weather API, for the area each balloon is in, so balloons that spread apart follow different winds. If the wind can't be fetched, balloons follow a default heading.

= Can I try it before selling entries? =

Yes: **Balloon Race → Settings → Demo race → Create a demo race** makes a race with 30 made-up balloons that have already flown for three days. It renews itself: when it's about to end, a fresh one replaces it (untick **Keep it running** to stop that). Remove it from the same place.

= How far do the balloons go? =

It depends on the wind and the flight mode. In Soar (the default), a week-long race typically sees the leader several hundred to about a thousand kilometres from the launch point. Settings shows an estimate for each mode.

= Who wins? =

By default, the balloon furthest from its launch point in a straight line, as in real balloon races. Each race can instead rank by the whole distance travelled.

= What happens if an order is refunded or cancelled? =

Its balloons are withdrawn: they leave the map, leaderboard and team scores, but stay in the admin screens and the CSV for your records. Reopening the order brings them back.

= Can I turn the emails off? =

Yes. They're WooCommerce emails: switch each off, or change its wording, in **WooCommerce → Settings → Emails**.

= What is kept if I delete the plugin? =

Everything, unless you tick **Settings → Data → Delete all race data when the plugin is deleted**. Pages, products, orders and uploaded logos are always kept.

== External services ==

This plugin connects to the third-party services below. By default it uses free services that need no API keys or accounts; **Balloon Race → Settings → Maps, weather and place search** can switch to keyed providers for busier or commercial sites.

= Open-Meteo (weather) =

Used to move balloons with the real wind. When a race is flying, your server requests the current wind speed and direction for the areas the balloons are in (rounded latitude and longitude) each time balloons move. No personal data is sent; requests identify the plugin and your site's address. With an Open-Meteo API key set, requests go to customer-api.open-meteo.com and include the key.
Service: [open-meteo.com](https://open-meteo.com/). [Terms of use](https://open-meteo.com/en/terms). The free API is for non-commercial use; for commercial use, get an API key.

= Map tiles: OpenStreetMap, MapTiler, Stadia Maps or a custom provider =

Used to draw the map. Visitors' browsers load map images from the chosen provider whenever a map is shown (public race map, Settings, race screen, and the launch point picker on the product page), which shares their IP address and browser details with that provider. With a keyed provider, the key is part of each image address.

* OpenStreetMap (default): tile.openstreetmap.org. [Tile usage policy](https://operations.osmfoundation.org/policies/tiles/). [Privacy policy](https://osmfoundation.org/wiki/Privacy_Policy).
* MapTiler (optional): api.maptiler.com. [Terms](https://www.maptiler.com/terms/). [Privacy policy](https://www.maptiler.com/privacy-policy/).
* Stadia Maps (optional): tiles.stadiamaps.com. [Terms](https://stadiamaps.com/terms-of-service/). [Privacy policy](https://stadiamaps.com/privacy-policy/).
* Custom: whatever tile address the site admin enters.

= Place search: Nominatim or MapTiler =

Used by the "search for a place" box when choosing a launch point (in Settings, on the race screen, and for buyers choosing a custom launch point). When the Search button is pressed, the text typed is sent from the visitor's browser to the chosen service (at most one search a second). Place search can be switched off.

* Nominatim (default): nominatim.openstreetmap.org. [Usage policy](https://operations.osmfoundation.org/policies/nominatim/). [Privacy policy](https://osmfoundation.org/wiki/Privacy_Policy).
* MapTiler (optional, with MapTiler map tiles): api.maptiler.com. [Terms](https://www.maptiler.com/terms/). [Privacy policy](https://www.maptiler.com/privacy-policy/).

== Screenshots ==

1. The live race map with balloon trails, team filter and the balloon list.
2. Team standings and the leaderboard, with paging and search.
3. The product page: a live preview of the balloon as the buyer chooses its colour, logo and team.
4. Settings → Flight mode, with distance estimates for each mode.
5. A race's admin screen: its status, entries against the limit, shortcodes, the emails to entrants, and its dates.

== Changelog ==

= 1.12.0 =
* New: Settings → Maps, weather and place search. Choose the map tiles (OpenStreetMap, MapTiler, Stadia Maps or a custom tile server, with an API key), the place search (Nominatim, MapTiler or off) and an Open-Meteo API key for commercial use.
* Changed: place searches are spaced at least a second apart, as Nominatim's usage policy asks.
* Changed: the plugin's author is Luke Hart, with the project site as the plugin's home page.

= 1.11.0 =
* New: copies installed from the GitHub release zip are offered new versions as normal plugin updates, with release notes under View details and automatic updates if switched on.
* The plugin's code is now public on GitHub: https://github.com/f3zz3h/virtual-balloon-race

= 1.10.0 =
* New: optional entry limit per race, with "Only N places left!" and "Sold out" in the shop. Unpaid orders hold their places.
* New: Leaflet is bundled with the plugin instead of loaded from a CDN.
* New: Settings link on the Plugins screen.
* New: readme.txt for WordPress.org.
* New: Settings → Demo race creates a ready-made race with made-up balloons (also `wp vbr demo`), and replaces it with a fresh one when it's about to end.

= 1.9.0 =
* New: abandoned logo uploads are deleted after 7 days.
* New: optional "Delete all race data when the plugin is deleted" (Settings → Data).
* New: translation template.

= 1.8.0 =
* New: emails to entrants when a race launches and when it finishes.

= 1.7.0 =
* New: refunded, cancelled and failed orders withdraw their balloons; Withdraw / Reinstate by hand.

= 1.6.0 =
* New: flight modes (Drift, Soar, Jetstream).
* New: leaderboard paging, 10 per page by default.

= 1.5.0 =
* New: balloons are ranked by distance from the launch point by default; each race can rank by distance travelled.
* New: a Balloon Race Entry product is created on a fresh install.
* Fix: the race screen no longer fails when WooCommerce is inactive.

= 1.4.0 =
* New: optional teams with team standings.
* New: live balloon preview on the product page; one balloon per basket line.
* New: balloon-shaped map markers, with logos filling the balloon.

= 1.3.0 =
* New: "Balloon race entry" product type, tracking page, My Account → My balloons, "My balloons" on the map and leaderboard.

= 1.2.0 =
* New: race schedules (entries open, launch, end) and per-race sales.

== Upgrade Notice ==

= 1.12.0 =
Busier or commercial sites can now use paid map, place search and weather providers. Nothing changes unless you pick one.

= 1.11.0 =
Installed from GitHub? From this version, new releases appear as normal plugin updates.

= 1.10.0 =
Adds optional entry limits per race. The map library is now bundled with the plugin.
