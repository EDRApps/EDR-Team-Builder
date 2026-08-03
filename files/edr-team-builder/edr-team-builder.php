<?php
/**
 * Plugin Name: EDR Team Builder
 * Description: Endurotech Racing endurance team + stint planner. Pulls Garage 61 pace and official iRacing session times, collects driver availability in-house, and builds Pro/Casual teams and stint rotations. Add the [edr_team_builder] shortcode to a page.
 * Version: 2.4.25
 * Author: Endurotech Racing
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit; // no direct access

define('EDR_TB_DIR', plugin_dir_path(__FILE__));
define('EDR_TB_URL', plugin_dir_url(__FILE__));
define('EDR_TB_VER', '2.4.25');

require_once EDR_TB_DIR . 'includes/garage61.php';
require_once EDR_TB_DIR . 'includes/iracing.php';
require_once EDR_TB_DIR . 'includes/results.php';

/* ----------------------------------------------------------------
 * Settings (one shared credential set, admin-only, server-side)
 * ---------------------------------------------------------------- */
function edr_tb_settings() {
    return wp_parse_args(get_option('edr_tb_settings', array()), array(
        'g61_token'   => '',
        'team_slug'   => 'edr-endurotech',
        'edit_pass'   => '',
        'iracing_url' => '',
        'iracing_key' => '',
        'discord_webhook' => '',
    ));
}

/* Bounds for the public availability route. It is unauthenticated by design, so cap how far
   a single option row can grow: the squad is ~30 drivers and the calendar ~50 events a year,
   so these sit far above legitimate use and only stop a script inflating the option forever. */
define('EDR_TB_MAX_EVENTS',     150);
define('EDR_TB_MAX_DRIVERS',    300);
/* Wrong-password allowance per IP before the password check refuses outright, for 10 minutes. */
define('EDR_TB_MAX_PASS_FAILS', 10);

function edr_tb_pass_bucket() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    return 'edr_tb_authfail_' . md5($ip);
}

function edr_tb_pass_throttled() {
    return ((int) get_transient(edr_tb_pass_bucket())) >= EDR_TB_MAX_PASS_FAILS;
}

/**
 * Check the builder admin password, counting failures per IP.
 *
 * A wrong password used to cost sleep(1). That damped guessing but held a PHP worker for the
 * full second on a public route, so a handful of concurrent wrong passwords could exhaust the
 * pool on shared hosting. Count failures in a transient and refuse immediately once tripped
 * instead. Only requests that actually present a password count, so ordinary credential-less
 * reads never fill the bucket. Behind a proxy or CDN every visitor can share one REMOTE_ADDR,
 * hence the generous allowance.
 */
function edr_tb_pass_ok($pass) {
    $s = edr_tb_settings();
    if ($s['edit_pass'] === '' || $pass === '' || edr_tb_pass_throttled()) return false;
    $bucket = edr_tb_pass_bucket();
    if (hash_equals($s['edit_pass'], $pass)) { delete_transient($bucket); return true; }
    set_transient($bucket, ((int) get_transient($bucket)) + 1, 10 * MINUTE_IN_SECONDS);
    return false;
}

/**
 * Event keys are built by evKey() in the builder as "<event name>|<YYYY-MM-DD>". Anything else
 * is not a shape the UI can produce, so reject it rather than store it.
 */
function edr_tb_valid_ev($ev) {
    return (bool) preg_match('/^.{1,100}\|\d{4}-\d{2}-\d{2}$/u', $ev);
}

/**
 * Availability revision. The plan already had one (for its 409 concurrency check); availability
 * needed its own so a driver submitting blocks shows up on everyone else's screen the same way
 * a plan edit does, without anyone re-fetching the whole store to find out.
 */
function edr_tb_bump_avail_rev() {
    $rev = intval(get_option('edr_tb_avail_rev', 0)) + 1;
    update_option('edr_tb_avail_rev', $rev, false);
    return $rev;
}

/* How stale the Garage 61 pace cache may get before the heartbeat schedules a refresh. Pace
   only moves when people practise, and a heavy pull can get the site's IP rate-limited, so
   this is deliberately slow — hourly only while an event is close, daily the rest of the time. */
define('EDR_TB_PACE_NEAR_SECS', HOUR_IN_SECONDS);
define('EDR_TB_PACE_FAR_SECS',  DAY_IN_SECONDS);
define('EDR_TB_PACE_NEAR_DAYS', 10);

/**
 * Editing is allowed for logged-in users, or for anyone presenting the admin
 * password (plugin Settings) in the X-EDR-Pass header — the team's planner
 * does not have a WordPress account.
 */
function edr_tb_req_can_edit(WP_REST_Request $req) {
    if (is_user_logged_in()) return true;
    return edr_tb_pass_ok((string) $req->get_header('x-edr-pass'));
}

add_action('admin_menu', function () {
    add_options_page('EDR Team Builder', 'EDR Team Builder', 'manage_options', 'edr-tb', 'edr_tb_settings_page');
});

add_action('admin_init', function () {
    register_setting('edr_tb', 'edr_tb_settings', function ($in) {
        return array(
            'g61_token'   => sanitize_text_field($in['g61_token'] ?? ''),
            'team_slug'   => sanitize_text_field($in['team_slug'] ?? 'edr-endurotech'),
            'edit_pass'   => sanitize_text_field($in['edit_pass'] ?? ''),
            'iracing_url' => esc_url_raw($in['iracing_url'] ?? ''),
            'iracing_key' => sanitize_text_field($in['iracing_key'] ?? ''),
            'discord_webhook' => esc_url_raw($in['discord_webhook'] ?? ''),
        );
    });
});

