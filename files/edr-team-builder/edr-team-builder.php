<?php
/**
 * Plugin Name: EDR Team Builder
 * Description: Endurotech Racing endurance team + stint planner. Pulls Garage 61 pace and official iRacing session times, collects driver availability in-house, and builds Pro/Casual teams and stint rotations. Add the [edr_team_builder] shortcode to a page.
 * Version: 2.4.9
 * Author: Endurotech Racing
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit; // no direct access

define('EDR_TB_DIR', plugin_dir_path(__FILE__));
define('EDR_TB_URL', plugin_dir_url(__FILE__));
define('EDR_TB_VER', '2.4.9');

require_once EDR_TB_DIR . 'includes/garage61.php';
require_once EDR_TB_DIR . 'includes/iracing.php';

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
    ));
}

/**
 * Editing is allowed for logged-in users, or for anyone presenting the admin
 * password (plugin Settings) in the X-EDR-Pass header — the team's planner
 * does not have a WordPress account.
 */
function edr_tb_req_can_edit(WP_REST_Request $req) {
    if (is_user_logged_in()) return true;
    $s = edr_tb_settings();
    $p = (string) $req->get_header('x-edr-pass');
    return $s['edit_pass'] !== '' && $p !== '' && hash_equals($s['edit_pass'], $p);
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
    $seasons = edr_ir_seasons($s['iracing_url'], $s['iracing_key']);
    if (is_wp_error($seasons)) {
        // don't cache failures (e.g. an expired proxy session) — surface and retry next time
        return rest_ensure_response(array('ok' => false, 'reason' => 'error', 'message' => $seasons->get_error_message(), 'seasons' => array()));
    }
    $payload = array('ok' => true, 'seasons' => $seasons);
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
        $cache = get_transient('edr_tb_roster2');
        if (is_array($cache) && isset($cache['ids'][$lookup])) {
            return rest_ensure_response(array('name' => $cache['ids'][$lookup]));
        }
        if (!get_transient('edr_tb_roster_recheck')) {
            set_transient('edr_tb_roster_recheck', 1, 10 * MINUTE_IN_SECONDS);
            $members = edr_g61_all_members($s['g61_token']);
            if (!is_wp_error($members)) {
                set_transient('edr_tb_roster2', $members, 6 * HOUR_IN_SECONDS);
                $cache = $members;
            }
        }
        return rest_ensure_response(array('name' => (is_array($cache) && isset($cache['ids'][$lookup])) ? $cache['ids'][$lookup] : null));
    }

    $fresh = $req->get_param('fresh') && edr_tb_req_can_edit($req);
    if (!$fresh) {
        $cache = get_transient('edr_tb_roster2');   // new key: shape changed to {names, ids} in 2.4.7
        if ($cache) return rest_ensure_response($cache);
    }
    $members = edr_g61_all_members($s['g61_token']);
    if (is_wp_error($members)) return $members;
    set_transient('edr_tb_roster2', $members, 6 * HOUR_IN_SECONDS);
    return rest_ensure_response($members);
}

function edr_tb_rest_auth(WP_REST_Request $req) {
    $s  = edr_tb_settings();
    $p  = (string) $req->get_param('pass');
    $ok = ($s['edit_pass'] !== '' && $p !== '' && hash_equals($s['edit_pass'], $p));
    if (!$ok) sleep(1); // slow down brute force a touch
    return rest_ensure_response(array('ok' => $ok));
}

function edr_tb_rest_avail_get() {
    $all    = get_option('edr_tb_avail', array());
    $prefs = get_option('edr_tb_prefs', array());
    return rest_ensure_response(array(
        'store'  => empty($all) ? new stdClass() : $all,
        'locked' => array(),   // advisory model (2.4.2): no hard per-device locks — see below
        'prefs'  => empty($prefs) ? new stdClass() : $prefs,
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
    $name = substr(sanitize_text_field((string) $req->get_param('name')), 0, 80);
    if ($name === '') return new WP_Error('bad_req', 'Need a driver name.', array('status' => 400));

    if ($req->get_param('release')) {
        return rest_ensure_response(array('ok' => true, 'released' => $name));   // no locks to release
    }

    $ev = substr(sanitize_text_field((string) $req->get_param('ev')), 0, 120);
    if ($ev === '') return new WP_Error('bad_req', 'Need ev.', array('status' => 400));

    $slots = array_slice(array_values(array_unique(array_filter(
        array_map('intval', (array) $req->get_param('slots')),
        function ($v) { return $v >= 0 && $v < 1000; }
    ))), 0, 500);
    $all = (array) get_option('edr_tb_avail', array());
    if (!isset($all[$ev]) || !is_array($all[$ev])) $all[$ev] = array();
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
    return rest_ensure_response(array('ok' => true, 'locked' => array()));
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

    // pure Garage 61 pace pull; availability is collected in-house (Availability tab)
    $roster = edr_g61_roster($s['g61_token'], $trackIds, $teamSlug);   // [{name, cars}]
    if (is_wp_error($roster)) return $roster;
    return rest_ensure_response(array('roster' => $roster, 'needs_availability' => false));
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
