<?php
/**
 * Plugin Name: EDR Team Builder
 * Description: Endurotech Racing endurance team + stint planner. Pulls Garage 61 pace and iRacePlan availability, builds Pro/Casual teams and stint rotations. Add the [edr_team_builder] shortcode to a page.
 * Version: 2.1.1
 * Author: Endurotech Racing
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit; // no direct access

define('EDR_TB_DIR', plugin_dir_path(__FILE__));
define('EDR_TB_URL', plugin_dir_url(__FILE__));
define('EDR_TB_VER', '2.1.1');

require_once EDR_TB_DIR . 'includes/garage61.php';
require_once EDR_TB_DIR . 'includes/iraceplan.php';
require_once EDR_TB_DIR . 'includes/merge.php';

/* ----------------------------------------------------------------
 * Settings (one shared credential set, admin-only, server-side)
 * ---------------------------------------------------------------- */
function edr_tb_settings() {
    return wp_parse_args(get_option('edr_tb_settings', array()), array(
        'g61_token'  => '',
        'irp_key'    => '',
        'team_slug'  => 'edr-endurotech',
        'edit_pass'  => '',
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
            'g61_token' => sanitize_text_field($in['g61_token'] ?? ''),
            'irp_key'   => sanitize_text_field($in['irp_key'] ?? ''),
            'team_slug' => sanitize_text_field($in['team_slug'] ?? 'edr-endurotech'),
            'edit_pass' => sanitize_text_field($in['edit_pass'] ?? ''),
        );
    });
});

function edr_tb_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = edr_tb_settings();
    ?>
    <div class="wrap">
      <h1>EDR Team Builder</h1>
      <p>Credentials are stored on the server and used only to call Garage 61 and iRacePlan. They are never sent to visitors' browsers.</p>
      <form method="post" action="options.php">
        <?php settings_fields('edr_tb'); ?>
        <table class="form-table">
          <tr><th scope="row">Garage 61 API token</th>
            <td><input type="text" name="edr_tb_settings[g61_token]" value="<?php echo esc_attr($s['g61_token']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Garage 61 &rarr; My applications &rarr; API key (needs <code>driving_data</code>).</p></td></tr>
          <tr><th scope="row">iRacePlan API key</th>
            <td><input type="text" name="edr_tb_settings[irp_key]" value="<?php echo esc_attr($s['irp_key']); ?>" class="regular-text" autocomplete="off">
            <p class="description">iRacePlan &rarr; Settings &rarr; API Keys.</p></td></tr>
          <tr><th scope="row">Garage 61 team slug</th>
            <td><input type="text" name="edr_tb_settings[team_slug]" value="<?php echo esc_attr($s['team_slug']); ?>" class="regular-text"></td></tr>
          <tr><th scope="row">Builder admin password</th>
            <td><input type="text" name="edr_tb_settings[edit_pass]" value="<?php echo esc_attr($s['edit_pass']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Whoever knows this password can unlock admin mode in the builder (edit Drivers / Teams / Stints and run imports) without a WordPress account. Leave empty to require WordPress login.</p></td></tr>
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
    register_rest_route('edr/v1', '/events', array(
        'methods' => 'GET', 'permission_callback' => 'edr_tb_req_can_edit', 'callback' => 'edr_tb_rest_events',
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
});

function edr_tb_rest_roster(WP_REST_Request $req) {
    $s = edr_tb_settings();
    if (!$s['g61_token']) return rest_ensure_response(array());
    $fresh = $req->get_param('fresh') && edr_tb_req_can_edit($req);
    if (!$fresh) {
        $cache = get_transient('edr_tb_roster');
        if ($cache) return rest_ensure_response($cache);
    }
    $names = edr_g61_all_members($s['g61_token']);
    if (is_wp_error($names)) return $names;
    set_transient('edr_tb_roster', $names, 6 * HOUR_IN_SECONDS);
    return rest_ensure_response($names);
}

function edr_tb_rest_auth(WP_REST_Request $req) {
    $s  = edr_tb_settings();
    $p  = (string) $req->get_param('pass');
    $ok = ($s['edit_pass'] !== '' && $p !== '' && hash_equals($s['edit_pass'], $p));
    if (!$ok) sleep(1); // slow down brute force a touch
    return rest_ensure_response(array('ok' => $ok));
}

function edr_tb_rest_avail_get() {
    $all = get_option('edr_tb_avail', array());
    return rest_ensure_response(empty($all) ? new stdClass() : $all);
}

function edr_tb_rest_avail_set(WP_REST_Request $req) {
    $ev   = substr(sanitize_text_field((string) $req->get_param('ev')), 0, 120);
    $name = substr(sanitize_text_field((string) $req->get_param('name')), 0, 80);
    if ($ev === '' || $name === '') return new WP_Error('bad_req', 'Need ev and name.', array('status' => 400));
    $slots = array_slice(array_values(array_unique(array_filter(
        array_map('intval', (array) $req->get_param('slots')),
        function ($v) { return $v >= 0 && $v < 1000; }
    ))), 0, 500);
    $all = (array) get_option('edr_tb_avail', array());
    if (!isset($all[$ev]) || !is_array($all[$ev])) $all[$ev] = array();
    $all[$ev][$name] = $slots;
    update_option('edr_tb_avail', $all, false);
    return rest_ensure_response(array('ok' => true));
}

function edr_tb_rest_plan_get() {
    return rest_ensure_response(array(
        'plan'     => get_option('edr_tb_plan', null),
        'can_edit' => is_user_logged_in(),
        'updated'  => get_option('edr_tb_plan_updated', ''),
    ));
}
function edr_tb_rest_plan_set(WP_REST_Request $req) {
    update_option('edr_tb_plan', $req->get_param('plan'), false);
    update_option('edr_tb_plan_updated', current_time('mysql'), false);
    return rest_ensure_response(array('ok' => true));
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

function edr_tb_rest_events() {
    $s = edr_tb_settings();
    if (!$s['irp_key']) return new WP_Error('no_key', 'iRacePlan key not set in plugin settings.', array('status' => 400));
    $events = edr_irp_events($s['irp_key']);
    if (is_wp_error($events)) return $events;
    return rest_ensure_response($events);
}

function edr_tb_rest_import(WP_REST_Request $req) {
    $s = edr_tb_settings();
    if (!$s['g61_token'] || !$s['irp_key']) return new WP_Error('no_creds', 'Set the Garage 61 token and iRacePlan key in plugin settings.', array('status' => 400));
    $trackIds  = array_map('intval', (array) $req->get_param('trackIds'));
    $surveyId  = intval($req->get_param('surveyId'));
    if ($surveyId <= 0) { $surveyId = edr_irp_nearest_survey($s['irp_key']); } // auto-pick nearest event
    $teamSlug  = sanitize_text_field($req->get_param('teamSlug') ?: $s['team_slug']);
    $overrides = (array) $req->get_param('overrides'); // {surveyName: g61Slug}

    if (!$trackIds) return new WP_Error('no_track', 'Pick a track first.', array('status' => 400));

    $roster = edr_g61_roster($s['g61_token'], $trackIds, $teamSlug);   // [{name, cars}]
    if (is_wp_error($roster)) return $roster;

    $event = $surveyId ? edr_irp_event_detail($s['irp_key'], $surveyId) : null;   // {window_start, candidate_starts, availability?}
    if (is_wp_error($event)) return $event;

    $merged = edr_tb_merge($roster, $event, $overrides);  // {drivers, candidate_starts, window_start, needs_availability, matches}
    return rest_ensure_response($merged);
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