function edr_tb_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = edr_tb_settings();
    ?>
    <div class="wrap">
      <h1>EDR Team Builder</h1>
      <p>Credentials are stored on the server and used only to call Garage 61 and the iRacing proxy. They are never sent to visitors' browsers.</p>
      <form method="post" action="options.php">
        <?php settings_fields('edr_tb'); ?>
        <table class="form-table">
          <tr><th scope="row">Garage 61 API token</th>
            <td><input type="text" name="edr_tb_settings[g61_token]" value="<?php echo esc_attr($s['g61_token']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Garage 61 &rarr; My applications &rarr; API key (needs <code>driving_data</code>).</p></td></tr>
          <tr><th scope="row">Garage 61 team slug</th>
            <td><input type="text" name="edr_tb_settings[team_slug]" value="<?php echo esc_attr($s['team_slug']); ?>" class="regular-text"></td></tr>
          <tr><th scope="row">Builder admin password</th>
            <td><input type="text" name="edr_tb_settings[edit_pass]" value="<?php echo esc_attr($s['edit_pass']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Whoever knows this password can unlock admin mode in the builder (edit Drivers / Teams / Stints and run imports) without a WordPress account. Leave empty to require WordPress login.</p></td></tr>
          <tr><th scope="row">iRacing proxy URL</th>
            <td><input type="text" name="edr_tb_settings[iracing_url]" value="<?php echo esc_attr($s['iracing_url']); ?>" class="regular-text" autocomplete="off" placeholder="https://iracing-bot.example.dev">
            <p class="description">Base URL of the iRacing Data API proxy. Used server-side only to pull official session start times and race lengths. Optional &mdash; leave blank to keep hand-typed session times.</p></td></tr>
          <tr><th scope="row">iRacing proxy key</th>
            <td><input type="text" name="edr_tb_settings[iracing_key]" value="<?php echo esc_attr($s['iracing_key']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Bearer key for the proxy. If the builder later reports the iRacing session expired, the proxy owner needs to re-authenticate the bot.</p></td></tr>
          <tr><th scope="row">Discord webhook (weekly update)</th>
            <td><input type="text" name="edr_tb_settings[discord_webhook]" value="<?php echo esc_attr($s['discord_webhook']); ?>" class="regular-text" autocomplete="off" placeholder="https://discord.com/api/webhooks/...">
            <p class="description">Where the weekly write-up gets posted. Use a <strong>private drafting channel</strong> &mdash; what is posted is a draft to edit, not a finished announcement. Leave blank to disable posting.</p></td></tr>
        </table>
        <?php submit_button(); ?>
      </form>
      <p>Then add the shortcode <code>[edr_team_builder]</code> to a page (preferably a private / members-only page).</p>
    </div>
    <?php
}

/* ----------------------------------------------------------------
 * REST API  (/wp-json/edr/v1/...)  — capability gated
 * ---------------------------------------------------------------- */
add_action('rest_api_init', function () {
    // editing (imports, plan writes) = logged-in user OR the builder admin password
    register_rest_route('edr/v1', '/tracks', array(
        'methods' => 'GET', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_tracks',
    ));
    register_rest_route('edr/v1', '/import', array(
        'methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_import',
    ));
    // shared plan: anyone who can reach the page can READ it; writing needs edit rights.
    // Keep the builder page itself private/password-protected — that is the only thing
    // hiding the plan from the web.
    register_rest_route('edr/v1', '/plan', array(
        array('methods' => 'GET',  'permission_callback' => '__return_true',        'callback' => 'edr_tb_rest_plan_get'),
        array('methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit',  'callback' => 'edr_tb_rest_plan_set'),
    ));
    // admin-password check for the builder's role unlock
    register_rest_route('edr/v1', '/auth', array(
        'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'edr_tb_rest_auth',
    ));
    // per-driver availability: drivers on the (password-protected) page submit their own
    // 4h blocks without any account, so both routes are public by design.
    register_rest_route('edr/v1', '/avail', array(
        array('methods' => 'GET',  'permission_callback' => '__return_true', 'callback' => 'edr_tb_rest_avail_get'),
        array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'edr_tb_rest_avail_set'),
    ));
    // tidying up past events is an admin job, not a public one
    register_rest_route('edr/v1', '/avail/prune', array(
        'methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_avail_prune',
    ));
    // last week's results recap for the weekly write-up (admin content, and the refresh is
    // an expensive proxy sweep, so both sides are edit-gated)
    register_rest_route('edr/v1', '/recap', array(
        'methods' => 'GET', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_recap',
    ));
    // cheap weekly ratings snapshot + diff — GET reads the movement, POST takes a new snapshot
    register_rest_route('edr/v1', '/ratings', array(
        array('methods' => 'GET',  'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_ratings'),
        array('methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_ratings'),
    ));
    register_rest_route('edr/v1', '/recap/refresh', array(
        'methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_recap_refresh',
    ));
    // the weekly write-up: the browser renders it, the server keeps the latest copy so a
    // scheduled job can post it without needing a browser
    register_rest_route('edr/v1', '/weekly/draft', array(
        array('methods' => 'GET',  'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_draft_get'),
        array('methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_draft_set'),
    ));
    register_rest_route('edr/v1', '/weekly/post', array(
        'methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_draft_post',
    ));
    // heartbeat: a few bytes, polled every ~20s by every open tab. Public, like /plan and /avail.
    register_rest_route('edr/v1', '/rev', array(
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'edr_tb_rest_rev',
    ));
    // full Garage 61 membership (every EDR team) for the availability roster —
    // names only, cached; public so drivers can find themselves without an account
    register_rest_route('edr/v1', '/roster', array(
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'edr_tb_rest_roster',
    ));
    // official iRacing session start times + race lengths (via the proxy) — admin only,
    // cached; the builder matches a calendar event to a season and applies its real times
    register_rest_route('edr/v1', '/iracing', array(
        'methods' => 'GET', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_iracing',
    ));
});

