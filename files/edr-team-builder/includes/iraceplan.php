<?php
if (!defined('ABSPATH')) exit;

/* iRacePlan API client: events, and availability via the plannings API.
   See files/iraceplan-api-notes.md. Survey responses are NOT in the API; the
   full multi-window availability comes from the bookmarklet scrape. The plannings
   API gives clean availability for sessions that already have a team planning. */

define('EDR_IRP_BASE', 'https://iraceplan.com/api/v1');

function edr_irp_get($path, $key) {
    $url = EDR_IRP_BASE . $path;
    $res = wp_remote_get($url, array(
        'timeout' => 45,
        'headers' => array('Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'),
    ));
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) return new WP_Error('irp_http', 'iRacePlan returned ' . $code . ' for ' . $path, array('status' => 502));
    return json_decode(wp_remote_retrieve_body($res), true);
}

function edr_irp_events($key) {
    $data = edr_irp_get('/surveys', $key);
    if (is_wp_error($data)) return $data;
    $out = array();
    foreach ((isset($data['surveys']) ? $data['surveys'] : array()) as $s) {
        $out[] = array(
            'id'             => intval($s['id']),
            'title'          => isset($s['title']) ? $s['title'] : ('Survey ' . $s['id']),
            'start_time'     => isset($s['start_time']) ? $s['start_time'] : '',
            'responses'      => isset($s['total_responses']) ? intval($s['total_responses']) : null,
            'drivers'        => isset($s['total_drivers']) ? intval($s['total_drivers']) : null,
        );
    }
    // newest first
    usort($out, function ($a, $b) { return strcmp($b['start_time'], $a['start_time']); });
    return $out;
}

/* Pick the survey whose start time is nearest to now (ties: upcoming first). */
function edr_irp_nearest_survey($key) {
    $evs = edr_irp_events($key);
    if (is_wp_error($evs) || empty($evs)) return 0;
    $now = time(); $best = 0; $bestd = PHP_INT_MAX;
    foreach ($evs as $e) {
        $t = strtotime($e['start_time']); if (!$t) continue;
        $d = abs($t - $now);
        if ($d < $bestd) { $bestd = $d; $best = $e['id']; }
    }
    return $best;
}

/* Returns event metadata + any plannings-based availability.
   { window_start, window_min, race_min, candidate_starts:[{n,iso,offset}],
     availability:[{name, windows_min:[[s,e]]}], needs_availability:bool } */
function edr_irp_event_detail($key, $surveyId) {
    $d = edr_irp_get('/surveys/' . $surveyId, $key);
    if (is_wp_error($d)) return $d;
    $sv = isset($d['survey']) ? $d['survey'] : $d;

    $sessions = isset($sv['session_times']) ? $sv['session_times'] : array();
    if (empty($sessions)) return new WP_Error('no_sessions', 'Survey has no session times.', array('status' => 400));

    // window starts at the earliest candidate session; ends at the latest session end
    $starts = array_map(function ($s) { return strtotime($s['start_at']); }, $sessions);
    $ends   = array_map(function ($s) { return strtotime($s['end_at']); }, $sessions);
    sort($starts); $winStart = $starts[0];
    $winEnd = max($ends);
    $raceMin = round((strtotime($sessions[0]['end_at']) - strtotime($sessions[0]['start_at'])) / 60);

    $cand = array();
    $i = 1;
    foreach ($sessions as $s) {
        $cand[] = array(
            'n'      => $i++,
            'iso'    => gmdate('c', strtotime($s['start_at'])),
            'offset' => round((strtotime($s['start_at']) - $winStart) / 60),
        );
    }

    // team iracing ids from the survey
    $teamIds = array();
    foreach ((isset($sv['teams']) ? $sv['teams'] : array()) as $t) {
        if (!empty($t['iracing_id'])) $teamIds[] = intval($t['iracing_id']);
    }

    // gather team plannings inside the event window, pull driver_availabilities
    $avail = array(); // name => [[s,e],...]
    $plannings = edr_irp_team_plannings($key, $teamIds, $winStart, $winEnd);
    foreach ($plannings as $pid) {
        $pd = edr_irp_get('/plannings/' . $pid, $key);
        if (is_wp_error($pd)) continue;
        $o = isset($pd['planning']) ? $pd['planning'] : $pd;
        foreach ((isset($o['driver_availabilities']) ? $o['driver_availabilities'] : array()) as $da) {
            $nm = isset($da['driver']['name']) ? $da['driver']['name'] : null;
            if (!$nm) continue;
            foreach ((isset($da['periods']) ? $da['periods'] : array()) as $p) {
                if (($p['status'] ?? '') !== 'available') continue;
                $s = round((strtotime($p['start_time']) - $winStart) / 60);
                $e = round((strtotime($p['end_time']) - $winStart) / 60);
                if ($e > $s) { if (!isset($avail[$nm])) $avail[$nm] = array(); $avail[$nm][] = array($s, $e); }
            }
        }
    }

    $availOut = array();
    foreach ($avail as $nm => $wins) {
        $availOut[] = array('name' => $nm, 'windows_min' => edr_merge_windows($wins));
    }

    return array(
        'window_start'     => gmdate('c', $winStart),
        'window_min'       => round(($winEnd - $winStart) / 60),
        'race_min'         => $raceMin,
        'candidate_starts' => $cand,
        'availability'     => $availOut,
        'needs_availability' => empty($availOut), // no planning data yet -> use bookmarklet
    );
}

function edr_irp_team_plannings($key, $teamIds, $winStart, $winEnd) {
    $ids = array();
    $offset = 0;
    do {
        $data = edr_irp_get('/plannings?limit=100&offset=' . $offset, $key);
        if (is_wp_error($data)) break;
        $batch = isset($data['plannings']) ? $data['plannings'] : array();
        foreach ($batch as $pl) {
            if (($pl['type'] ?? '') !== 'team') continue;
            $regId = isset($pl['registrable']['iracing_id']) ? intval($pl['registrable']['iracing_id']) : 0;
            $st = isset($pl['session']['start_at']) ? strtotime($pl['session']['start_at']) : 0;
            $inWindow = ($st >= $winStart - 3600 && $st <= $winEnd + 3600);
            if ($inWindow && (empty($teamIds) || in_array($regId, $teamIds, true))) $ids[] = intval($pl['id']);
        }
        $total = isset($data['meta']['total']) ? intval($data['meta']['total']) : count($batch);
        $offset += count($batch);
    } while (!empty($batch) && $offset < $total && $offset < 400);
    return $ids;
}

function edr_merge_windows($wins) {
    if (empty($wins)) return array();
    usort($wins, function ($a, $b) { return $a[0] - $b[0]; });
    $out = array($wins[0]);
    for ($i = 1; $i < count($wins); $i++) {
        $last = &$out[count($out) - 1];
        if ($wins[$i][0] <= $last[1]) { $last[1] = max($last[1], $wins[$i][1]); }
        else { $out[] = $wins[$i]; }
    }
    return $out;
}
