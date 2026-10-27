# Race-day checklist

What to check on your own site before a real race. The plugin's automated tests and a full rehearsal (below) cover how it behaves; these are the things only your site can confirm: your payment provider, your email delivery and your hosting.

## A few weeks before

- [ ] **Update** the plugin to the latest release and WooCommerce to a current version. If your site uses LiteSpeed Cache (or another page cache), purge it afterwards.
- [ ] **Create the race**: entries open, launch and end times, launch point, entry limit if any, flight mode. Check the times show in your site's timezone (Settings → General).
- [ ] **Teams, prizes and sponsors** on the race screen, if you use them. Open the tracking page and check the prize cards, "presented by" and the sponsor wall look right.
- [ ] **The entry product**: price, picture and description. It only appears in the shop once the race is planned.
- [ ] **Automatic updates** (Settings → Race movement): pick an interval. WordPress only runs scheduled jobs when someone visits; for on-time updates on a quiet site, ask your host for a real cron job running `wp lvbr tick` (or `wp cron event run --due-now`) every few minutes.

## Payments: one real order

- [ ] Switch your payment provider (Stripe, PayPal, …) to **live** mode, or use its test mode with its test cards first.
- [ ] Buy one entry yourself with every add-on you sell (helium strategy, custom launch point, logo). Check the basket total, the order's line details and that the balloon appears in **Balloon Race → Leaderboard**.
- [ ] If you take bank transfers: an unpaid order holds its place but has no balloon until you mark it paid.
- [ ] **Refund** that order (WooCommerce → Orders → Refund). Its balloon should show as withdrawn in the admin and leave the public map and leaderboard.

## Emails: delivered, not just sent

WordPress sends email through your host's mail server by default, which often lands in spam or isn't delivered at all.

- [ ] Use a proper mail service (your host's SMTP, or a plugin such as WP Mail SMTP with your email provider), with SPF and DKIM set up for your domain.
- [ ] Send yourself the test order above and check it **arrives** (not in spam), with the **Track your balloon** links.
- [ ] In **WooCommerce → Settings → Emails**, check "Balloon race: launched" and "Balloon race: final results" are on, and read their subject and wording.
- [ ] After launch, the race screen should say "Launch email: sent to N people"; after the end, the same for the results email.

## Launch day

- [ ] Entries close at the launch time: the product can no longer be bought for this race.
- [ ] Check the map an hour or two after launch: balloons should have moved, and "Last update" should be recent.
- [ ] If you use page caching, make sure the tracking page isn't cached for long (the map and leaderboard fetch live data, but the page around them can be stale).

## After the race

- [ ] Check the final leaderboard and the prize winners on the tracking page.
- [ ] **Balloon Race → Leaderboard → Export CSV** for your records (it has full names and emails, so keep it private).
- [ ] If you had sponsors, download **views and clicks** from the race screen to thank them.

## The rehearsal (October 2026)

On a test site (WordPress 7.1.3, WooCommerce 11.2.1, Twenty Twenty-Five) with WooCommerce's built-in test payments and a mail catcher, a guest bought two balloons (£8 with Max Height, a custom launch point and a logo; £3 plain) and paid "on the day"; another paid by bank transfer. Checked: basket and order totals and line details; balloons created with the right options; the thank-you page's Track links; order emails delivered with Track links; the bank-transfer order holding its place until marked paid; a partial refund withdrawing one balloon and sending WooCommerce's refund email; **Launch now** sending launch emails (refunded balloon left out, sponsor line included); flying; **Finish now** sending results emails with positions, team results, prize winners and the sponsor line. Everything worked.