function edr_tb_rest_iracing(WP_REST_Request $req) {
    $s = edr_tb_settings();
    if (empty($s['iracing_url']) || empty($s['iracing_key'])) {
        return rest_ensure_response(array('ok' => false, 'reason' => 'not_configured', 'seasons' => array()));
    }
    $fresh = $req->get_param('fresh');
    if (!$fresh) {
        // cache is keyed to the plugin version: a stale pre-upgrade payload (e.g. the old
        // one-week-per-season walker's list) must not survive a plugin update for 12h
        $cache = get_transient('edr_tb_iracing');
        if ($cache && is_array($cache) && (($cache['ver'] ?? '') === EDR_TB_VER)) {
            return rest_ensure_response($cache['payload']);
        }
    }
    // the weekly write-up wants next week's rounds, so pull a fortnight and let the client slice
    $from = strtotime('-1 day');
    $to   = strtotime('+21 days');
    $both = edr_ir_all($s['iracing_url'], $s['iracing_key'], $from, $to);
    if (is_wp_error($both)) {
        // don't cache failures (e.g. an expired proxy session) — surface and retry next time
        return rest_ensure_response(array('ok' => false, 'reason' => 'error', 'message' => $both->get_error_message(), 'seasons' => array(), 'weeks' => array()));
    }
    $payload = array('ok' => true, 'seasons' => $both['seasons'], 'weeks' => $both['weeks']);
    set_transient('edr_tb_iracing', array('ver' => EDR_TB_VER, 'payload' => $payload), 12 * HOUR_IN_SECONDS);
    return rest_ensure_response($payload);
}

function edr_tb_rest_roster(WP_REST_Request $req) {
    $s = edr_tb_settings();
    if (!$s['g61_token']) return rest_ensure_response(array());

    // live ID lookup: a brand-new member who joined Garage 61 five minutes ago must be able to
    // self-identify immediately — on a cache miss we re-pull the membership once (guarded to
    // one refresh per 10 minutes so mistyped IDs can't hammer Garage 61)
    $lookup = preg_replace('/\D/', '', (string) $req->get_param('lookup'));
    if ($lookup !== '') {
        $cache = get_transient('edr_tb_roster3');
        if (is_array($cache) && isset($cache['ids'][$lookup])) {
            return rest_ensure_response(array('name' => $cache['ids'][$lookup]));
        }
        if (!get_transient('edr_tb_roster_recheck')) {
            set_transient('edr_tb_roster_recheck', 1, 10 * MINUTE_IN_SECONDS);
            $members = edr_g61_all_members($s['g61_token']);
            if (!is_wp_error($members)) {
                set_transient('edr_tb_roster3', $members, 6 * HOUR_IN_SECONDS);
                $cache = $members;
            }
        }
        return rest_ensure_response(array('name' => (is_array($cache) && isset($cache['ids'][$lookup])) ? $cache['ids'][$lookup] : null));
    }

    $fresh = $req->get_param('fresh') && edr_tb_req_can_edit($req);
    if (!$fresh) {
        $cache = get_transient('edr_tb_roster3');   // key bumped in 2.4.14: names are now de-duplicated, so a cached roster must not survive the upgrade
        if ($cache) return rest_ensure_response($cache);
    }
    $members = edr_g61_all_members($s['g61_token']);
    if (is_wp_error($members)) return $members;
    set_transient('edr_tb_roster3', $members, 6 * HOUR_IN_SECONDS);
    return rest_ensure_response($members);
}

function edr_tb_rest_auth(WP_REST_Request $req) {
    if (edr_tb_pass_throttled()) {
        return new WP_Error('too_many', 'Too many wrong passwords from this network. Wait 10 minutes and try again.', array('status' => 429));
    }
    return rest_ensure_response(array('ok' => edr_tb_pass_ok((string) $req->get_param('pass'))));
}

/**
 * Heartbeat. Every open tab polls this every ~20s, so it must stay tiny and cheap: three
 * option reads and no API calls. Clients compare the numbers against what they hold and only
 * pull the full /plan or /avail payload when one has actually moved.
 *
 * It doubles as the scheduler tick. WP-Cron only runs when somebody hits the site, and the
 * builder page is private and low-traffic, so hanging the pace refresh off a real cron would
 * mean either configuring server cron or having it fire erratically. Using the heartbeat means
 * the pull is considered exactly when someone is looking at the tool, which is when it matters.
 */
function edr_tb_rest_draft_get() {
    $d = (array) get_option('edr_tb_weekly_draft', array());
    return rest_ensure_response(array(
        'text' => isset($d['text']) ? (string) $d['text'] : '',
        'at'   => isset($d['at']) ? intval($d['at']) : 0,
    ));
}

function edr_tb_rest_draft_set(WP_REST_Request $req) {
    $text = (string) $req->get_param('text');
    if (trim($text) === '') return new WP_Error('empty', 'Nothing to store.', array('status' => 400));
    $text = substr($text, 0, 20000);
    update_option('edr_tb_weekly_draft', array('text' => $text, 'at' => time()), false);
    return rest_ensure_response(array('ok' => true, 'at' => time()));
}

