<?php
/**
 * Plugin Name: EDR Team Builder
 * Description: Endurotech Racing endurance team + stint planner. Pulls Garage 61 pace and iRacePlan availability, builds Pro/Casual teams and stint rotations. Add the [edr_team_builder] shortcode to a page.
 * Version: 2.0.2
 * Author: Endurotech Racing
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit; // no direct access

define('EDR_TB_DIR', plugin_dir_path(__FILE__));
define('EDR_TB_URL', plugin_dir_url(__FILE__));
define('EDR_TB_VER', '2.0.2');

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
    ));
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
    $perm = function () { return is_user_logged_in(); }; // any logged-in member (admin gate removed)
    register_rest_route('edr/v1', '/tracks', array(
        'methods' => 'GET', 'permission_callback' => $perm, 'callback' => 'edr_tb_rest_tracks',
    ));
    register_rest_route('edr/v1', '/events', array(
        'methods' => 'GET', 'permission_callback' => $perm, 'callback' => 'edr_tb_rest_events',
    ));
    register_rest_route('edr/v1', '/import', array(
        'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'edr_tb_rest_import',
    ));
    // shared plan: any logged-in member can read and write
    register_rest_route('edr/v1', '/plan', array(
        array('methods' => 'GET',  'permission_callback' => 'is_user_logged_in', 'callback' => 'edr_tb_rest_plan_get'),
        array('methods' => 'POST', 'permission_callback' => $perm,               'callback' => 'edr_tb_rest_plan_set'),
    ));
});

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
