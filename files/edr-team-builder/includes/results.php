<?php
if (!defined('ABSPATH')) exit;

/**
 * Last week's official results, aggregated per driver, for the weekly write-up recap.
 *
 * This is the expensive one. It is a search per driver plus a fetch per unique subsession, on a
 * GET-only proxy that runs off one shared iRacing account and rate-limits. It must never run
 * inside a page request: edr_tb_recap_refresh() is fired from wp_schedule_single_event and the
 * result is cached in the edr_tb_recap option until the next run.
 *
 * Conventions that are easy to get wrong and are deliberate here:
 *  - finish positions are 0-BASED (winner = 0), so display adds one
 *  - results/search_series returns practice and qualifying too; only Race + official counts
 *  - team events nest the actual drivers under driver_results
 *  - Safety Rating sub-levels encode licence_class*1000 + SR*100, so 4217 = B 2.17
 */

define('EDR_TB_RECAP_PACE', 450000);   // microseconds between proxy calls — ~0.45s, below the limiter
define('EDR_TB_RECAP_MAX_SUBS', 400);  // hard ceiling on subsession fetches for one run

/**
 * Progress heartbeat.
 *
 * The sweep is minutes long and the loopback request running it can be killed by the host
 * mid-way. Without a heartbeat that failure is invisible: the running flag just sits there
 * until it expires and the tab polls an opaque spinner the whole time. Every step stamps
 * where it got to, so the UI can show real numbers and spot a job that has stopped moving.
 */
function edr_tb_recap_progress($stage, $done = 0, $total = 0) {
    update_option('edr_tb_recap_progress', array(
        'stage' => $stage, 'done' => intval($done), 'total' => intval($total), 'at' => time(),
    ), false);
}

/** Sub-level integer -> readable licence, e.g. 4217 => "B 2.17". */
function edr_tb_sr_label($sub) {
    $sub = intval($sub);
    if ($sub <= 0) return '';
    $classes = array(1 => 'R', 2 => 'D', 3 => 'C', 4 => 'B', 5 => 'A', 6 => 'P');
    $cls = intdiv($sub, 1000);
    $sr  = ($sub % 1000) / 100;
    $name = isset($classes[$cls]) ? $classes[$cls] : (string) $cls;
    return $name . ' ' . number_format($sr, 2);
}

/** Numeric SR for comparison: class carries far more weight than the decimal. */
function edr_tb_sr_value($sub) { return intval($sub); }

/**
 * Every official race subsession a driver started in the window.
 * Returns [subsession_id => true]; the heavy per-race detail comes later, once, per subsession.
 */
function edr_tb_recap_search($base, $key, $cust_id, $from, $to) {
    $path = '/data/results/search_series?cust_id=' . rawurlencode($cust_id)
          . '&start_range_begin=' . rawurlencode($from) . '&start_range_end=' . rawurlencode($to);
    $rows = edr_ir_get($base, $key, $path);
    if (is_wp_error($rows)) return $rows;
    // the proxy flattens the chunked search for us, but shapes vary — probe defensively
    if (isset($rows['items']) && is_array($rows['items'])) $rows = $rows['items'];
    if (!is_array($rows)) return array();
    $out = array();
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $type = isset($r['event_type_name']) ? (string) $r['event_type_name'] : '';
        if ($type !== '' && strcasecmp($type, 'Race') !== 0) continue;      // drop practice + qualifying
        if (isset($r['official_session']) && !$r['official_session']) continue;
        if (empty($r['subsession_id'])) continue;
        $out[intval($r['subsession_id'])] = true;
    }
    return $out;
}

/** Flatten a subsession into per-driver rows, including team entries. */
function edr_tb_recap_rows($sub) {
    $rows = array();
    foreach ((isset($sub['session_results']) && is_array($sub['session_results'])) ? $sub['session_results'] : array() as $sr) {
        if (!isset($sr['simsession_type_name']) || strcasecmp((string) $sr['simsession_type_name'], 'Race') !== 0) {
            // some payloads only carry the race sim-session; if the name is absent, keep going
            if (isset($sr['simsession_type_name'])) continue;
        }
        foreach ((isset($sr['results']) && is_array($sr['results'])) ? $sr['results'] : array() as $r) {
            if (!is_array($r)) continue;
            if (!empty($r['driver_results']) && is_array($r['driver_results'])) {
                foreach ($r['driver_results'] as $dr) {                      // team entry
                    if (is_array($dr)) $rows[] = $dr + array('_class_pos' => isset($r['finish_position_in_class']) ? $r['finish_position_in_class'] : null);
                }
            } else {
                $rows[] = $r;
            }
        }
    }
    return $rows;
}