/** Discord hard-limits a message to 2000 characters; split on blank lines so sections stay whole. */
function edr_tb_discord_chunks($text, $limit = 1900) {
    $paras = preg_split("/
{2,}/", str_replace("

", "
", $text));
    $out = array(); $cur = '';
    foreach ($paras as $p) {
        $p = rtrim($p);
        if ($p === '') continue;
        if (strlen($p) > $limit) {                       // one huge block: fall back to line splitting
            foreach (explode("
", $p) as $line) {
                /* a single line can still exceed the limit if it has no newlines at all, and
                   Discord 400s the whole post rather than truncating — so chop it hard */
                $pieces = (strlen($line) > $limit) ? str_split($line, $limit) : array($line);
                foreach ($pieces as $piece) {
                    if (strlen($cur) + strlen($piece) + 1 > $limit) { $out[] = $cur; $cur = ''; }
                    $cur .= ($cur === '' ? '' : "
") . $piece;
                }
            }
            continue;
        }
        if (strlen($cur) + strlen($p) + 2 > $limit) { $out[] = $cur; $cur = ''; }
        $cur .= ($cur === '' ? '' : "

") . $p;
    }
    if (trim($cur) !== '') $out[] = $cur;
    return $out;
}

/**
 * Post the stored draft to Discord. Refuses a stale one outright: a scheduled job firing against
 * a three-week-old draft would announce the wrong week to the whole team, which is worse than
 * posting nothing.
 */
function edr_tb_rest_draft_post(WP_REST_Request $req) {
    $s = edr_tb_settings();
    if (empty($s['discord_webhook'])) {
        return new WP_Error('no_webhook', 'Set the Discord webhook in plugin Settings first.', array('status' => 400));
    }
    $d = (array) get_option('edr_tb_weekly_draft', array());
    $text = isset($d['text']) ? trim((string) $d['text']) : '';
    if ($text === '') {
        return new WP_Error('no_draft', 'No weekly draft stored yet — open the Weekly tab and hit Generate.', array('status' => 409));
    }
    $ageDays = (time() - intval($d['at'])) / DAY_IN_SECONDS;
    $maxAge  = $req->get_param('max_age_days');
    $maxAge  = ($maxAge === null) ? 8 : max(1, intval($maxAge));
    if ($ageDays > $maxAge) {
        return new WP_Error('stale_draft', sprintf('The stored draft is %d days old — refusing to post it. Regenerate it on the Weekly tab.', (int) $ageDays), array('status' => 409));
    }

    $chunks = edr_tb_discord_chunks($text);
    $sent = 0;
    foreach ($chunks as $i => $chunk) {
        $res = wp_remote_post($s['discord_webhook'], array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('content' => $chunk, 'allowed_mentions' => array('parse' => array()))),
        ));
        if (is_wp_error($res)) {
            return new WP_Error('discord', 'Discord post failed: ' . $res->get_error_message() . ' (sent ' . $sent . ' of ' . count($chunks) . ')', array('status' => 502));
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('discord', 'Discord returned HTTP ' . $code . ' (sent ' . $sent . ' of ' . count($chunks) . ')', array('status' => 502));
        }
        $sent++;
        if ($i < count($chunks) - 1) sleep(1);   // stay clear of the webhook rate limit
    }
    update_option('edr_tb_weekly_posted', time(), false);
    return rest_ensure_response(array('ok' => true, 'messages' => $sent, 'draft_age_days' => round($ageDays, 1)));
}

/**
 * iRacing race weeks tick over at Tuesday 00:00 UTC — 10:00 Brisbane year-round, 10:00 or 11:00
 * Melbourne depending on daylight saving. Everything the Weekly tab reports is a race week, so
 * the recap window and the ratings snapshot key both hang off this one boundary; otherwise the
 * two halves of the same draft describe weeks that are a day out from each other.
 *
 * Returns the UTC timestamp of the tick that STARTS the next race week — the same instant the
 * client's nextWeekWindow() anchors on. Subtract a week for the race week now finishing.
 * Done arithmetically rather than with strtotime('next tuesday') because the relative formats
 * behave differently on a Tuesday itself, which is exactly the case that has to be right.
 */
function edr_tb_next_week_tick($now = null) {
    $now   = ($now === null) ? time() : (int) $now;
    $today = strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
    $ahead = (2 - (int) gmdate('w', $today) + 7) % 7;      // 2 = Tuesday
    if ($ahead === 0) $ahead = 7;                          // today's tick has already gone by
    return $today + $ahead * DAY_IN_SECONDS;
}

/**
 * Snapshots used to be keyed gmdate('o-\WW'). Fold any of those onto the race week they belong
 * to so the two key styles never sort against each other — ksort() is a string sort and every
 * 'YYYY-MM-DD' key sorts before every 'YYYY-Wnn' one, which would strand the new snapshots at
 * the bottom and diff the two stale ones forever.
 *
 * An ISO week's Tuesday is the right bucket for anything snapshotted Tue–Sun of that week, which
 * is every snapshot the Sunday job ever took. A hand-taken Monday one lands a week late; that is
 * one slightly-off movement figure on legacy data, not a broken diff.
 */
function edr_tb_migrate_snap_keys($snaps) {
    $out = array();
    foreach ((array) $snaps as $k => $v) {
        if (preg_match('/^(\d{4})-W(\d{2})$/', (string) $k, $m)) {
            $d = new DateTime('now', new DateTimeZone('UTC'));
            $d->setISODate((int) $m[1], (int) $m[2], 2);   // 2 = Tuesday of that ISO week
            $d->setTime(0, 0, 0);
            $k = $d->format('Y-m-d');
        }
        $out[$k] = $v;
    }
    ksort($out);
    return $out;
}

/**
 * Weekly ratings snapshot + diff.
 *
 * Two Garage 61 calls, seconds not minutes. Every snapshot is stored under its race week, so the
 * movement for a week is just this week's numbers minus last week's. This is what actually
 * answers "most improved" and "safety rating changes" — the iRacing results sweep is only
 * needed for wins and podiums, and should not be what those awards wait on.
 */
function edr_tb_snapshot_ratings() {
    $s = edr_tb_settings();
    if (empty($s['g61_token'])) return new WP_Error('no_token', 'Garage 61 token not set.', array('status' => 400));
    $now = edr_g61_member_ratings_full($s['g61_token']);
    if (is_wp_error($now)) return $now;
    if (!$now) return new WP_Error('empty', 'Garage 61 returned no member ratings.', array('status' => 502));

    $snaps = edr_tb_migrate_snap_keys(get_option('edr_tb_rating_snaps', array()));
    /* Keyed by the race week's own Tuesday. ISO weeks start on Monday, so a snapshot taken
       Monday and one taken Tuesday sat in different ISO weeks despite being a day apart in the
       same race week — the movement then read as a full week's gain off a single day. The
       Weekly tab has a Take-snapshot button, so off-schedule runs are normal, not hypothetical. */
    $week  = gmdate('Y-m-d', edr_tb_next_week_tick() - 7 * DAY_IN_SECONDS);
    $snaps[$week] = array('at' => time(), 'r' => $now);
    // keep a quarter of history; this option is read on every diff
    if (count($snaps) > 14) { ksort($snaps); $snaps = array_slice($snaps, -14, null, true); }
    update_option('edr_tb_rating_snaps', $snaps, false);
    return array('week' => $week, 'drivers' => count($now));
}

/** Movement between the two most recent snapshots. */
function edr_tb_rating_movement() {
    // normalise on read too, so a diff taken before the next snapshot never sees mixed key styles
    $snaps = edr_tb_migrate_snap_keys(get_option('edr_tb_rating_snaps', array()));
    if (count($snaps) < 2) return array('ready' => false, 'have' => count($snaps), 'movers' => array());
    $keys = array_keys($snaps);
    $prev = $snaps[$keys[count($keys) - 2]];
    $curr = $snaps[$keys[count($keys) - 1]];
    $movers = array();
    foreach ((array) $curr['r'] as $k => $c) {
        if (!isset($prev['r'][$k])) continue;          // joined since the last snapshot
        $p = $prev['r'][$k];
        $dIr = intval($c['ir']) - intval($p['ir']);
        $dSr = round(floatval($c['sr']) - floatval($p['sr']), 2);
        if ($dIr === 0 && abs($dSr) < 0.01) continue;  // nothing moved
        $movers[] = array(
            'name' => $c['name'], 'irDelta' => $dIr, 'irEnd' => intval($c['ir']),
            'srDelta' => $dSr, 'srStart' => floatval($p['sr']), 'srEnd' => floatval($c['sr']),
            'srLabel' => (string) $c['srLabel'],
        );
    }
    usort($movers, function ($a, $b) { return $b['irDelta'] - $a['irDelta']; });
    return array('ready' => true, 'from' => $keys[count($keys) - 2], 'to' => $keys[count($keys) - 1], 'movers' => $movers);
}

function edr_tb_rest_ratings(WP_REST_Request $req) {
    if ($req->get_method() === 'POST') {
        $r = edr_tb_snapshot_ratings();
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('ok' => true, 'snapshot' => $r, 'movement' => edr_tb_rating_movement()));
    }
    return rest_ensure_response(edr_tb_rating_movement());
}

function edr_tb_rest_recap() {
    $r = get_option('edr_tb_recap', null);
    $prog = (array) get_option('edr_tb_recap_progress', array());
    $running = (bool) get_transient('edr_tb_recap_running');
    /* A job whose heartbeat stopped more than three minutes ago is dead — the host killed the
       loopback request. Say so instead of leaving the tab polling a spinner until the flag
       expires twenty minutes later. */
    $stalled = $running && !empty($prog['at']) && (time() - intval($prog['at']) > 180);
    if ($stalled) { delete_transient('edr_tb_recap_running'); $running = false; }
    return rest_ensure_response(array(
        'recap'    => $r ?: null,
        'running'  => $running,
        'progress' => $prog ?: null,
        'stalled'  => $stalled,
        'error'    => $stalled ? 'The results pull stopped partway through — the host cut it off. Try again, or rely on the ratings snapshot.' : (string) get_option('edr_tb_recap_err', ''),
    ));
}

/**
 * Queue the sweep. Returns immediately — a search per driver plus a fetch per subsession takes
 * minutes against a rate-limited proxy, so it cannot happen inside the request.
 */
function edr_tb_rest_recap_refresh(WP_REST_Request $req) {
    $s = edr_tb_settings();
    if (empty($s['iracing_url']) || empty($s['iracing_key'])) {
        return new WP_Error('not_configured', 'Set the iRacing proxy in plugin Settings first.', array('status' => 400));
    }
    if (get_transient('edr_tb_recap_running')) {
        return rest_ensure_response(array('ok' => true, 'running' => true, 'queued' => false));
    }
    /* The write-up previews the next race week, so the recap is the race week immediately before
       it — the one just finishing — not the one before that. It hangs off the same Tuesday tick as
       the client's window (edr_tb_next_week_tick()); anchoring this on Monday while the preview
       ran Tuesday-to-Monday left the two halves of one draft a day out of step. Generated
       mid-week it is results-so-far, which is still the right thing to look back on. */
    $tick = edr_tb_next_week_tick();
    /* The client sends the window explicitly so the recap follows the tab's next-week/this-week
       toggle. Anything not in the exact shape the proxy expects falls back to the default rather
       than being passed through: a malformed range comes back as an empty sweep, which reads as
       "nobody raced last week" rather than as the bug it is. */
    $shape = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $v); };
    $to   = $shape($req->get_param('to'))   ? $req->get_param('to')   : gmdate('Y-m-d\TH:i:s\Z', $tick);
    $from = $shape($req->get_param('from')) ? $req->get_param('from') : gmdate('Y-m-d\TH:i:s\Z', $tick - 7 * DAY_IN_SECONDS);
    set_transient('edr_tb_recap_running', 1, 20 * MINUTE_IN_SECONDS);
    wp_schedule_single_event(time(), 'edr_tb_recap_job', array((string) $from, (string) $to));
    return rest_ensure_response(array('ok' => true, 'running' => true, 'queued' => true, 'from' => $from, 'to' => $to));
}

