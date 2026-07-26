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
 * Build the recap. $members is custId => display name, from edr_g61_all_members().
 * Returns the shape the Weekly tab renders, or a WP_Error if the proxy is unusable.
 */
function edr_tb_recap_build($base, $key, $members, $from, $to) {
    if (!$members) return new WP_Error('no_members', 'No Garage 61 members with an iRacing ID.', array('status' => 400));

    // 1) which subsessions the squad raced — one search per driver
    $subs = array();
    $byCust = array();
    foreach ($members as $cust => $name) {
        $found = edr_tb_recap_search($base, $key, $cust, $from, $to);
        if (is_wp_error($found)) return $found;                 // proxy down or expired: fail loudly
        foreach ($found as $sid => $_) $subs[$sid] = true;
        $byCust[(string) $cust] = array(
            'name' => $name, 'races' => 0, 'wins' => 0, 'podiums' => 0, 'inc' => 0, 'dnf' => 0,
            'irDelta' => 0, 'irEnd' => null, 'srStart' => null, 'srEnd' => null, 'best' => null,
        );
        usleep(EDR_TB_RECAP_PACE);
    }
    if (!$subs) return array('at' => time(), 'from' => $from, 'to' => $to, 'drivers' => array(), 'races' => 0);

    // 2) each subsession once — team enduros overlap heavily, so dedupe first
    $ids = array_slice(array_keys($subs), 0, EDR_TB_RECAP_MAX_SUBS);
    $dropped = count($subs) - count($ids);
    foreach ($ids as $sid) {
        $sub = edr_ir_get($base, $key, '/data/results/get?subsession_id=' . intval($sid));
        usleep(EDR_TB_RECAP_PACE);
        if (is_wp_error($sub) || !is_array($sub)) continue;      // skip a bad one, keep the run
        foreach (edr_tb_recap_rows($sub) as $r) {
            $cust = isset($r['cust_id']) ? (string) intval($r['cust_id']) : '';
            if ($cust === '' || !isset($byCust[$cust])) continue;
            $d =& $byCust[$cust];
            $d['races']++;
            // finish positions are 0-based; prefer in-class where the payload gives it
            $pos = null;
            foreach (array('finish_position_in_class', '_class_pos', 'finish_position') as $k) {
                if (isset($r[$k]) && $r[$k] !== null) { $pos = intval($r[$k]); break; }
            }
            if ($pos !== null) {
                if ($pos === 0) $d['wins']++;
                if ($pos <= 2) $d['podiums']++;
                if ($d['best'] === null || $pos < $d['best']) $d['best'] = $pos;
            }
            if (isset($r['incidents'])) $d['inc'] += intval($r['incidents']);
            if (!empty($r['reason_out']) && strcasecmp((string) $r['reason_out'], 'Running') !== 0) $d['dnf']++;
            if (isset($r['oldi_rating'], $r['newi_rating']) && intval($r['oldi_rating']) > 0) {
                $d['irDelta'] += intval($r['newi_rating']) - intval($r['oldi_rating']);
                $d['irEnd']    = intval($r['newi_rating']);
            }
            if (isset($r['old_sub_level'], $r['new_sub_level'])) {
                if ($d['srStart'] === null) $d['srStart'] = intval($r['old_sub_level']);
                $d['srEnd'] = intval($r['new_sub_level']);
            }
            unset($d);
        }
    }

    // 3) shape it for the write-up — only drivers who actually raced
    $drivers = array();
    foreach ($byCust as $cust => $d) {
        if (!$d['races']) continue;
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
        'from'    => $from,
        'to'      => $to,
        'races'   => count($ids),
        'dropped' => $dropped,     // surfaced rather than silently truncating
        'drivers' => $drivers,
    );
}
