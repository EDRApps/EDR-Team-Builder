<?php
/**
 * Plugin Name: EDR Team Builder
 * Description: Endurotech Racing endurance team + stint planner. Pulls Garage 61 pace and official iRacing session times, collects driver availability in-house, and builds Pro/Casual teams and stint rotations. Add the [edr_team_builder] shortcode to a page.
 * Version: 2.6.1
 * Author: Endurotech Racing
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit; // no direct access

define('EDR_TB_DIR', plugin_dir_path(__FILE__));
define('EDR_TB_URL', plugin_dir_url(__FILE__));
define('EDR_TB_VER', '2.6.1');

require_once EDR_TB_DIR . 'includes/garage61.php';
require_once EDR_TB_DIR . 'includes/iracing.php';
require_once EDR_TB_DIR . 'includes/results.php';
require_once EDR_TB_DIR . 'includes/history.php';

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
        'confirm_webhook' => '',
        'discord_bot_token'   => '',
        'discord_guild_id'    => '',
        'discord_category_id' => '',
        'gemini_key'   => '',
        'gemini_model' => 'gemini-3.5-flash',
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
define('EDR_TB_PACE_SETS_MAX', 8);

/* Pace is cached per track set, not as one global slot. The plan only ever planned one event at a
   time, so a single cache keyed to "whatever was imported last" looked fine — but it meant the
   background refresh kept pulling Road Atlanta weeks after everyone had moved on to Spa, and a
   driver viewing the next event saw nothing new however much they practised. Each set is keyed
   by its sorted track ids, so viewers on different events keep separate, independently warm
   caches. Capped so stale events age out instead of growing the option forever. */