/**
 * One slice of the sweep per invocation, re-queueing itself until done.
 *
 * Called two ways, and the difference matters: with a window (from the refresh route) it starts or
 * restarts a sweep, and with no arguments (its own re-queue) it resumes the stored one. Resuming
 * rather than restarting is why a host that kills the request every 30 seconds still gets there —
 * each slice keeps whatever the last one banked.
 */
add_action('edr_tb_recap_job', 'edr_tb_recap_run', 10, 2);
function edr_tb_recap_run($from = '', $to = '') {
    $s = edr_tb_settings();
    $fail = function ($msg) {
        update_option('edr_tb_recap_err', $msg, false);
        edr_tb_recap_state_clear();
        delete_transient('edr_tb_recap_running');
    };
    if (empty($s['iracing_url']) || empty($s['iracing_key']) || empty($s['g61_token'])) {
        $fail('Garage 61 token and iRacing proxy both need to be set.');
        return;
    }
    @set_time_limit(0);

    $st = edr_tb_recap_state();
    $fresh = !$st || ($from !== '' && ((string) ($st['from'] ?? '') !== (string) $from || (string) ($st['to'] ?? '') !== (string) $to));
    if ($fresh) {
        $members = edr_g61_all_members($s['g61_token']);
        if (is_wp_error($members) || empty($members['ids'])) {
            $fail('Could not read the Garage 61 membership (needed for iRacing customer IDs).');
            return;
        }
        $st = edr_tb_recap_state_init($members['ids'], $from, $to);
        edr_tb_recap_state_save($st);
    }

    $r = edr_tb_recap_step($s['iracing_url'], $s['iracing_key'], $st);
    if (is_wp_error($r)) { $fail($r->get_error_message()); return; }

    if ($r === 'working') {
        /* Hand the rest to a fresh request. spawn_cron() kicks a loopback now rather than waiting
           for the next visitor — and the client polling GET /recap every 5s is itself traffic, so
           the chain keeps advancing even where spawn_cron's own 60s lock declines to fire. */
        set_transient('edr_tb_recap_running', 1, 20 * MINUTE_IN_SECONDS);
        wp_schedule_single_event(time() + 1, 'edr_tb_recap_job');
        if (function_exists('spawn_cron')) spawn_cron();
        return;
    }

    update_option('edr_tb_recap', edr_tb_recap_shape($st), false);
    update_option('edr_tb_recap_err', '', false);
    edr_tb_recap_state_clear();
    delete_transient('edr_tb_recap_running');
}

