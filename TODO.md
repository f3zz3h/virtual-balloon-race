# To do

Things agreed but not done yet. Tick them off (`[x]`) or delete them as they're done.

## Next steps (in priority order)

1. [x] **Refunds and cancellations** (v1.7.0): refunded, cancelled and failed orders and refunded lines withdraw their balloons; reopening or deleting a refund reinstates them; Withdraw / Reinstate by hand
2. [x] **Release zip** (`.github/workflows/release.yml`): a `v*` tag builds the zip (files listed in `.distignore` left out) and publishes a GitHub release; pull requests build it as a download, and the smoke test installs from it.
   - [x] Tagged `v1.7.0`: first release published
   - [ ] Live site: install from release zips from now on (see README "Moving from a hand-copied folder")
3. [x] **Emails to entrants** (v1.8.0): launch and final-results WooCommerce emails, one per person, switchable in WooCommerce → Settings → Emails
4. [x] **Housekeeping.**
   - [x] `LICENSE` file (GPL-2.0)
   - [x] Uninstall clean-up (`uninstall.php`): Settings → Data → "Delete all race data when the plugin is deleted" (off by default)
   - [x] Translation template (`languages/virtual-balloon-race.pot`, refreshed in every release zip)
   - [x] Abandoned logo uploads deleted after 7 days; a basket entry whose logo has gone is removed with a notice
5. [x] **Entry limits per race** (v1.10.0): optional limit; paid and unpaid orders hold places; sold out in the shop; "Only N places left!"

All five next steps are done. Next: release 1.10.0, then pick from the stretch goals below.

Done since: leaderboard paging (10 entries per page by default, with a dropdown to change it); donation links; WordPress.org readme; demo race, which renews itself.

## Project website

https://www.lumaha.uk/balloonrace/ shows the plugin with a live demo race (which renews itself), and links from the portfolio at https://www.lumaha.uk/. Hosting, access and the scripts that build the sites are kept in a separate private repo, as are the dev site's details.

- [ ] After each release, upload the zip to the project site (Plugins → Add New → Upload → "Replace current with uploaded"). Or automate it:
  - A step in `release.yml` that copies the built plugin to the project site over SFTP, with a deploy-only login kept in GitHub Secrets. Then visit the site once, so database upgrades run
  - Or an update checker in the plugin that offers new GitHub releases as normal WordPress updates (WordPress's `Update URI` header and the `update_plugins_{hostname}` filter), with auto-updates on for the project site. This also helps every other site using the plugin, but needs the repo to be public (or a token). Not allowed in the WordPress.org version, which updates from WordPress.org, so leave it out of that build
- [ ] Download page: add the zip and release notes when the repo goes public

## Going public

The public repo will be a **new** repo made from this one's history, rewritten so it carries no private details; this private repo stays as the full archive (GitHub keeps every pull request's commits, so making this one public would expose them). The step-by-step procedure and the rewrite rules are in the private notes repo.

- [x] Audit: no passwords, tokens or keys have ever been committed
- [x] Dev site, hosting and website details moved to the private notes repo
- [ ] GitHub → Settings → Emails: tick **Keep my email addresses private** (commits then use the noreply address)
- [ ] When ready: create the public repo from the rewritten history, check it, then point the Download page and readme at it

## Stretch goals

