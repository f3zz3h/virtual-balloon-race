#!/usr/bin/env bash
# Fresh-install smoke test.
#
# Needs a WordPress install at $WP_PATH (database configured, WP_DEBUG_LOG on) with
# WooCommerce and this plugin (as plugins/virtual-balloon-race) copied in but not active.
# Each scenario starts from an empty database. Used by .github/workflows/smoke_test.yml.
set -euo pipefail

WP_PATH="${WP_PATH:?Set WP_PATH to the WordPress install}"
WP_CLI="${WP_CLI:-wp}"
PLUGIN="virtual-balloon-race"
CHECKS="$(cd "$(dirname "$0")" && pwd)/checks.php"
LOG="$WP_PATH/wp-content/debug.log"

# `command` so this calls WP-CLI itself, not this function again.
wp() { command "$WP_CLI" --path="$WP_PATH" "$@"; }

# Empty the database from PHP (`wp db reset` needs the mysql command-line client).
fresh_site() {
	wp eval 'global $wpdb; foreach ( $wpdb->get_col( "SHOW TABLES" ) as $table ) { $wpdb->query( "DROP TABLE `$table`" ); }' --skip-plugins --skip-themes
	wp core install --url=http://localhost --title="Smoke test" --admin_user=admin --admin_password=smoke-test --admin_email=admin@example.com --skip-email --quiet
}

# A request that runs `init`, so setup tasks queued on activation get a chance to run.
next_request() { wp eval 'echo "";' > /dev/null; }

stage() { echo; echo "== $1"; }

stage "Scenario A: plugin first, WooCommerce later"
fresh_site
wp plugin activate "$PLUGIN"
next_request
wp eval-file "$CHECKS" woo-inactive

stage "Scenario A: activate WooCommerce"
wp plugin activate woocommerce
next_request
wp eval-file "$CHECKS" fresh-product

stage "Scenario A: deactivate and reactivate the plugin"
wp plugin deactivate "$PLUGIN"
wp plugin activate "$PLUGIN"
next_request
wp eval-file "$CHECKS" one-product

stage "Scenario A: a race end to end"
wp eval-file "$CHECKS" race-flow

stage "Scenario A: refunds and cancellations"
wp eval-file "$CHECKS" refunds

stage "Scenario B: WooCommerce first, then the plugin"
fresh_site
wp plugin activate woocommerce
wp plugin activate "$PLUGIN"
next_request
wp eval-file "$CHECKS" fresh-product

stage "PHP errors from the plugin"
if [ -f "$LOG" ] && grep -E "PHP (Fatal error|Parse error|Warning|Notice|Deprecated).*plugins/$PLUGIN/" "$LOG"; then
	echo "FAIL the plugin logged PHP errors (above)"
	exit 1
fi
echo "ok   none logged"