function edr_tb_rest_rev(WP_REST_Request $req) {
    $pace = (array) get_option('edr_tb_pace', array());
    edr_tb_maybe_schedule_pace($req->get_param('ev'));
    return rest_ensure_response(array(
        'plan'    => intval(get_option('edr_tb_plan_rev', 0)),
        'avail'   => intval(get_option('edr_tb_avail_rev', 0)),
        'paceAt'  => isset($pace['at']) ? intval($pace['at']) : 0,
        'paceErr' => isset($pace['err']) ? (string) $pace['err'] : '',
        'recapAt' => intval((array) get_option('edr_tb_recap', array()) ? (get_option('edr_tb_recap')['at'] ?? 0) : 0),
        'recapRunning' => (bool) get_transient('edr_tb_recap_running'),
    ));
}

/**
 * Queue a background pace pull if the cache has gone stale. wp_schedule_single_event plus WP's
 * own loopback spawner means the visitor's request returns immediately and the Garage 61 call
 * happens in a separate process — a synchronous pull here would block a page load for seconds.
 *
 * The transient is a debounce: without it every heartbeat from every open tab would queue
 * another job, and Garage 61 rate-limits hard enough to firewall the site's IP.
 */
function edr_tb_maybe_schedule_pace($ev = '') {
    $s = edr_tb_settings();
    if (empty($s['g61_token'])) return;
    $tracks = (array) get_option('edr_tb_pace_tracks', array());
    if (!$tracks) return;                       // nothing imported yet — nothing to keep warm
    if (get_transient('edr_tb_pace_queued')) return;

    $pace = (array) get_option('edr_tb_pace', array());
    $age  = time() - (isset($pace['at']) ? intval($pace['at']) : 0);

    // hourly while the event is close, daily otherwise — the date lives in the ev key
    $window = EDR_TB_PACE_FAR_SECS;
    if (is_string($ev) && edr_tb_valid_ev($ev)) {
        $parts = explode('|', $ev);
        $when  = strtotime((string) end($parts));
        if ($when !== false && abs($when - time()) <= EDR_TB_PACE_NEAR_DAYS * DAY_IN_SECONDS) {
            $window = EDR_TB_PACE_NEAR_SECS;
        }
    }
    if ($age < $window) return;

    set_transient('edr_tb_pace_queued', 1, 10 * MINUTE_IN_SECONDS);
    wp_schedule_single_event(time(), 'edr_tb_pace_refresh', array($tracks));
}

/* The background job itself. Failures are recorded rather than thrown away, so the Setup tab
   can say why the pace is old instead of just showing a stale timestamp. */
add_action('edr_tb_pace_refresh', 'edr_tb_do_pace_refresh', 10, 1);
function edr_tb_do_pace_refresh($tracks) {
    $s = edr_tb_settings();
    if (empty($s['g61_token'])) return;
    $tracks = array_values(array_map('intval', (array) $tracks));
    if (!$tracks) return;
    $roster = edr_g61_roster($s['g61_token'], $tracks, ($s['team_slug'] ?: 'edr-endurotech'));
    if (is_wp_error($roster)) {
        $prev = (array) get_option('edr_tb_pace', array());
        $prev['err'] = $roster->get_error_message();
        update_option('edr_tb_pace', $prev, false);
        return;
    }
    update_option('edr_tb_pace', array(
        'at'     => time(),
        'tracks' => $tracks,
        'roster' => $roster,
        'err'    => '',
    ), false);
    delete_transient('edr_tb_pace_queued');
}

function edr_tb_rest_avail_get() {
    $all   = get_option('edr_tb_avail', array());
    $prefs = get_option('edr_tb_prefs', array());
    $seen  = get_option('edr_tb_avail_seen', array());
    return rest_ensure_response(array(
        'store'  => empty($all) ? new stdClass() : $all,
        'locked' => array(),   // advisory model (2.4.2): no hard per-device locks — see below
        'prefs'  => empty($prefs) ? new stdClass() : $prefs,
        'seen'   => empty($seen) ? new stdClass() : $seen,   // {ev: {name: {at, n}}} — last submission
    ));
}

/**
 * Availability writes are ADVISORY-ONLY as of 2.4.2 (user decision): any device may edit any
 * driver's blocks — the team runs on trust. The old first-device-token hard lock kept locking
 * drivers out of their own availability when they switched phone->PC (no accounts, so the
 * server can't tell "same person, new device" from "someone else"). The legacy release/token
 * params are accepted and ignored so older clients keep working; edr_tb_avail_owners is unused.
 */