Free for anyone to use (GPL-2.0-or-later), with an optional donation link (https://buymeacoffee.com/lumaha: `VBR_Support::DONATE_URL`, `Donate link:` in `readme.txt`, `.github/FUNDING.yml`).

### WordPress.org listing
- [x] `readme.txt` in WordPress.org format, with the External services section; Leaflet bundled (no CDN), as WordPress.org requires
- [ ] Confirm the WordPress.org username for `Contributors:` in `readme.txt` (currently `f3zz3h`, the GitHub name)
- [ ] Choose the plugin's `Author` (and optional `Author URI` / `Plugin URI`) in the plugin header: currently "Virtual Balloon Race"
- [ ] Listing assets (in the SVN `assets/` folder, not the zip): `screenshot-1.png`…`screenshot-5.png` matching the readme's Screenshots section, `banner-772x250.png`, `icon-256x256.png`
- [ ] Submit at wordpress.org/plugins/developers/add/ and work through the review

### Features
- [ ] Settings for outside services, so busier sites stay within the providers' terms: an Open-Meteo API key (its commercial plan), a map tile provider (e.g. MapTiler, Stadia or Thunderforest, with a key) instead of tile.openstreetmap.org, and the place search's rate limit and identification (Nominatim policy)
- [ ] Per-race movement settings (speed, interval and burst rates are site-wide)
- [ ] Logo upload with themes that use AJAX add-to-cart on single products
- [ ] Very large races: page the public leaderboard on the server, with a search endpoint, instead of sending every balloon to the browser
- [ ] Only match "My balloons" by email once the email is verified (today it trusts account and billing emails; only balloon names and short owner names are exposed)
- [ ] Embeddable map for sites that aren't WordPress (an iframe endpoint)
- [ ] Land and sea awareness; richer weather (gusts, altitude bands)

### Not tested yet (by hand)
- [ ] Real checkout with payment, and the order emails actually being delivered (so far the email output was rendered directly)
- [ ] Price and stock fields showing and hiding on the product edit screen when switching product type (WooCommerce's own admin script)
- [ ] The basket guard when a *different* race is still open for entries

### Code quality
- [ ] Raise PHPStan from level 5 to level 6 (about 200 findings today, mostly missing array type annotations)
- [ ] AI code review on pull requests (the Claude Code GitHub Action or GitHub Copilot code review): costs per review, so not yet

## GitHub setup

### Protect `main`
- [ ] Rulesets are free on public repos (a private repo needs GitHub Pro or Team): set this up on the public repo. See **Settings → Rules**.
- [ ] **Settings → Rules → Rulesets → New branch ruleset**, targeting `main`:
  - [ ] Require a pull request before merging, with **0** required approvals (you can't approve your own PR)
  - [ ] Block force pushes
  - [ ] Restrict deletions
  - [ ] Require status checks to pass: "PHP 7.4 syntax", "PHP 8.0 syntax", "PHP 8.3 syntax", "WordPress Coding Standards", "PHPStan", "Fresh install smoke test"
  - [ ] Add yourself to the bypass list, for emergencies only
- [x] GitHub CLI (`gh`) installed in WSL (`~/.local/bin/gh`) and logged in, so Claude can open PRs

### GitHub Actions checks (run on every PR)
- [x] PHP syntax check on PHP 7.4 (the plugin's minimum) up to 8.3 (`.github/workflows/php_check.yml`)
- [x] WordPress Coding Standards (PHPCS): errors fail the check; warnings (82, mostly direct queries on the plugin's own tables) are reported only. Consider fixing or explicitly ignoring them later
- [x] PHPStan static analysis at level 5, with WordPress and WooCommerce stubs (`phpstan.neon.dist`); no findings
- [x] Fresh-install smoke test: latest WordPress + WooCommerce, both activation orders, a race end to end, no PHP errors (`.github/workflows/smoke_test.yml`, `tests/smoke/`)
- [x] Release zip (`.github/workflows/release.yml`, see next step 2)

### Other GitHub housekeeping
- [ ] Move the open items in this file into GitHub Issues
- [ ] Turn on Dependabot for GitHub Actions and Composer (dev tools) versions

## Other open items
- [x] Demo race: Settings → Demo race (Create / Remove) and `wp vbr demo [--remove]`; a fresh one replaces it automatically when it's about to end (on by default)
- [ ] Delete the old `f3zz3h-php-check` and `f3zz3h-demo-action` branches and the `demo.yml` workflow if they're no longer needed
- [ ] LiteSpeed: after each plugin update on the live site, run **LiteSpeed Cache → Purge All**, and check its JS/CSS minify/combine settings so changed files get new addresses (browsers kept old copies during testing)