function edr_tb_pace_tracks_clean($tracks) {
    if (is_string($tracks)) $tracks = explode(',', $tracks);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $tracks), function ($t) { return $t > 0; })));
    sort($ids);
    return count($ids) > 10 ? array() : $ids;   // Garage 61 rejects more than ~10 tracks per request
}
function edr_tb_pace_key($tracks) { return implode(',', edr_tb_pace_tracks_clean($tracks)); }
function edr_tb_pace_get($tracks) {
    $key = edr_tb_pace_key($tracks);
    if ($key === '') return array();
    $sets = (array) get_option('edr_tb_pace_sets', array());
    if (isset($sets[$key])) return (array) $sets[$key];
    // pre-2.4.39 single-slot cache: still valid if it happens to cover these tracks
    $legacy = (array) get_option('edr_tb_pace', array());
    if (isset($legacy['tracks']) && edr_tb_pace_key($legacy['tracks']) === $key) return $legacy;
    return array();
}
function edr_tb_pace_put($tracks, $data) {
    $key = edr_tb_pace_key($tracks);
    if ($key === '') return;
    $sets = (array) get_option('edr_tb_pace_sets', array());
    $sets[$key] = $data;
    if (count($sets) > EDR_TB_PACE_SETS_MAX) {
        uasort($sets, function ($a, $b) { return intval($b['at'] ?? 0) - intval($a['at'] ?? 0); });
        $sets = array_slice($sets, 0, EDR_TB_PACE_SETS_MAX, true);
    }
    update_option('edr_tb_pace_sets', $sets, false);
}

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
            'confirm_webhook' => esc_url_raw($in['confirm_webhook'] ?? ''),
            'discord_bot_token'   => sanitize_text_field($in['discord_bot_token'] ?? ''),
            'discord_guild_id'    => preg_replace('/\D/', '', (string) ($in['discord_guild_id'] ?? '')),
            'discord_category_id' => preg_replace('/\D/', '', (string) ($in['discord_category_id'] ?? '')),
            'gemini_key'   => sanitize_text_field($in['gemini_key'] ?? ''),
            'gemini_model' => sanitize_text_field($in['gemini_model'] ?? 'gemini-3.5-flash'),
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
          <tr><th scope="row">Discord webhook (stint confirmations)</th>
            <td><input type="text" name="edr_tb_settings[confirm_webhook]" value="<?php echo esc_attr($s['confirm_webhook']); ?>" class="regular-text" autocomplete="off" placeholder="https://discord.com/api/webhooks/...">
            <p class="description">The <strong>team channel</strong> drivers read. Once the line-up and stints are locked, the builder posts here 4 days before the race asking everyone to confirm their stints, and again 2 days before naming whoever has not. Leave blank to turn the reminders off.</p></td></tr>
          <tr><th scope="row">Discord bot token (car voice channels)</th>
            <td><input type="password" name="edr_tb_settings[discord_bot_token]" value="<?php echo esc_attr($s['discord_bot_token']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Lets the builder make one <strong>voice channel per car</strong> the day before an endurance race, post that car's stints in the channel's chat, and delete the channels after the race. The bot needs <em>View Channels</em>, <em>Manage Channels</em> and <em>Send Messages</em> in the category below. Leave blank to turn it off. Never share this token.</p></td></tr>
          <tr><th scope="row">Discord server ID</th>
            <td><input type="text" name="edr_tb_settings[discord_guild_id]" value="<?php echo esc_attr($s['discord_guild_id']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Discord &rarr; Settings &rarr; Advanced &rarr; Developer Mode on, then right-click the EDR server icon &rarr; Copy Server ID.</p></td></tr>
          <tr><th scope="row">Discord category ID</th>
            <td><input type="text" name="edr_tb_settings[discord_category_id]" value="<?php echo esc_attr($s['discord_category_id']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Right-click the <strong>Racing Preparation and Events</strong> category &rarr; Copy Channel ID. Leave blank to find that category by name.</p></td></tr>
          <tr><th scope="row">Gemini API key (optional)</th>
            <td><input type="text" name="edr_tb_settings[gemini_key]" value="<?php echo esc_attr($s['gemini_key']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Enables the <strong>Compose with AI</strong> button on the Weekly tab, which rewrites the generated draft in EDR&rsquo;s voice. Only the draft and the sanitised driver one-liners are sent &mdash; never the source profile briefs. <strong>Use a billing-enabled key:</strong> Google&rsquo;s free tier trains on your prompts and has human reviewers; the paid tier does not, and weekly use costs next to nothing. Leave blank to hide the button.</p></td></tr>
          <tr><th scope="row">Gemini model</th>
            <td><input type="text" name="edr_tb_settings[gemini_model]" value="<?php echo esc_attr($s['gemini_model']); ?>" class="regular-text" autocomplete="off" placeholder="gemini-3.5-flash">
            <p class="description">Which model composes the draft. A Flash model is cheap and plenty for this. If the compose button reports the model was not found, correct the id here.</p></td></tr>
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
    // stint confirmations: drivers confirm (or flag) their own stints from the page, public like
    // /avail; the reminder post is admin-only
    register_rest_route('edr/v1', '/confirm', array(
        'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'edr_tb_rest_confirm_set',
    ));
    register_rest_route('edr/v1', '/confirm/remind', array(
        'methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_confirm_remind',
    ));
    register_rest_route('edr/v1', '/vc', array(
        'methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_vc',
    ));
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
    // per-driver / per-track / per-series team history for the write-up (cheap proxy sweep,
    // resumable like the recap). GET reads + advances a slice, POST starts a fresh sweep.
    register_rest_route('edr/v1', '/history', array(
        'methods' => 'GET', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_history',
    ));
    register_rest_route('edr/v1', '/history/refresh', array(
        'methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_history_refresh',
    ));
    // optional: rewrite the generated draft in EDR's voice with Gemini (facts stay the client's)
    register_rest_route('edr/v1', '/draft/compose', array(
        'methods' => 'POST', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_compose',
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
    // warm Garage 61 pace for a track set, so every viewer (drivers included) sees fresh pace
    // without an admin import. Cache-only: it never calls Garage 61 itself. The plan already
    // publishes each driver's pace, so this exposes nothing new.
    register_rest_route('edr/v1', '/pace', array(
        'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'edr_tb_rest_pace',
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
    /* Read it straight back. On a database still on 3-byte "utf8" rather than utf8mb4, WordPress
       strips 4-byte characters on the way in without raising anything - which silently eats every
       emoji in the write-up. The scheduled post sends this stored copy, so if it came back short
       the tab has to say so rather than let a Sunday job quietly post a de-emojied update. */
    $back = (array) get_option('edr_tb_weekly_draft', array());
    $kept = isset($back['text']) ? (string) $back['text'] : '';
    return rest_ensure_response(array(
        'ok'    => true,
        'at'    => time(),
        'intact' => ($kept === $text),
        'stored' => strlen($kept),
        'sent'   => strlen($text),
    ));
}

/**
 * Discord hard-limits a message to 2000 characters. Split on blank lines so sections stay whole,
 * and never hand the webhook an empty message: it 400s on one, which aborts the whole post.
 *
 * Everything is normalised to \n first. The browser builds the draft with \n, but the write-up
 * is copied out, edited elsewhere and pasted back, so CRLF arrives as a matter of course. This
 * function used to carry literal CRLF inside its own string literals (this file is CRLF), so the
 * paragraph split never matched LF-only text at all: every update over the limit fell through to
 * a hard byte chop instead of splitting at a blank line.
 */
function edr_tb_discord_chunks($text, $limit = 1900) {
    $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
    /* Capture the separators, do not just split on them. The write-up puts a wider gap before
       a section heading than between entries, and rejoining every paragraph with a flat "\n\n"
       silently flattened that back out - the spacing survived the browser and died here. */
    $parts = preg_split("/(\n{2,})/", $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = array(); $cur = ''; $sep = "\n\n";
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {                                     // a captured separator
            $sep = (strlen($part) > 3) ? "\n\n\n" : $part;      // at most one extra blank line
            continue;
        }
        $p = rtrim($part);
        if ($p === '') continue;
        if (strlen($p) > $limit) {                       // one huge block: fall back to line splitting
            foreach (explode("\n", $p) as $line) {
                /* a single line can still exceed the limit if it has no newlines at all, and
                   Discord 400s the whole post rather than truncating - so chop it hard, but on
                   character boundaries: a byte-wise chop lands inside a multi-byte character
                   (every dot separator, dash and emoji in the write-up) and posts mojibake */
                $pieces = (strlen($line) > $limit) ? edr_tb_split_utf8($line, $limit) : array($line);
                foreach ($pieces as $piece) {
                    /* only ever flush something: $cur is empty on the first piece, and pushing
                       that empty string is what made a long post fail with "sent 0 of N" */
                    if ($cur !== '' && strlen($cur) + strlen($piece) + 1 > $limit) { $out[] = $cur; $cur = ''; }
                    $cur .= ($cur === '' ? '' : "\n") . $piece;
                }
            }
            continue;
        }
        $join = ($cur === '') ? '' : $sep;
        if ($cur !== '' && strlen($cur) + strlen($join) + strlen($p) > $limit) { $out[] = $cur; $cur = ''; $join = ''; }
        $cur .= $join . $p;
    }
    if (trim($cur) !== '') $out[] = $cur;
    return $out;
}

/** str_split() cuts on bytes and shreds multi-byte characters. Split on character boundaries
 *  while still measuring against the byte budget the webhook is held to. */
function edr_tb_split_utf8($s, $limit) {
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) return str_split($s, $limit);      // not valid UTF-8: nothing better to do
    $out = array(); $cur = ''; $len = 0;
    foreach ($chars as $ch) {
        $n = strlen($ch);
        if ($len + $n > $limit) { $out[] = $cur; $cur = ''; $len = 0; }
        $cur .= $ch; $len += $n;
    }
    if ($cur !== '') $out[] = $cur;
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
    /* A manual post carries its own text: it is the exact string the admin just previewed, so
       nothing between the preview and Discord can alter it. Reading it back out of the option
       instead means a round trip through the database, and a database on 3-byte "utf8" strips
       every 4-byte emoji on the way through - the post then arrives without the formatting the
       preview promised. The scheduled job has no browser, so it still uses the stored copy. */
    $direct = (string) $req->get_param('text');
    if (trim($direct) !== '') {
        $text = trim(substr($direct, 0, 20000));
    } else {
        $d = (array) get_option('edr_tb_weekly_draft', array());
        $text = isset($d['text']) ? trim((string) $d['text']) : '';
        if ($text === '') {
            return new WP_Error('no_draft', 'No weekly draft stored yet - open the Weekly tab and hit Generate.', array('status' => 409));
        }
        $ageDays = (time() - intval($d['at'])) / DAY_IN_SECONDS;
        $maxAge  = $req->get_param('max_age_days');
        $maxAge  = ($maxAge === null) ? 8 : max(1, intval($maxAge));
        if ($ageDays > $maxAge) {
            return new WP_Error('stale_draft', sprintf('The stored draft is %d days old - refusing to post it. Regenerate it on the Weekly tab.', (int) $ageDays), array('status' => 409));
        }
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
    return rest_ensure_response(array('ok' => true, 'messages' => $sent,
        'draft_age_days' => isset($ageDays) ? round($ageDays, 1) : 0));
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

/**
 * Split an iRacing safety-rating sub-level into a class letter and a real SR.
 * A sub-level encodes class*1000 + SR*100, so 5233 -> ('A', 2.33). Values under 100 are treated
 * as an already-decoded SR with no class (defensive, in case the feed ever sends the plain number).
 */
function edr_tb_sr_parts($v) {
    $v = floatval($v);
    if ($v >= 100) { $cls = (int) ($v / 1000); $sr = round(($v - $cls * 1000) / 100, 2); }
    else           { $cls = 0; $sr = round($v, 2); }
    $names = array(1 => 'R', 2 => 'D', 3 => 'C', 4 => 'B', 5 => 'A', 6 => 'P');
    return array(isset($names[$cls]) ? $names[$cls] : '', $sr);
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
    $matched = 0; $zeroDelta = 0; $curRated = 0; $prevRated = 0;
    foreach ((array) $prev['r'] as $p) { if (intval($p['ir']) > 0) $prevRated++; }
    foreach ((array) $curr['r'] as $k => $c) {
        if (intval($c['ir']) > 0) $curRated++;
        if (!isset($prev['r'][$k])) continue;          // joined since the last snapshot
        $matched++;
        $p = $prev['r'][$k];
        $dIr = intval($c['ir']) - intval($p['ir']);
        /* Garage 61 carries the safety rating as the iRacing sub-level (class*1000 + SR*100), so
           5233 is A 2.33, not a rating of 5233. Split it into a class letter and a real SR before
           diffing, so the delta is in SR points (0.21) and the display reads "A 2.33", not "5233.00". */
        list($cLab, $cSr) = edr_tb_sr_parts($c['sr']);
        list(, $pSr)      = edr_tb_sr_parts($p['sr']);
        $dSr = round($cSr - $pSr, 2);
        if ($dIr === 0 && abs($dSr) < 0.01) { $zeroDelta++; continue; }  // nothing moved
        $movers[] = array(
            'name' => $c['name'], 'irDelta' => $dIr, 'irEnd' => intval($c['ir']),
            'srDelta' => $dSr, 'srStart' => $pSr, 'srEnd' => $cSr,
            'srLabel' => $cLab !== '' ? $cLab : (string) $c['srLabel'],
        );
    }
    usort($movers, function ($a, $b) { return $b['irDelta'] - $a['irDelta']; });
    return array(
        'ready'  => true,
        'from'   => $keys[count($keys) - 2],
        'to'     => $keys[count($keys) - 1],
        'movers' => $movers,
        /* Why "N moved" can read low: only the sports_car category is snapshotted
           (edr_g61_member_ratings_full), and a driver only moves if they raced it between the two
           snapshots. These counts separate a coverage problem (few matched, or few with a rating
           at all) from genuinely-quiet (many matched, nearly all zero-delta). */
        'diag'   => array(
            'prevDrivers' => count((array) $prev['r']),
            'currDrivers' => count((array) $curr['r']),
            'matched'     => $matched,
            'prevRated'   => $prevRated,
            'currRated'   => $curRated,
            'zeroDelta'   => $zeroDelta,
        ),
    );
}

function edr_tb_rest_ratings(WP_REST_Request $req) {
    if ($req->get_method() === 'POST') {
        $r = edr_tb_snapshot_ratings();
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('ok' => true, 'snapshot' => $r, 'movement' => edr_tb_rating_movement()));
    }
    return rest_ensure_response(edr_tb_rating_movement());
}

/**
 * Advance the sweep by one bounded slice, guarded so the cron job and a client poll can never step
 * the same state at once — that would double the proxy calls and let two saves clobber each other.
 * Returns 'idle' (nothing running, or another request holds the lock), 'working', 'done', 'error'.
 *
 * This is what lets the sweep finish on a host with WP-Cron loopback disabled: GET /recap calls it
 * on every 5s poll, so the open tab itself drives the run to completion with no cron at all.
 */
function edr_tb_recap_advance() {
    global $wpdb;
    if (!get_transient('edr_tb_recap_running')) return 'idle';
    if ($wpdb->get_var("SELECT GET_LOCK('edr_tb_recap_step', 0)") != 1) return 'idle';

    $out = 'idle';
    $st  = edr_tb_recap_state();
    $s   = edr_tb_settings();
    if ($st && !empty($s['iracing_url']) && !empty($s['iracing_key'])) {
        @set_time_limit(0);
        $step = edr_tb_recap_step($s['iracing_url'], $s['iracing_key'], $st);
        if (is_wp_error($step)) {
            update_option('edr_tb_recap_err', $step->get_error_message(), false);
            edr_tb_recap_state_clear();
            delete_transient('edr_tb_recap_running');
            $out = 'error';
        } elseif ($step === 'done') {
            update_option('edr_tb_recap', edr_tb_recap_shape($st), false);
            update_option('edr_tb_recap_err', '', false);
            edr_tb_recap_state_clear();
            delete_transient('edr_tb_recap_running');
            $out = 'done';
        } else {
            $out = 'working';   // edr_tb_recap_step saved state as it went
        }
    }
    $wpdb->query("SELECT RELEASE_LOCK('edr_tb_recap_step')");
    return $out;
}

function edr_tb_rest_recap() {
    edr_tb_recap_advance();   // the poll itself drives the sweep — see edr_tb_recap_advance()
    $r = get_option('edr_tb_recap', null);
    $prog = (array) get_option('edr_tb_recap_progress', array());
    $running = (bool) get_transient('edr_tb_recap_running');
    /* With step-on-read driving it, the heartbeat only goes quiet if every tab has closed. Five
       minutes of true silence means the run is abandoned; report it rather than spin forever. */
    $stalled = $running && !empty($prog['at']) && (time() - intval($prog['at']) > 300);
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
    if (empty($s['g61_token'])) {
        return new WP_Error('not_configured', 'Set the Garage 61 token in plugin Settings first.', array('status' => 400));
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

    /* Build the work plan now, in this request. The queued job still builds it where WP-Cron works,
       but on a host with cron loopback dead the plan would never exist and the client's step-on-read
       (GET /recap) would have nothing to advance — leaving the sweep "running" until it timed out,
       which is the failure that kept recurring. One Garage 61 membership call is cheap, and doing it
       here also surfaces a token/proxy problem immediately instead of as a silent stall. */
    $members = edr_g61_all_members($s['g61_token']);
    if (is_wp_error($members)) return $members;
    if (empty($members['ids'])) {
        return new WP_Error('no_members', 'Could not read the Garage 61 membership (needed for iRacing customer IDs).', array('status' => 502));
    }
    $st = edr_tb_recap_state_init($members['ids'], $from, $to);
    edr_tb_recap_state_save($st);
    update_option('edr_tb_recap_err', '', false);

    set_transient('edr_tb_recap_running', 1, 20 * MINUTE_IN_SECONDS);
    wp_schedule_single_event(time(), 'edr_tb_recap_job', array((string) $from, (string) $to));
    if (function_exists('spawn_cron')) spawn_cron();
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

    /* A window means "start (or restart) a sweep" — build the plan and arm the run. The refresh
       route already does this synchronously, so its queued job finds the plan in place and skips
       straight to stepping; a no-argument call is the self-requeue and just resumes. The running
       transient is the single source of truth that a sweep is live: it is armed here and cleared
       only by edr_tb_recap_advance() on done/error, so the re-queue below can never outlive it. */
    if ($from !== '') {
        $st = edr_tb_recap_state();
        $changed = !$st || (string) ($st['from'] ?? '') !== (string) $from || (string) ($st['to'] ?? '') !== (string) $to;
        if ($changed) {
            $members = edr_g61_all_members($s['g61_token']);
            if (is_wp_error($members) || empty($members['ids'])) {
                $fail('Could not read the Garage 61 membership (needed for iRacing customer IDs).');
                return;
            }
            $st = edr_tb_recap_state_init($members['ids'], $from, $to);
            edr_tb_recap_state_save($st);
            update_option('edr_tb_recap_err', '', false);
        }
        set_transient('edr_tb_recap_running', 1, 20 * MINUTE_IN_SECONDS);
    }

    $r = edr_tb_recap_advance();   // one bounded slice, under the shared step lock
    if (($r === 'working' || $r === 'idle') && get_transient('edr_tb_recap_running')) {
        // more to do (or a client poll holds the lock this instant) — hand on to another request
        wp_schedule_single_event(time() + 1, 'edr_tb_recap_job');
        if (function_exists('spawn_cron')) spawn_cron();
    }
}

/* ----------------------------------------------------------------
 * Team history sweep — same resumable, step-on-read machinery as the recap (see
 * edr_tb_recap_advance for the why). Its own lock and running flag so the two never collide.
 * ---------------------------------------------------------------- */
function edr_tb_hist_advance() {
    global $wpdb;
    if (!get_transient('edr_tb_hist_running')) return 'idle';
    if ($wpdb->get_var("SELECT GET_LOCK('edr_tb_hist_step', 0)") != 1) return 'idle';

    $out = 'idle';
    $st  = edr_tb_hist_state();
    $s   = edr_tb_settings();
    if ($st && !empty($s['iracing_url']) && !empty($s['iracing_key'])) {
        @set_time_limit(0);
        $step = edr_tb_hist_step($s['iracing_url'], $s['iracing_key'], $st);
        if (is_wp_error($step)) {
            update_option('edr_tb_history_err', $step->get_error_message(), false);
            edr_tb_hist_state_clear();
            delete_transient('edr_tb_hist_running');
            $out = 'error';
        } elseif ($step === 'done') {
            update_option('edr_tb_history', edr_tb_hist_shape($st), false);
            update_option('edr_tb_history_err', '', false);
            edr_tb_hist_state_clear();
            delete_transient('edr_tb_hist_running');
            $out = 'done';
        } else {
            $out = 'working';   // edr_tb_hist_step banked state as it went
        }
    }
    $wpdb->query("SELECT RELEASE_LOCK('edr_tb_hist_step')");
    return $out;
}

function edr_tb_rest_history() {
    edr_tb_hist_advance();   // the poll drives the sweep, cron or not
    $h = get_option('edr_tb_history', null);
    $prog = (array) get_option('edr_tb_hist_progress', array());
    $running = (bool) get_transient('edr_tb_hist_running');
    $stalled = $running && !empty($prog['at']) && (time() - intval($prog['at']) > 300);
    if ($stalled) { delete_transient('edr_tb_hist_running'); $running = false; }
    return rest_ensure_response(array(
        'history'  => $h ?: null,
        'running'  => $running,
        'progress' => $prog ?: null,
        'stalled'  => $stalled,
        'error'    => $stalled ? 'The history pull stopped partway through — reopen the Weekly tab to resume it.' : (string) get_option('edr_tb_history_err', ''),
    ));
}

function edr_tb_rest_history_refresh(WP_REST_Request $req) {
    $s = edr_tb_settings();
    if (empty($s['iracing_url']) || empty($s['iracing_key'])) {
        return new WP_Error('not_configured', 'Set the iRacing proxy in plugin Settings first.', array('status' => 400));
    }
    if (empty($s['g61_token'])) {
        return new WP_Error('not_configured', 'Set the Garage 61 token in plugin Settings first.', array('status' => 400));
    }
    if (get_transient('edr_tb_hist_running')) {
        return rest_ensure_response(array('ok' => true, 'running' => true, 'queued' => false));
    }
    $days = intval($req->get_param('days'));
    // build the plan now so step-on-read has something to advance even where cron is dead
    $members = edr_g61_all_members($s['g61_token']);
    if (is_wp_error($members)) return $members;
    if (empty($members['ids'])) {
        return new WP_Error('no_members', 'Could not read the Garage 61 membership (needed for iRacing customer IDs).', array('status' => 502));
    }
    $st = edr_tb_hist_state_init($members['ids'], $days);
    edr_tb_hist_state_save($st);
    update_option('edr_tb_history_err', '', false);
    set_transient('edr_tb_hist_running', 1, 30 * MINUTE_IN_SECONDS);
    wp_schedule_single_event(time(), 'edr_tb_hist_job', array($days));
    if (function_exists('spawn_cron')) spawn_cron();
    return rest_ensure_response(array('ok' => true, 'running' => true, 'queued' => true, 'days' => $st['days']));
}

add_action('edr_tb_hist_job', 'edr_tb_hist_run', 10, 1);
function edr_tb_hist_run($days = 0) {
    $s = edr_tb_settings();
    $fail = function ($msg) {
        update_option('edr_tb_history_err', $msg, false);
        edr_tb_hist_state_clear();
        delete_transient('edr_tb_hist_running');
    };
    if (empty($s['iracing_url']) || empty($s['iracing_key']) || empty($s['g61_token'])) {
        $fail('Garage 61 token and iRacing proxy both need to be set.');
        return;
    }
    @set_time_limit(0);
    if (!edr_tb_hist_state()) {   // cron beat the refresh, or a bare job — build the plan
        $members = edr_g61_all_members($s['g61_token']);
        if (is_wp_error($members) || empty($members['ids'])) {
            $fail('Could not read the Garage 61 membership (needed for iRacing customer IDs).');
            return;
        }
        edr_tb_hist_state_save(edr_tb_hist_state_init($members['ids'], intval($days)));
        set_transient('edr_tb_hist_running', 1, 30 * MINUTE_IN_SECONDS);
    }
    $r = edr_tb_hist_advance();
    if (($r === 'working' || $r === 'idle') && get_transient('edr_tb_hist_running')) {
        wp_schedule_single_event(time() + 1, 'edr_tb_hist_job');
        if (function_exists('spawn_cron')) spawn_cron();
    }
}

/* ----------------------------------------------------------------
 * Optional AI compose — rewrite the generated draft in EDR's voice with Gemini.
 *
 * The draft the client sends is the single source of truth for every fact; the model is told, in
 * the strongest terms, to rewrite prose only and invent nothing. Only the draft and the sanitised
 * one-line driver notes ($voice) are sent — never the source profile briefs (which the codebase
 * does not even hold). Server-side so the key never reaches a browser, same as every other credential.
 * ---------------------------------------------------------------- */
function edr_tb_gemini_compose($draft, $voice) {
    $s = edr_tb_settings();
    if (empty($s['gemini_key'])) {
        return new WP_Error('no_key', 'Add a Gemini API key in plugin Settings to use Compose with AI.', array('status' => 400));
    }
    $draft = trim((string) $draft);
    if ($draft === '') return new WP_Error('empty', 'Generate the draft first, then compose.', array('status' => 400));

    $model = ($s['gemini_model'] !== '') ? $s['gemini_model'] : 'gemini-3.5-flash';

    $system = "You are the race-week correspondent for Endurotech Racing (EDR), a GT3/GTP iRacing "
        . "endurance team of adult amateurs who race for fun.\n\n"
        . "YOUR JOB: rewrite the weekly update below into one polished, flowing briefing in EDR's voice - "
        . "Australian English, warm and a little irreverent, sentence case, no em dashes.\n\n"
        . "Return ONLY the finished briefing, ready to paste. Do NOT analyse, verify, compare, fact-check, "
        . "explain or comment on the draft, and do not narrate what you are doing. No preamble, no notes, no "
        . "checklists, no 'here is the rewrite' - output nothing but the rewritten briefing itself.\n\n"
        . "CONTENT RULES:\n"
        . "- Every fact must come from the draft. Never invent, change or drop a statistic, result, driver name, "
        . "car, track, race length, session time, date or event.\n"
        . "- Keep every section heading exactly as written, including the emoji that starts it, and keep every "
        . "schedule detail (track, car, race length, session times) exact.\n"
        . "- Rewrite the intro, the award wording and the one-line driver mentions for flow and character, using the "
        . "personality notes. Those notes are voice only: never state them as facts.\n"
        . "- Only mention a driver the draft already mentions.\n"
        . "- It is posted straight into Discord. Keep the layout exactly: ## for the title, ### for each section "
        . "heading, the subject of an entry **bold** and alone on its line, its facts on the next line, quiet "
        . "supporting detail in *italics*, the human note as a > quote, one blank line between entries and two "
        . "above a section heading. Keep the medal emoji on the top three; do not add emoji anywhere else. "
        . "NEVER use -# subtext and never use a single # heading: older Discord clients render -# as a large "
        . "heading, which inverts the whole post. Keep lines short - Discord wraps narrow on mobile.";

    $voiceTxt = '';
    if (is_array($voice) && $voice) {
        $lines = array();
        foreach ($voice as $name => $note) {
            $name = trim((string) $name); $note = trim((string) $note);
            if ($name !== '' && $note !== '') $lines[] = '- ' . $name . ': ' . $note;
        }
        if ($lines) $voiceTxt = "\n\nDriver personality notes (voice and character only, never facts):\n" . implode("\n", $lines);
    }

    $base = array(
        'systemInstruction' => array('parts' => array(array('text' => $system))),
        'contents' => array(array('role' => 'user', 'parts' => array(array(
            'text' => "This week's draft. Every fact in your version must come from it:\n\n" . $draft . $voiceTxt,
        )))),
        'generationConfig' => array('temperature' => 0.7, 'maxOutputTokens' => 8192),
    );

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $call = function ($cfg) use ($url, $s) {
        return wp_remote_post($url, array(
            'timeout' => 60,
            'headers' => array('Content-Type' => 'application/json', 'x-goog-api-key' => $s['gemini_key']),
            'body'    => wp_json_encode($cfg),
        ));
    };

    /* Turn the model's "thinking" off: it is a rewrite, not a reasoning task, and a thinking model can
       burn the whole token budget reasoning/verifying and return a truncated fact-check with no draft.
       thinkingBudget lives under generationConfig in newer models and used to sit at the top level, and
       a model that does not support disabling it 400s — so try the current shape, then the legacy one,
       then plain, and never let that alone break compose. */
    $variants = array(
        array_merge_recursive($base, array('generationConfig' => array('thinkingConfig' => array('thinkingBudget' => 0)))),
        array_merge($base, array('thinkingConfig' => array('thinkingBudget' => 0))),
        $base,
    );
    $res = null; $code = 0; $data = null;
    foreach ($variants as $i => $cfg) {
        $res = $call($cfg);
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code === 200) break;
        // only fall through to the next variant when it is the thinking field being rejected
        $emsg = (is_array($data) && isset($data['error']['message'])) ? strtolower((string) $data['error']['message']) : '';
        if ($i < count($variants) - 1 && (strpos($emsg, 'thinking') !== false || strpos($emsg, 'thinkingbudget') !== false || strpos($emsg, 'thinkingconfig') !== false)) {
            continue;
        }
        break;
    }
    if ($code !== 200) {
        $msg = (is_array($data) && isset($data['error']['message'])) ? (string) $data['error']['message'] : ('Gemini returned HTTP ' . $code . '.');
        return new WP_Error('gemini_http', $msg, array('status' => 502));
    }
    $text = '';
    if (isset($data['candidates'][0]['content']['parts']) && is_array($data['candidates'][0]['content']['parts'])) {
        foreach ($data['candidates'][0]['content']['parts'] as $p) {
            if (isset($p['text'])) $text .= $p['text'];
        }
    }
    $text = trim($text);
    if ($text === '') {
        $reason = isset($data['candidates'][0]['finishReason']) ? (' (' . (string) $data['candidates'][0]['finishReason'] . ')') : '';
        return new WP_Error('gemini_empty', 'Gemini returned no text' . $reason . '. Try again, or pick a different model in Settings.', array('status' => 502));
    }
    return $text;
}

function edr_tb_rest_pace(WP_REST_Request $req) {
    $tracks = edr_tb_pace_tracks_clean((string) $req->get_param('tracks'));
    $pace = edr_tb_pace_get($tracks);
    return rest_ensure_response(array(
        'key'    => edr_tb_pace_key($tracks),
        'at'     => isset($pace['at']) ? intval($pace['at']) : 0,
        'roster' => isset($pace['roster']) ? $pace['roster'] : array(),
    ));
}

function edr_tb_rest_compose(WP_REST_Request $req) {
    $voice = $req->get_param('voice');
    $out = edr_tb_gemini_compose((string) $req->get_param('draft'), is_array($voice) ? $voice : array());
    if (is_wp_error($out)) return $out;
    return rest_ensure_response(array('ok' => true, 'text' => $out));
}

function edr_tb_rest_rev(WP_REST_Request $req) {
    // the viewer's selected event sends its Garage 61 tracks; older clients send none, so fall
    // back to whatever was imported last (the pre-2.4.39 behaviour)
    $tracks = edr_tb_pace_tracks_clean((string) $req->get_param('tracks'));
    if (!$tracks) $tracks = edr_tb_pace_tracks_clean(get_option('edr_tb_pace_tracks', array()));
    $pace = edr_tb_pace_get($tracks);
    edr_tb_maybe_schedule_pace($req->get_param('ev'), $tracks);
    return rest_ensure_response(array(
        'plan'    => intval(get_option('edr_tb_plan_rev', 0)),
        'avail'   => intval(get_option('edr_tb_avail_rev', 0)),
        'paceAt'  => isset($pace['at']) ? intval($pace['at']) : 0,
        'paceErr' => isset($pace['err']) ? (string) $pace['err'] : '',
        'paceKey' => edr_tb_pace_key($tracks),
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
function edr_tb_maybe_schedule_pace($ev = '', $tracks = array()) {
    $s = edr_tb_settings();
    if (empty($s['g61_token'])) return;
    $tracks = edr_tb_pace_tracks_clean($tracks);
    if (!$tracks) return;                       // event has no Garage 61 tracks — nothing to keep warm
    // one debounce per track set, plus a site-wide one: at most one Garage 61 pull every 10
    // minutes however many events are being viewed (or however many track sets someone sends to
    // this public route) — the old single-slot cache had exactly this ceiling
    $qkey = 'edr_tb_pace_q_' . md5(edr_tb_pace_key($tracks));
    if (get_transient($qkey) || get_transient('edr_tb_pace_queued')) return;

    $pace = edr_tb_pace_get($tracks);
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

    set_transient($qkey, 1, 10 * MINUTE_IN_SECONDS);
    set_transient('edr_tb_pace_queued', 1, 10 * MINUTE_IN_SECONDS);
    wp_schedule_single_event(time(), 'edr_tb_pace_refresh', array($tracks));
}

/* The background job itself. Failures are recorded rather than thrown away, so the Setup tab
   can say why the pace is old instead of just showing a stale timestamp. */
add_action('edr_tb_pace_refresh', 'edr_tb_do_pace_refresh', 10, 1);
function edr_tb_do_pace_refresh($tracks) {
    $s = edr_tb_settings();
    if (empty($s['g61_token'])) return;
    $tracks = edr_tb_pace_tracks_clean($tracks);
    if (!$tracks) return;
    $roster = edr_g61_roster($s['g61_token'], $tracks, ($s['team_slug'] ?: 'edr-endurotech'));
    if (is_wp_error($roster)) {
        $prev = edr_tb_pace_get($tracks);
        $prev['err'] = $roster->get_error_message();
        $prev['tracks'] = $tracks;
        edr_tb_pace_put($tracks, $prev);
        return;
    }
    edr_tb_pace_put($tracks, array(
        'at'     => time(),
        'tracks' => $tracks,
        'roster' => $roster,
        'err'    => '',
    ));
    delete_transient('edr_tb_pace_q_' . md5(edr_tb_pace_key($tracks)));
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
        'confirm'       => (object) get_option('edr_tb_confirm', array()),          // {ev: {name: {st, sig, cant, at}}}
        'confirmPosted' => (object) get_option('edr_tb_confirm_posted', array()),   // {ev: {d4: ts, d2: ts}}
        'vc'            => edr_tb_vc_public(),                                         // car voice channels: state for the admin box
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
    foreach (array('edr_tb_avail', 'edr_tb_prefs', 'edr_tb_avail_seen', 'edr_tb_confirm', 'edr_tb_confirm_posted') as $opt) {
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
        foreach (array('edr_tb_avail', 'edr_tb_prefs', 'edr_tb_avail_seen', 'edr_tb_confirm', 'edr_tb_confirm_posted') as $opt) {
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

/* ----------------------------------------------------------------
 * Stint confirmations (2.5.0)
 *
 * A driver confirms the stints they have been given ("ok") or flags that they cannot make some
 * of them ("no", with the stint numbers). Each record carries the signature of the stints it was
 * made against; the admin's plan save publishes the current signature per driver (plan.confirm),
 * so a confirmation silently lapses back to "not yet" the moment their stints change.
 * Advisory, like availability: the page is the team's private page and runs on trust.
 * ---------------------------------------------------------------- */
function edr_tb_rest_confirm_set(WP_REST_Request $req) {
    global $wpdb;
    $name = substr(sanitize_text_field((string) $req->get_param('name')), 0, 80);
    $ev   = substr(sanitize_text_field((string) $req->get_param('ev')), 0, 120);
    $st   = (string) $req->get_param('st');
    if ($name === '' || $ev === '') return new WP_Error('bad_req', 'Need a driver name and event.', array('status' => 400));
    if (!edr_tb_valid_ev($ev)) return new WP_Error('bad_ev', 'Unrecognised event.', array('status' => 400));
    if (!in_array($st, array('ok', 'no', 'clear'), true)) return new WP_Error('bad_req', 'Unknown answer.', array('status' => 400));
    $sig  = substr(sanitize_text_field((string) $req->get_param('sig')), 0, 600);
    $cant = array_slice(array_values(array_unique(array_filter(
        array_map('intval', (array) $req->get_param('cant')),
        function ($v) { return $v >= 1 && $v < 500; }
    ))), 0, 100);

    $locked = $wpdb->get_var("SELECT GET_LOCK('edr_tb_avail_write', 5)");
    if ((int) $locked !== 1) {
        return new WP_Error('busy', 'Someone else is saving right now — try again in a moment.', array('status' => 503));
    }
    wp_cache_delete('edr_tb_confirm', 'options');
    $all = (array) get_option('edr_tb_confirm', array());
    if (!isset($all[$ev]) && count($all) >= EDR_TB_MAX_EVENTS) {
        $wpdb->query("SELECT RELEASE_LOCK('edr_tb_avail_write')");
        return new WP_Error('ev_full', 'Too many events stored — an admin needs to clear out old ones.', array('status' => 409));
    }
    if (!isset($all[$ev]) || !is_array($all[$ev])) $all[$ev] = array();
    if (!isset($all[$ev][$name]) && count($all[$ev]) >= EDR_TB_MAX_DRIVERS) {
        $wpdb->query("SELECT RELEASE_LOCK('edr_tb_avail_write')");
        return new WP_Error('ev_full', 'Too many drivers on this event.', array('status' => 409));
    }
    if ($st === 'clear') unset($all[$ev][$name]);
    else $all[$ev][$name] = array('st' => $st, 'sig' => $sig, 'cant' => ($st === 'no' ? $cant : array()), 'at' => current_time('mysql'));
    update_option('edr_tb_confirm', $all, false);
    edr_tb_bump_avail_rev();   // admins see it on their next heartbeat
    $wpdb->query("SELECT RELEASE_LOCK('edr_tb_avail_write')");
    return rest_ensure_response(array('ok' => true, 'rec' => $all[$ev][$name] ?? null));
}

/** Who has confirmed what, against the stints the plan currently gives them. */
function edr_tb_confirm_status($plan) {
    $c   = (isset($plan['confirm']) && is_array($plan['confirm'])) ? $plan['confirm'] : array();
    $ev  = (string) ($c['ev'] ?? '');
    $drv = (isset($c['drivers']) && is_array($c['drivers'])) ? $c['drivers'] : array();
    $store = (array) get_option('edr_tb_confirm', array());
    $recs = isset($store[$ev]) ? (array) $store[$ev] : array();
    $out = array('ev' => $ev, 'ok' => array(), 'no' => array(), 'pending' => array());
    foreach ($drv as $name => $d) {
        $r = isset($recs[$name]) ? (array) $recs[$name] : null;
        $live = $r && (string) ($r['sig'] ?? '') === (string) ($d['sig'] ?? '');
        if ($live && ($r['st'] ?? '') === 'ok') $out['ok'][] = $name;
        elseif ($live && ($r['st'] ?? '') === 'no') $out['no'][$name] = array_map('intval', (array) ($r['cant'] ?? array()));
        else $out['pending'][] = $name;
    }
    return $out;
}

function edr_tb_confirm_text($stage, $plan) {
    $c = edr_tb_confirm_status($plan);
    $evName = explode('|', $c['ev'])[0];
    $url = (string) get_option('edr_tb_page_url', '');
    $link = $url ? "\n" . $url . '#stints' : '';
    $total = count($c['ok']) + count($c['no']) + count($c['pending']);
    $cant = array();
    foreach ($c['no'] as $n => $st) $cant[] = $n . ($st ? ' (stint ' . implode(', ', $st) . ')' : '');
    $lines = array();
    if ($stage === 'd4') {
        $lines[] = '🏁 **' . $evName . '** — the stints are set.';
        $lines[] = 'Check yours and tap **I\'m good for these**, or **I can\'t make one** so we can sort a swap early.' . $link;
        $lines[] = '';
        if ($c['pending']) $lines[] = '⏳ Still to confirm: ' . implode(', ', $c['pending']);
        if ($cant)         $lines[] = '✗ Can\'t make one: ' . implode(', ', $cant);
        $lines[] = '✓ Confirmed: ' . count($c['ok']) . ' of ' . $total;
    } else {
        if (!$c['pending'] && !$cant) {
            $lines[] = '✅ Everyone has confirmed their stints for **' . $evName . '**. See you on track.';
        } else {
            $lines[] = '⏳ **' . $evName . '** is 2 days away.';
            if ($c['pending']) $lines[] = 'Still waiting on: ' . implode(', ', $c['pending']) . ' — please confirm your stints.' . $link;
            if ($cant)         $lines[] = '✗ Can\'t make one: ' . implode(', ', $cant) . ' — admins, these need a swap.';
        }
    }
    return array('text' => implode("\n", $lines), 'status' => $c);
}

function edr_tb_confirm_post($text) {
    $s = edr_tb_settings();
    if (empty($s['confirm_webhook'])) return new WP_Error('no_webhook', 'Set the stint-confirmation Discord webhook in plugin Settings first.', array('status' => 400));
    $sent = 0;
    $chunks = edr_tb_discord_chunks($text);
    foreach ($chunks as $i => $chunk) {
        $res = wp_remote_post($s['confirm_webhook'], array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array('content' => $chunk, 'allowed_mentions' => array('parse' => array()))),
        ));
        if (is_wp_error($res)) return new WP_Error('discord', 'Discord post failed: ' . $res->get_error_message(), array('status' => 502));
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) return new WP_Error('discord', 'Discord returned HTTP ' . $code, array('status' => 502));
        $sent++;
        if ($i < count($chunks) - 1) sleep(1);
    }
    return $sent;
}

function edr_tb_confirm_mark($ev, $stages) {
    $posted = (array) get_option('edr_tb_confirm_posted', array());
    if (!isset($posted[$ev]) || !is_array($posted[$ev])) $posted[$ev] = array();
    foreach ($stages as $st) $posted[$ev][$st] = time();
    update_option('edr_tb_confirm_posted', $posted, false);
    edr_tb_bump_avail_rev();
}

/** Admin: preview (dry) or send a reminder now. Sending also counts as that stage's post. */
function edr_tb_rest_confirm_remind(WP_REST_Request $req) {
    $stage = ($req->get_param('stage') === 'd2') ? 'd2' : 'd4';
    $plan = (array) get_option('edr_tb_plan', array());
    if (empty($plan['confirm']['ev'])) return new WP_Error('no_plan', 'No stints in the saved plan yet.', array('status' => 409));
    $msg = edr_tb_confirm_text($stage, $plan);
    if ($req->get_param('dry')) return rest_ensure_response(array('ok' => true, 'text' => $msg['text']));
    $sent = edr_tb_confirm_post($msg['text']);
    if (is_wp_error($sent)) return $sent;
    edr_tb_confirm_mark($plan['confirm']['ev'], $stage === 'd2' ? array('d4', 'd2') : array('d4'));
    return rest_ensure_response(array('ok' => true, 'text' => $msg['text'], 'messages' => $sent));
}

/**
 * Hourly: post the 4-day and 2-day reminders for the plan's event. Only once the line-up and
 * stints are both locked — a plan still being shuffled must not ask people to confirm it. If the
 * lock lands late (inside 2 days), only the 2-day post goes out. Each stage posts once per event.
 */
add_action('edr_tb_confirm_tick', 'edr_tb_confirm_run');
function edr_tb_confirm_run() {
    $s = edr_tb_settings();
    if (empty($s['confirm_webhook'])) return;
    $plan = (array) get_option('edr_tb_plan', array());
    $c = (isset($plan['confirm']) && is_array($plan['confirm'])) ? $plan['confirm'] : array();
    $ev = (string) ($c['ev'] ?? '');
    if ($ev === '' || $ev !== (string) ($plan['evsel'] ?? '') || empty($c['drivers'])) return;
    if (empty($plan['teamsLocked']) || empty($plan['stintsLocked'])) return;
    $start = intval($c['start'] ?? 0) / 1000;
    $now = time();
    if ($start <= $now) return;
    $posted = (array) get_option('edr_tb_confirm_posted', array());
    $done = isset($posted[$ev]) ? (array) $posted[$ev] : array();
    $stage = null;
    if (empty($done['d2']) && $now >= $start - 2 * DAY_IN_SECONDS) $stage = 'd2';
    elseif (empty($done['d4']) && empty($done['d2']) && $now >= $start - 4 * DAY_IN_SECONDS) $stage = 'd4';
    if (!$stage) return;
    $msg = edr_tb_confirm_text($stage, $plan);
    if (is_wp_error(edr_tb_confirm_post($msg['text']))) return;   // try again next hour
    edr_tb_confirm_mark($ev, $stage === 'd2' ? array('d4', 'd2') : array('d4'));
}
add_action('init', function () {
    if (!wp_next_scheduled('edr_tb_confirm_tick')) wp_schedule_event(time() + 300, 'hourly', 'edr_tb_confirm_tick');
});
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('edr_tb_confirm_tick'); });

/* ----------------------------------------------------------------
 * Car voice channels (2.6.0)
 *
 * The day before an endurance race the bot makes one voice channel per car inside the team's
 * "Racing Preparation and Events" category, posts that car's stints in the channel's built-in
 * chat, reposts them if they change, and deletes the channels after the race. Needs a bot token:
 * webhooks can post but cannot create or delete channels. What to post comes from the plan
 * (plan.channels, written by the admin's browser on save: car names, drivers, stint times).
 * State per event lives in edr_tb_vc: {ev: {created, deleted, cat, chans: {carKey: {id, name, hash}}, err}}.
 * ---------------------------------------------------------------- */
define('EDR_TB_VC_BEFORE', DAY_IN_SECONDS);        // create this long before the first car starts
define('EDR_TB_VC_AFTER',  12 * HOUR_IN_SECONDS);  // delete this long after the last car finishes
define('EDR_TB_VC_CATEGORY', 'Racing Preparation and Events');

function edr_tb_discord_api($method, $path, $body = null) {
    $s = edr_tb_settings();
    if (empty($s['discord_bot_token'])) return new WP_Error('no_bot', 'Set the Discord bot token in plugin Settings first.', array('status' => 400));
    $args = array(
        'method'  => $method,
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Bot ' . $s['discord_bot_token'],
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'DiscordBot (https://endurotechracing.com, ' . EDR_TB_VER . ')',
        ),
    );
    if ($body !== null) $args['body'] = wp_json_encode($body);
    $res = wp_remote_request('https://discord.com/api/v10' . $path, $args);
    if (is_wp_error($res)) return new WP_Error('discord', 'Discord: ' . $res->get_error_message(), array('status' => 502));
    $code = wp_remote_retrieve_response_code($res);
    $json = json_decode((string) wp_remote_retrieve_body($res), true);
    if ($code === 404 && $method === 'DELETE') return array();   // already gone
    if ($code < 200 || $code >= 300) {
        $why = is_array($json) && isset($json['message']) ? $json['message'] : 'HTTP ' . $code;
        if ($code === 401) $why = 'the bot token is wrong';
        if ($code === 403) $why = 'the bot is missing a permission (View Channels, Manage Channels, Send Messages) in that category';
        return new WP_Error('discord', 'Discord refused: ' . $why, array('status' => 502));
    }
    return is_array($json) ? $json : array();
}

/** The category to create in: the configured ID, else the one named EDR_TB_VC_CATEGORY. */
function edr_tb_vc_category() {
    $s = edr_tb_settings();
    if (!empty($s['discord_category_id'])) return $s['discord_category_id'];
    if (empty($s['discord_guild_id'])) return new WP_Error('no_guild', 'Set the Discord server ID in plugin Settings first.', array('status' => 400));
    $chans = edr_tb_discord_api('GET', '/guilds/' . $s['discord_guild_id'] . '/channels');
    if (is_wp_error($chans)) return $chans;
    foreach ($chans as $c) {
        if (intval($c['type'] ?? -1) === 4 && strcasecmp(trim((string) $c['name']), EDR_TB_VC_CATEGORY) === 0) return (string) $c['id'];
    }
    return new WP_Error('no_category', 'Could not find the "' . EDR_TB_VC_CATEGORY . '" category — set its ID in plugin Settings.', array('status' => 404));
}

/** One car's chat post. Discord <t:…> stamps show every reader their own local time. */
function edr_tb_vc_text($car, $ch) {
    $t = function ($ms, $f) { return '<t:' . intval(intval($ms) / 1000) . ':' . $f . '>'; };
    $stints = (array) ($car['stints'] ?? array());
    $lines = array('🏁 **' . ($ch['name'] ?? 'Endurance race') . '** — **' . ($car['name'] ?? 'Car') . '**'
        . (!empty($car['cls']) ? ' (' . $car['cls'] . ')' : ''));
    if (!empty($car['drivers'])) $lines[] = 'Drivers: ' . implode(', ', (array) $car['drivers']);
    if ($stints) $lines[] = 'Race start ' . $t($stints[0]['s'], 'F');
    $lines[] = '';
    $lines[] = '**Stints** (shown in your own time)';
    foreach ($stints as $st) {
        $lines[] = intval($st['n']) . ' · ' . ((string) ($st['d'] ?? '') !== '' ? $st['d'] : '—') . ' · '
            . $t($st['s'], 't') . '–' . $t($st['e'], 't') . (!empty($st['wx']) ? ' ' . $st['wx'] : '');
    }
    $lines[] = '';
    $lines[] = 'Can\'t make a stint? ' . (!empty($ch['swap']) ? $ch['swap'] : 'Message Thomaz on Discord — @tomhernandes');
    return implode("\n", $lines);
}

function edr_tb_vc_post($channelId, $text) {
    foreach (edr_tb_discord_chunks($text) as $i => $chunk) {
        if ($i) sleep(1);
        $r = edr_tb_discord_api('POST', '/channels/' . $channelId . '/messages', array('content' => $chunk, 'allowed_mentions' => array('parse' => array())));
        if (is_wp_error($r)) return $r;
    }
    return true;
}

function edr_tb_vc_state_get($ev) { $all = (array) get_option('edr_tb_vc', array()); return isset($all[$ev]) ? (array) $all[$ev] : array(); }
function edr_tb_vc_state_put($ev, $st) {
    $all = (array) get_option('edr_tb_vc', array());
    $all[$ev] = $st;
    // only the last few events are worth remembering
    if (count($all) > 10) $all = array_slice($all, -10, null, true);
    update_option('edr_tb_vc', $all, false);
    edr_tb_bump_avail_rev();   // admin boxes refresh
}
function edr_tb_vc_public() {
    $s = edr_tb_settings();
    return array('ready' => !empty($s['discord_bot_token']) && (!empty($s['discord_category_id']) || !empty($s['discord_guild_id'])),
                 'events' => (object) get_option('edr_tb_vc', array()));
}

/** Create the missing channels for the plan's event and (re)post each car's stints when they changed. */
function edr_tb_vc_sync($plan) {
    $ch = (isset($plan['channels']) && is_array($plan['channels'])) ? $plan['channels'] : array();
    $ev = (string) ($ch['ev'] ?? '');
    if ($ev === '' || empty($ch['cars'])) return new WP_Error('no_cars', 'No cars in the saved plan yet.', array('status' => 409));
    $st = edr_tb_vc_state_get($ev);
    if (!empty($st['deleted'])) return new WP_Error('done', 'The channels for this race were already deleted.', array('status' => 409));
    $cat = !empty($st['cat']) ? (string) $st['cat'] : edr_tb_vc_category();   // look the category up once per event
    if (is_wp_error($cat)) { $st['err'] = $cat->get_error_message(); edr_tb_vc_state_put($ev, $st); return $cat; }
    $s = edr_tb_settings();
    $guild = $s['discord_guild_id'];
    if ($guild === '') {   // only the category ID was given: ask Discord which server it is in
        $c = edr_tb_discord_api('GET', '/channels/' . $cat);
        if (is_wp_error($c)) return $c;
        $guild = (string) ($c['guild_id'] ?? '');
    }
    $st['cat'] = $cat;
    $chans = isset($st['chans']) ? (array) $st['chans'] : array();
    $made = 0; $posted = 0;
    foreach ((array) $ch['cars'] as $car) {
        $key = (string) ($car['key'] ?? ''); if ($key === '') continue;
        $name = substr((string) ($car['name'] ?? 'Car'), 0, 90);
        if (empty($chans[$key]['id'])) {
            if ($made) sleep(1);
            $r = edr_tb_discord_api('POST', '/guilds/' . $guild . '/channels', array('name' => $name, 'type' => 2, 'parent_id' => $cat));
            if (is_wp_error($r)) { $st['chans'] = $chans; $st['err'] = $r->get_error_message(); edr_tb_vc_state_put($ev, $st); return $r; }
            $chans[$key] = array('id' => (string) $r['id'], 'name' => $name, 'hash' => '');
            $made++;
        }
        $text = edr_tb_vc_text($car, $ch);
        $hash = md5($text);
        if ($chans[$key]['hash'] !== $hash) {
            $body = ($chans[$key]['hash'] === '' ? '' : "🔄 **Updated stints**\n") . $text;
            $r = edr_tb_vc_post($chans[$key]['id'], $body);
            if (is_wp_error($r)) { $st['chans'] = $chans; $st['err'] = $r->get_error_message(); edr_tb_vc_state_put($ev, $st); return $r; }
            $chans[$key]['hash'] = $hash; $posted++;
        }
    }
    $st['chans'] = $chans;
    if (empty($st['created'])) $st['created'] = time();
    $st['err'] = '';
    edr_tb_vc_state_put($ev, $st);
    return array('made' => $made, 'posted' => $posted);
}

function edr_tb_vc_delete($ev) {
    $st = edr_tb_vc_state_get($ev);
    $chans = isset($st['chans']) ? (array) $st['chans'] : array();
    foreach ($chans as $key => $c) {
        if (empty($c['id'])) continue;
        $r = edr_tb_discord_api('DELETE', '/channels/' . $c['id']);
        if (is_wp_error($r)) { $st['err'] = $r->get_error_message(); edr_tb_vc_state_put($ev, $st); return $r; }
        unset($chans[$key]);
        sleep(1);
    }
    $st['chans'] = $chans; $st['deleted'] = time(); $st['err'] = '';
    edr_tb_vc_state_put($ev, $st);
    return true;
}

/** Race window of the plan's event from its stint times: [first start, last finish] in seconds. */
function edr_tb_vc_window($plan) {
    $first = 0; $last = 0;
    foreach ((array) ($plan['channels']['cars'] ?? array()) as $car) {
        foreach ((array) ($car['stints'] ?? array()) as $s) {
            $a = intval($s['s']) / 1000; $b = intval($s['e']) / 1000;
            if (!$first || $a < $first) $first = $a;
            if ($b > $last) $last = $b;
        }
    }
    return array($first, $last);
}

/**
 * Hourly: inside [first start - 1 day, last finish] keep the channels in sync (create, repost
 * changed stints); after last finish + 12 h delete them. Line-up and stints must both be locked —
 * a plan still being shuffled should not open channels. Anything this run made before its plan
 * moved to another event is still cleaned up once that event is over.
 */
add_action('edr_tb_vc_tick', 'edr_tb_vc_run');
function edr_tb_vc_run() {
    $pub = edr_tb_vc_public();
    if (!$pub['ready']) return;
    $now = time();
    $plan = (array) get_option('edr_tb_plan', array());
    $ev = (string) ($plan['channels']['ev'] ?? '');
    list($first, $last) = edr_tb_vc_window($plan);
    if ($ev !== '' && $ev === (string) ($plan['evsel'] ?? '') && $first && !empty($plan['teamsLocked']) && !empty($plan['stintsLocked'])
        && $now >= $first - EDR_TB_VC_BEFORE && $now < $last) {
        edr_tb_vc_sync($plan);
    }
    foreach ((array) get_option('edr_tb_vc', array()) as $k => $st) {
        $st = (array) $st;
        if (empty($st['chans']) || !empty($st['deleted'])) continue;
        $end = ($k === $ev && $last) ? $last : 0;
        if (!$end) { $parts = explode('|', (string) $k); $end = strtotime(end($parts) . ' +4 days'); }   // an older event: its date + the longest race weekend
        if ($end && $now >= $end + EDR_TB_VC_AFTER) edr_tb_vc_delete($k);
    }
}
add_action('init', function () {
    if (!wp_next_scheduled('edr_tb_vc_tick')) wp_schedule_event(time() + 600, 'hourly', 'edr_tb_vc_tick');
});
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('edr_tb_vc_tick'); });

/** Admin: test the bot, create/sync now, or delete now. */
function edr_tb_rest_vc(WP_REST_Request $req) {
    $act = (string) $req->get_param('action');
    $plan = (array) get_option('edr_tb_plan', array());
    if ($act === 'test') {
        $cat = edr_tb_vc_category(); if (is_wp_error($cat)) return $cat;
        $s = edr_tb_settings(); $guild = $s['discord_guild_id'];
        if ($guild === '') { $c = edr_tb_discord_api('GET', '/channels/' . $cat); if (is_wp_error($c)) return $c; $guild = (string) ($c['guild_id'] ?? ''); }
        $r = edr_tb_discord_api('POST', '/guilds/' . $guild . '/channels', array('name' => 'EDR test channel', 'type' => 2, 'parent_id' => $cat));
        if (is_wp_error($r)) return $r;
        $p = edr_tb_vc_post($r['id'], "🔧 Test from the EDR Team Builder: this voice channel was made by the bot and is deleted again straight away.");
        sleep(1);
        $d = edr_tb_discord_api('DELETE', '/channels/' . $r['id']);
        if (is_wp_error($p)) return $p;
        if (is_wp_error($d)) return new WP_Error('discord', 'Made the test channel but could not delete it: ' . $d->get_error_message(), array('status' => 502));
        return rest_ensure_response(array('ok' => true, 'message' => 'Bot works: made a voice channel in the category, posted in its chat, and deleted it.'));
    }
    if ($act === 'create') {
        $r = edr_tb_vc_sync($plan);
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('ok' => true, 'message' => 'Channels ready: ' . $r['made'] . ' made, ' . $r['posted'] . ' stint posts.'));
    }
    if ($act === 'delete') {
        $ev = (string) ($req->get_param('ev') ?: ($plan['channels']['ev'] ?? ''));
        $r = edr_tb_vc_delete($ev);
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(array('ok' => true, 'message' => 'Channels deleted.'));
    }
    return new WP_Error('bad_req', 'Unknown action.', array('status' => 400));
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
    $pace = edr_tb_pace_get($trackIds);
    $sameTracks = !empty($pace['tracks']);
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
    edr_tb_pace_put($trackIds, array(
        'at' => time(), 'tracks' => edr_tb_pace_tracks_clean($trackIds), 'roster' => $roster, 'err' => '',
    ));
    return rest_ensure_response(array(
        'roster' => $roster, 'needs_availability' => false, 'cached' => false, 'at' => time(),
    ));
}

/* ----------------------------------------------------------------
 * Shortcode  [edr_team_builder]
 * ---------------------------------------------------------------- */
add_shortcode('edr_team_builder', function () {
    // the reminder posts link drivers back here, and a cron job has no page of its own to ask
    $url = get_permalink();
    if ($url && $url !== get_option('edr_tb_page_url')) update_option('edr_tb_page_url', $url, false);
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