function edr_tb_rest_avail_set(WP_REST_Request $req) {
    global $wpdb;

    $name = substr(sanitize_text_field((string) $req->get_param('name')), 0, 80);
    if ($name === '') return new WP_Error('bad_req', 'Need a driver name.', array('status' => 400));

    if ($req->get_param('release')) {
        return rest_ensure_response(array('ok' => true, 'released' => $name));   // no locks to release
    }

    $ev = substr(sanitize_text_field((string) $req->get_param('ev')), 0, 120);
    if ($ev === '') return new WP_Error('bad_req', 'Need ev.', array('status' => 400));
    if (!edr_tb_valid_ev($ev)) return new WP_Error('bad_ev', 'Unrecognised event.', array('status' => 400));

    $slots = array_slice(array_values(array_unique(array_filter(
        array_map('intval', (array) $req->get_param('slots')),
        function ($v) { return $v >= 0 && $v < 1000; }
    ))), 0, 500);

    // Availability is a read-modify-write of one option, exactly like the plan, so it needs the
    // same guard: without it two drivers submitting in the same instant both read the store,
    // both add themselves, and the second write drops the first driver's blocks — silently,
    // after they were told "Submitted". Bursts are the norm here (everyone fills it in after
    // the same Discord ping), so take the named lock around the whole read-modify-write.
    $locked = $wpdb->get_var("SELECT GET_LOCK('edr_tb_avail_write', 5)");
    if ((int) $locked !== 1) {
        return new WP_Error('busy', 'Someone else is saving right now — hit Submit again in a moment.', array('status' => 503));
    }
    wp_cache_delete('edr_tb_avail', 'options');   // re-read fresh inside the lock (object-cache safety)
    wp_cache_delete('edr_tb_prefs', 'options');

    $all = (array) get_option('edr_tb_avail', array());
    // the route is public, so a new key may only be created while the store is under its cap;
    // updating an entry that already exists is always allowed
    if (!isset($all[$ev]) && count($all) >= EDR_TB_MAX_EVENTS) {
        $wpdb->query("SELECT RELEASE_LOCK('edr_tb_avail_write')");
        return new WP_Error('ev_full', 'Too many events stored — an admin needs to clear out old ones.', array('status' => 409));
    }
    if (!isset($all[$ev]) || !is_array($all[$ev])) $all[$ev] = array();
    if (!isset($all[$ev][$name]) && count($all[$ev]) >= EDR_TB_MAX_DRIVERS) {
        $wpdb->query("SELECT RELEASE_LOCK('edr_tb_avail_write')");
        return new WP_Error('ev_full', 'Too many drivers on this event — an admin needs to tidy the list.', array('status' => 409));
    }
    $all[$ev][$name] = $slots;
    update_option('edr_tb_avail', $all, false);

    // race preferences (F9): {time in start|finish|any, cond in wet|dry|any}
    $prefsParam = $req->get_param('prefs');
    if (is_array($prefsParam)) {
        $time = in_array(($prefsParam['time'] ?? 'any'), array('start','finish','any'), true) ? $prefsParam['time'] : 'any';
        $cond = in_array(($prefsParam['cond'] ?? 'any'), array('wet','dry','any'), true) ? $prefsParam['cond'] : 'any';
        $cls  = substr(sanitize_text_field((string) ($prefsParam['cls'] ?? '')), 0, 24);   // declared event class (GT3 / Porsche Cup / …)
        $pAll = (array) get_option('edr_tb_prefs', array());
        if (!isset($pAll[$ev]) || !is_array($pAll[$ev])) $pAll[$ev] = array();
        $pAll[$ev][$name] = array('time' => $time, 'cond' => $cond) + ($cls !== '' ? array('cls' => $cls) : array());
        update_option('edr_tb_prefs', $pAll, false);
    }

    // when the driver last submitted, so the tool can tell them rather than making them
    // re-read their own grid to work out whether they are already in
    $stamps = (array) get_option('edr_tb_avail_seen', array());
    if (!isset($stamps[$ev]) || !is_array($stamps[$ev])) $stamps[$ev] = array();
    $stamps[$ev][$name] = array('at' => current_time('mysql'), 'n' => count($slots));
    update_option('edr_tb_avail_seen', $stamps, false);

    edr_tb_bump_avail_rev();   // so every other open tab notices on its next heartbeat
    $wpdb->query("SELECT RELEASE_LOCK('edr_tb_avail_write')");
    return rest_ensure_response(array('ok' => true, 'locked' => array(), 'seen' => $stamps[$ev][$name]));
}

/**
 * Drop availability, preferences and submission stamps for events that finished more than
 * `days` ago. This is the only way the store caps above are ever reached in normal use, so
 * admins need a way to tidy up without touching the database.
 */
function edr_tb_rest_avail_prune(WP_REST_Request $req) {
    global $wpdb;
    $days = $req->get_param('days');
    $days = ($days === null) ? 90 : max(1, min(3650, intval($days)));
    $cutoff = strtotime('-' . $days . ' days');

    $locked = $wpdb->get_var("SELECT GET_LOCK('edr_tb_avail_write', 5)");
    if ((int) $locked !== 1) {
        return new WP_Error('busy', 'Another save is in progress — try again.', array('status' => 503));
    }
    foreach (array('edr_tb_avail', 'edr_tb_prefs', 'edr_tb_avail_seen') as $opt) {
        wp_cache_delete($opt, 'options');
    }

    $removed = array();
    $avail = (array) get_option('edr_tb_avail', array());
    foreach (array_keys($avail) as $ev) {
        // the key is "<event name>|<YYYY-MM-DD>" — the date is what decides if it is old
        $parts = explode('|', (string) $ev);
        $when = strtotime((string) end($parts));
        if ($when !== false && $when < $cutoff) $removed[] = $ev;
    }
    if ($removed) {
        foreach (array('edr_tb_avail', 'edr_tb_prefs', 'edr_tb_avail_seen') as $opt) {
            $data = (array) get_option($opt, array());
            foreach ($removed as $ev) unset($data[$ev]);
            update_option($opt, $data, false);
        }
        edr_tb_bump_avail_rev();
    }

    $wpdb->query("SELECT RELEASE_LOCK('edr_tb_avail_write')");
    return rest_ensure_response(array(
        'ok'        => true,
        'removed'   => array_values($removed),
        'remaining' => count($avail) - count($removed),
    ));
}

