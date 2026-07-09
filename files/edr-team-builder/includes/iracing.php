<?php
if (!defined('ABSPATH')) exit;

/* iRacing Data API via a teammate's proxy. Same server-side pattern as garage61.php:
   the proxy handles auth + the expiring-S3-link hop, so we just GET and cache. The
   proxy runs off the owner's iRacing login; when it lapses every call returns
   invalid_grant/expired and only the owner can re-authenticate it. */

function edr_ir_get($base, $key, $path) {
    $base = rtrim($base, '/');
    $res = wp_remote_get($base . $path, array(
        'timeout' => 60,
        'headers' => array('Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'),
    ));
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    if ($code !== 200) {
        $expired = (stripos($body, 'invalid_grant') !== false || stripos($body, 'expired') !== false);
        return new WP_Error('ir_http', $expired
            ? 'The iRacing proxy session has expired. Ask the proxy owner to re-authenticate the bot, then refresh.'
            : ('iRacing proxy returned HTTP ' . $code . '.'), array('status' => 502));
    }
    return json_decode($body, true);
}

/**
 * Official seasons that currently expose race session start times, simplified for the
 * builder. iRacing only carries session_times for active/near-active weeks (special
 * events appear once they go active), so this returns the events you can actually plan
 * against right now; everything else falls back to the calendar defaults.
 *
 * Each item: {season_id, series_id, name, track, start_date, race_min, sessions:[iso...]}.
 */
function edr_ir_seasons($base, $key) {
    $data = edr_ir_get($base, $key, '/data/series/seasons?include_series=1');
    if (is_wp_error($data)) return $data;
    if (!is_array($data)) return array();
    $out = array();
    foreach ($data as $s) {
        if (empty($s['official'])) continue;
        $scheds = isset($s['schedules']) && is_array($s['schedules']) ? $s['schedules'] : array();
        foreach ($scheds as $wk) {
            $rtd = isset($wk['race_time_descriptors']) && is_array($wk['race_time_descriptors']) ? $wk['race_time_descriptors'] : array();
            $times = array();
            foreach ($rtd as $d) {
                if (!empty($d['session_times']) && is_array($d['session_times'])) { $times = $d['session_times']; break; }
            }
            if (!$times) continue;
            $tr = isset($wk['track']) && is_array($wk['track']) ? $wk['track'] : array();
            $out[] = array(
                'season_id'  => isset($s['season_id']) ? intval($s['season_id']) : 0,
                'series_id'  => isset($s['series_id']) ? intval($s['series_id']) : 0,
                'name'       => isset($s['season_name']) ? $s['season_name'] : '',
                'track'      => trim((isset($tr['track_name']) ? $tr['track_name'] : '') . ' ' . (isset($tr['config_name']) ? $tr['config_name'] : '')),
                'start_date' => isset($wk['start_date']) ? $wk['start_date'] : '',
                'race_min'   => isset($wk['race_time_limit']) ? intval($wk['race_time_limit']) : 0,
                'sessions'   => array_values($times),
                'weather'    => isset($wk['weather']) ? $wk['weather'] : null,
            );
            break; // one schedule per season is enough
        }
    }
    return $out;
}