/**
 * The sweep is RESUMABLE, and has to be.
 *
 * It used to run start-to-finish inside one wp_schedule_single_event loopback: ~30 driver searches
 * plus up to EDR_TB_RECAP_MAX_SUBS subsession fetches, all at EDR_TB_RECAP_PACE, which is minutes
 * in a single HTTP request. @set_time_limit(0) lifts PHP's own ceiling but nothing else's — the
 * webserver, PHP-FPM and any front proxy all have their own, and on shared hosting the request is
 * killed long before the sweep ends. The symptom is the stale-heartbeat message the Weekly tab
 * shows ("the host cut it off"), and on a host with a short limit the recap can never finish at all.
 *
 * So progress is persisted and each invocation does a bounded slice of work, then re-queues itself.
 * No single request runs longer than EDR_TB_RECAP_BUDGET, whatever the host's limit is.
 */
define('EDR_TB_RECAP_BUDGET', 10);      // seconds of work per invocation — short, so even a tight host limit clears one slice
define('EDR_TB_RECAP_MAX_STEPS', 300);  // backstop: refuse to re-queue forever on a pathological run

function edr_tb_recap_state() { return (array) get_option('edr_tb_recap_state', array()); }
function edr_tb_recap_state_save($st) { update_option('edr_tb_recap_state', $st, false); }
function edr_tb_recap_state_clear() { delete_option('edr_tb_recap_state'); }

/** Fresh per-driver tally. */
function edr_tb_recap_blank($name) {
    return array(
        'name' => $name, 'races' => 0, 'wins' => 0, 'podiums' => 0, 'inc' => 0, 'dnf' => 0,
        'irDelta' => 0, 'irEnd' => null, 'srStart' => null, 'srEnd' => null, 'best' => null,
    );
}

/** Start a sweep: just the plan, no proxy calls yet. */
function edr_tb_recap_state_init($members, $from, $to) {
    $byCust = array();
    foreach ($members as $cust => $name) $byCust[(string) $cust] = edr_tb_recap_blank($name);
    return array(
        'from' => (string) $from, 'to' => (string) $to,
        'phase' => 'search',
        'todo'  => array_map('strval', array_keys($members)),   // custIds still to search
        'subs'  => array(),                                     // sid => true, deduped
        'ids'   => array(),                                     // sids still to fetch
        'byCust' => $byCust,
        'nSubs' => 0, 'dropped' => 0, 'steps' => 0, 'started' => time(),
    );
}

/** Fold one subsession payload into the running tallies. */
function edr_tb_recap_fold($sub, &$byCust) {
    foreach (edr_tb_recap_rows($sub) as $row) {
        $cust = isset($row['cust_id']) ? (string) intval($row['cust_id']) : '';
        if ($cust === '' || !isset($byCust[$cust])) continue;
        $d =& $byCust[$cust];
        $d['races']++;
        // finish positions are 0-based; prefer in-class where the payload gives it
        $pos = null;
        foreach (array('finish_position_in_class', '_class_pos', 'finish_position') as $k) {
            if (isset($row[$k]) && $row[$k] !== null) { $pos = intval($row[$k]); break; }
        }
        if ($pos !== null) {
            if ($pos === 0) $d['wins']++;
            if ($pos <= 2) $d['podiums']++;
            if ($d['best'] === null || $pos < $d['best']) $d['best'] = $pos;
        }
        if (isset($row['incidents'])) $d['inc'] += intval($row['incidents']);
        if (!empty($row['reason_out']) && strcasecmp((string) $row['reason_out'], 'Running') !== 0) $d['dnf']++;
        if (isset($row['oldi_rating'], $row['newi_rating']) && intval($row['oldi_rating']) > 0) {
            $d['irDelta'] += intval($row['newi_rating']) - intval($row['oldi_rating']);
            $d['irEnd']    = intval($row['newi_rating']);
        }
        if (isset($row['old_sub_level'], $row['new_sub_level'])) {
            if ($d['srStart'] === null) $d['srStart'] = intval($row['old_sub_level']);
            $d['srEnd'] = intval($row['new_sub_level']);
        }
        unset($d);
    }
}