function edr_tb_rest_plan_get() {
    return rest_ensure_response(array(
        'plan'     => get_option('edr_tb_plan', null),
        'rev'      => intval(get_option('edr_tb_plan_rev', 0)),
        'can_edit' => is_user_logged_in(),
        'updated'  => get_option('edr_tb_plan_updated', ''),
    ));
}
/**
 * Optimistic concurrency: clients send the revision they loaded (baseRev); a save based on
 * a stale revision is rejected with 409 instead of silently clobbering another device's work
 * (the "stints reshuffled over the weekend" bug — two admin browsers, last write wins).
 * Clients that don't send baseRev (pre-2.4.2) keep the old last-write-wins behaviour.
 */
function edr_tb_rest_plan_set(WP_REST_Request $req) {
    global $wpdb;
    // named lock makes the check-then-write atomic: two overlapping saves with the same
    // baseRev can no longer both pass the guard (the residual ms-window clobber)
    $locked = $wpdb->get_var("SELECT GET_LOCK('edr_tb_plan_write', 3)");
    if ((int) $locked !== 1) {
        return new WP_Error('busy', 'Another save is in progress — try again.', array('status' => 503));
    }
    wp_cache_delete('edr_tb_plan_rev', 'options');   // re-read fresh inside the lock (object-cache safety)
    $rev  = intval(get_option('edr_tb_plan_rev', 0));
    $base = $req->get_param('baseRev');
    if ($base !== null && intval($base) !== $rev) {
        $wpdb->query("SELECT RELEASE_LOCK('edr_tb_plan_write')");
        return new WP_Error('stale_plan', 'The plan was updated from another device. Latest version reloaded — re-apply your change.', array('status' => 409, 'rev' => $rev));
    }
    update_option('edr_tb_plan', $req->get_param('plan'), false);
    update_option('edr_tb_plan_rev', $rev + 1, false);
    update_option('edr_tb_plan_updated', current_time('mysql'), false);
    $wpdb->query("SELECT RELEASE_LOCK('edr_tb_plan_write')");
    return rest_ensure_response(array('ok' => true, 'rev' => $rev + 1));
}

function edr_tb_rest_tracks() {
    $s = edr_tb_settings();
    if (!$s['g61_token']) return new WP_Error('no_token', 'Garage 61 token not set in plugin settings.', array('status' => 400));
    $cache = get_transient('edr_tb_tracks');
    if ($cache) return rest_ensure_response($cache);
    $tracks = edr_g61_tracks($s['g61_token']);
    if (is_wp_error($tracks)) return $tracks;
    set_transient('edr_tb_tracks', $tracks, DAY_IN_SECONDS);
    return rest_ensure_response($tracks);
}


function edr_tb_rest_import(WP_REST_Request $req) {
    $s = edr_tb_settings();
    // only the Garage 61 token is required — iRacePlan is optional legacy (availability
    // now comes from the builder's own Availability tab)
    if (!$s['g61_token']) return new WP_Error('no_creds', 'Set the Garage 61 token in plugin settings.', array('status' => 400));
    $trackIds = array_map('intval', (array) $req->get_param('trackIds'));
    if (!$trackIds) return new WP_Error('no_track', 'Pick a track first.', array('status' => 400));
    // an empty team slug would silently drop the teams= filter and return only the
    // key owner's laps ("only Sam Millar") — always fall back to the team default
    $teamSlug = sanitize_text_field($req->get_param('teamSlug') ?: ($s['team_slug'] ?: 'edr-endurotech'));

    // remember what to keep warm in the background from now on
    update_option('edr_tb_pace_tracks', array_values($trackIds), false);

    // Serve the warm cache when it covers these tracks and is recent. Imports are the one
    // moment an admin is waiting on Garage 61, and it is also the easiest moment to trip the
    // rate limiter; the scheduled refresh exists so this is usually instant. `fresh=1` forces.
    $pace = (array) get_option('edr_tb_pace', array());
    $sameTracks = isset($pace['tracks']) && array_values((array) $pace['tracks']) == array_values($trackIds);
    $fresh = (bool) $req->get_param('fresh');
    if (!$fresh && $sameTracks && !empty($pace['roster']) && (time() - intval($pace['at'])) < EDR_TB_PACE_NEAR_SECS) {
        return rest_ensure_response(array(
            'roster' => $pace['roster'], 'needs_availability' => false,
            'cached' => true, 'at' => intval($pace['at']),
        ));
    }

    // pure Garage 61 pace pull; availability is collected in-house (Availability tab)
    $roster = edr_g61_roster($s['g61_token'], $trackIds, $teamSlug);   // [{name, cars}]
    if (is_wp_error($roster)) return $roster;
    update_option('edr_tb_pace', array(
        'at' => time(), 'tracks' => array_values($trackIds), 'roster' => $roster, 'err' => '',
    ), false);
    return rest_ensure_response(array(
        'roster' => $roster, 'needs_availability' => false, 'cached' => false, 'at' => time(),
    ));
}

/* ----------------------------------------------------------------
 * Shortcode  [edr_team_builder]
 * ---------------------------------------------------------------- */
add_shortcode('edr_team_builder', function () {
    wp_enqueue_style('edr-tb', EDR_TB_URL . 'assets/builder.css', array(), EDR_TB_VER);
    wp_enqueue_script('edr-tb', EDR_TB_URL . 'assets/builder.js', array(), EDR_TB_VER, true);
    wp_localize_script('edr-tb', 'EDR_TB', array(
        'root'  => esc_url_raw(rest_url('edr/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'logo'  => EDR_TB_URL . 'assets/edr_logo.png',
        'can_edit' => is_user_logged_in(),
    ));
    return '<div id="edr-tb-app"><noscript>This tool needs JavaScript.</noscript></div>';
});