/**
 * Do one bounded slice. Returns 'working', 'done', or a WP_Error the caller should surface.
 * Mutates and saves $st itself so a mid-slice kill still leaves real progress behind.
 */
function edr_tb_recap_step($base, $key, &$st) {
    $deadline = time() + EDR_TB_RECAP_BUDGET;
    $st['steps'] = intval($st['steps']) + 1;
    if ($st['steps'] > EDR_TB_RECAP_MAX_STEPS) {
        return new WP_Error('too_many_steps', 'Results sweep did not converge; giving up rather than looping.');
    }

    // phase 1: one search per driver, collecting deduped subsession ids
    if ($st['phase'] === 'search') {
        $total = count($st['todo']) + count($st['byCust']) - count($st['todo']);   // for the heartbeat
        while ($st['todo'] && time() < $deadline) {
            $cust = array_shift($st['todo']);
            $found = edr_tb_recap_search($base, $key, $cust, $st['from'], $st['to']);
            if (is_wp_error($found)) { edr_tb_recap_state_save($st); return $found; }
            foreach ($found as $sid => $_) $st['subs'][(string) $sid] = true;
            edr_tb_recap_state_save($st);   // bank each driver: a mid-slice kill then loses one search, not the slice
            usleep(EDR_TB_RECAP_PACE);
            edr_tb_recap_progress('drivers', count($st['byCust']) - count($st['todo']), count($st['byCust']));
        }
        if (!$st['todo']) {
            // team enduros overlap heavily, so dedupe before spending a fetch each
            $all = array_keys($st['subs']);
            $st['ids']     = array_slice($all, 0, EDR_TB_RECAP_MAX_SUBS);
            $st['dropped'] = count($all) - count($st['ids']);
            $st['nSubs']   = count($st['ids']);
            $st['phase']   = 'races';
        }
        edr_tb_recap_state_save($st);
        return 'working';
    }

    // phase 2: each unique subsession once
    if ($st['phase'] === 'races') {
        while ($st['ids'] && time() < $deadline) {
            $sid = array_shift($st['ids']);
            $sub = edr_ir_get($base, $key, '/data/results/get?subsession_id=' . intval($sid));
            usleep(EDR_TB_RECAP_PACE);
            // a single bad subsession must not kill a sweep that is otherwise fine
            if (!is_wp_error($sub) && is_array($sub)) edr_tb_recap_fold($sub, $st['byCust']);
            edr_tb_recap_state_save($st);   // bank each subsession so a mid-slice kill keeps the tallies
            edr_tb_recap_progress('races', $st['nSubs'] - count($st['ids']), $st['nSubs']);
        }
        edr_tb_recap_state_save($st);
        return $st['ids'] ? 'working' : 'done';
    }

    return 'done';
}

/** Shape the finished tallies for the write-up — only drivers who actually raced. */
function edr_tb_recap_shape($st) {
    $drivers = array();
    foreach ((array) $st['byCust'] as $d) {
        if (empty($d['races'])) continue;
        $drivers[] = array(
            'name'    => $d['name'],
            'races'   => $d['races'],
            'wins'    => $d['wins'],
            'podiums' => $d['podiums'],
            'inc'     => $d['inc'],
            'dnf'     => $d['dnf'],
            'irDelta' => $d['irDelta'],
            'irEnd'   => $d['irEnd'],
            'srDelta' => ($d['srStart'] !== null && $d['srEnd'] !== null) ? ($d['srEnd'] - $d['srStart']) : 0,
            'srStart' => edr_tb_sr_label($d['srStart']),
            'srEnd'   => edr_tb_sr_label($d['srEnd']),
            'best'    => ($d['best'] === null) ? null : ($d['best'] + 1),   // 0-based -> human
        );
    }
    usort($drivers, function ($a, $b) { return $b['irDelta'] - $a['irDelta']; });

    return array(
        'at'      => time(),
        'from'    => $st['from'],
        'to'      => $st['to'],
        'races'   => intval($st['nSubs']),
        'dropped' => intval($st['dropped']),   // surfaced rather than silently truncating
        'drivers' => $drivers,
    );
}
