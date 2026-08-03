<?php
if (!defined('ABSPATH')) exit;

/**
 * Team history: what EDR has actually done, per driver, per track and per series.
 *
 * This is the data the weekly write-up was missing. Until now the "who races this" figures came
 * from WEEKLY_SERIES (a one-off popularity report baked into the HTML) and the driver mentions came
 * from DRIVER_NOTES (hand-written personality lines), so every draft said the same thing about the
 * same people no matter what anybody had actually raced. TEAM_SERIES was the documented live slot
 * and was never filled in.
 *
 * Why this is cheap where the recap is expensive
 * ---------------------------------------------
 * The recap needs every EDR driver in a race it did not know about, so it must fetch each
 * subsession in full (team entries nest their drivers, and a teammate never shows up in your own
 * search). Per-driver history has no such problem: results/search_series already returns one row
 * per race FOR THE DRIVER SEARCHED, carrying the track, the series and that driver's own finish.
 * So this is one call per driver per window chunk — around 30 calls for a season, seconds not
 * minutes — and it never touches results/get at all.
 *
 * iRacing caps a search range at 90 days, hence the chunking; a season fits in a single chunk,
 * which is why EDR_TB_HIST_DEFAULT_DAYS matches the project's season-scoped pace convention.
 *
 * Field probing is deliberate. The proxy's exact row spelling is not something this code can
 * assume, so every field is probed across its known spellings and the run reports which ones
 * actually arrived (the `fields` key). A missing position field must degrade to "starts only"
 * rather than to a confident zero — "0 wins here" reads as a fact, and would be a lie.
 */

define('EDR_TB_HIST_PACE', 450000);        // microseconds between proxy calls, same as the recap
define('EDR_TB_HIST_CHUNK_DAYS', 80);      // search_series caps a range at 90 days; stay inside it
define('EDR_TB_HIST_MAX_CALLS', 300);      // backstop so a wide window cannot sweep forever
define('EDR_TB_HIST_DEFAULT_DAYS', 90);    // one iRacing season, matching the pace-pull convention (two search chunks)
define('EDR_TB_HIST_BUDGET', 10);          // seconds of proxy work per slice — short, so a tight host limit still clears one

/* Resumable state, mirroring the recap sweep (see edr_tb_recap_* in edr-team-builder.php). The
   monolithic edr_tb_hist_build() below still exists for local/CLI use, but in WordPress the build
   runs one bounded slice per request so a host that kills long requests can never stop it finishing:
   state persists after every proxy call, and the client's GET /history poll advances it. */
function edr_tb_hist_state()        { $s = get_option('edr_tb_hist_state', null); return is_array($s) ? $s : null; }
function edr_tb_hist_state_save($s) { update_option('edr_tb_hist_state', $s, false); }
function edr_tb_hist_state_clear()  { delete_option('edr_tb_hist_state'); }

function edr_tb_hist_progress($stage, $done = 0, $total = 0) {
    update_option('edr_tb_hist_progress', array(
        'stage' => $stage, 'done' => intval($done), 'total' => intval($total), 'at' => time(),
    ), false);
}

/**
 * Key a track so the same circuit lands in one bucket whatever configuration was run.
 *
 * Deliberately config-insensitive: "raced here" means the circuit, and splitting Spa Grand Prix
 * from Spa Endurance would halve every count for no gain. Accents are folded first because the
 * schedule and the results API disagree on Nurburgring/Nürburgring.
 */
function edr_tb_hist_track_key($name) {
    $s = (string) $name;
    if (function_exists('remove_accents')) $s = remove_accents($s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return (string) $s;
}

/**
 * Key a series the same way the client's seriesKey() does, so the two can be joined.
 *
 * Must stay in step with seriesKey() in EDR-Team-Builder.html: strip the season suffix
 * ("- 2026 Season 3") and the sponsor tail ("by CONSPIT"), then normalise. Exact match only —
 * "imsa iracing series" is a prefix of "imsa iracing series fixed", so a loose compare would
 * collapse the Fixed and open splits and credit the wrong drivers.
 */
function edr_tb_hist_series_key($name) {
    $s = (string) $name;
    if (function_exists('remove_accents')) $s = remove_accents($s);
    $s = preg_replace('/\s*[-–]\s*20\d\d\s*season\s*\d?.*$/i', '', $s);
    $s = preg_replace('/\s+by\s+[a-z0-9 .&\'-]+$/i', '', $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return (string) $s;
}

/** Tidy series name for display, mirroring prettySeriesName() on the client. */
function edr_tb_hist_series_label($name) {
    $s = preg_replace('/\s*[-–]\s*20\d\d\s*season\s*\d?.*$/i', '', (string) $name);
    $s = preg_replace('/\s*[-–]\s*20\d\d\s*$/', '', $s);
    return trim($s);
}

/** First present key out of several spellings, or null. */
function edr_tb_hist_pick($row, $keys) {
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') return $row[$k];
    }
    return null;
}

/**
 * One driver's official race rows in a window.
 *
 * Returns a list of normalised rows plus a note of which optional fields were present, so the
 * caller can tell "no wins" from "the payload never carried a position".
 */
function edr_tb_hist_search($base, $key, $cust_id, $from, $to) {
    $path = '/data/results/search_series?cust_id=' . rawurlencode($cust_id)
          . '&start_range_begin=' . rawurlencode($from) . '&start_range_end=' . rawurlencode($to);
    $rows = edr_ir_get($base, $key, $path);
    if (is_wp_error($rows)) return $rows;
    if (isset($rows['items']) && is_array($rows['items'])) $rows = $rows['items'];
    if (!is_array($rows)) return array('rows' => array(), 'sawPos' => false, 'sawInc' => false);

    $out = array(); $sawPos = false; $sawInc = false;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        // same two filters as the recap: races only, official only
        $type = isset($r['event_type_name']) ? (string) $r['event_type_name'] : '';
        if ($type !== '' && strcasecmp($type, 'Race') !== 0) continue;
        if (isset($r['official_session']) && !$r['official_session']) continue;

        $tr = edr_tb_hist_pick($r, array('track'));
        $trackLabel = '';
        if (is_array($tr)) {
            $trackLabel = function_exists('edr_ir_track_label') ? edr_ir_track_label($tr) : (string) edr_tb_hist_pick($tr, array('track_name'));
        } elseif ($tr !== null) {
            $trackLabel = (string) $tr;
        }

        $series = (string) edr_tb_hist_pick($r, array('series_name', 'season_name', 'series_short_name'));

        /* In-class position first: a GT3 driver who wins their class in a multi-class race has won,
           and overall position would call it a loss. 0-based, winner = 0. */
        $pos = edr_tb_hist_pick($r, array('finish_position_in_class', 'finish_position'));
        if ($pos !== null) $sawPos = true;
        $inc = edr_tb_hist_pick($r, array('incidents'));
        if ($inc !== null) $sawInc = true;

        $out[] = array(
            'sid'    => intval(edr_tb_hist_pick($r, array('subsession_id'))),
            'track'  => $trackLabel,
            'series' => $series,
            'pos'    => ($pos === null) ? null : intval($pos),
            'inc'    => ($inc === null) ? null : intval($inc),
            'at'     => (string) edr_tb_hist_pick($r, array('session_start_time', 'start_time')),
        );
    }
    return array('rows' => $out, 'sawPos' => $sawPos, 'sawInc' => $sawInc);
}

/** Fold one race into a running tally. */
function edr_tb_hist_tally(&$bucket, $row) {
    if (!isset($bucket['races'])) {
        $bucket = array('races' => 0, 'wins' => 0, 'podiums' => 0, 'top5' => 0, 'inc' => 0, 'best' => null);
    }
    $bucket['races']++;
    if ($row['pos'] !== null) {
        if ($row['pos'] === 0) $bucket['wins']++;
        if ($row['pos'] <= 2) $bucket['podiums']++;
        if ($row['pos'] <= 4) $bucket['top5']++;
        if ($bucket['best'] === null || $row['pos'] < $bucket['best']) $bucket['best'] = $row['pos'];
    }
    if ($row['inc'] !== null) $bucket['inc'] += $row['inc'];
}

/** 0-based position to a human ordinal, or '' when no position ever arrived. */
function edr_tb_hist_ordinal($pos) {
    if ($pos === null) return '';
    $n = intval($pos) + 1;
    $mod100 = $n % 100;
    if ($mod100 >= 11 && $mod100 <= 13) return $n . 'th';
    switch ($n % 10) {
        case 1: return $n . 'st';
        case 2: return $n . 'nd';
        case 3: return $n . 'rd';
    }
    return $n . 'th';
}

/**
 * Build the whole history. $members is custId => display name from edr_g61_all_members().
 *
 * Aggregates three ways off one pass, because the draft asks three different questions:
 *  - per track   -> "EDR here: 23 starts, 3 wins" on a round at that circuit
 *  - per series  -> who actually runs it, replacing the seeded WEEKLY_SERIES figures
 *  - per driver  -> the flare line, so a mention is earned rather than hand-written
 */
/** Fold one driver's search result set into the accumulators. Shared by step and build so the
 *  resumable and in-memory paths can never tally differently. */
function edr_tb_hist_fold(&$acc, $name, $res) {
    if (!empty($res['sawPos'])) $acc['sawPos'] = true;
    if (!empty($res['sawInc'])) $acc['sawInc'] = true;
    foreach ($res['rows'] as $row) {
        $acc['races']++;
        if (!isset($acc['drivers'][$name])) $acc['drivers'][$name] = array();
        edr_tb_hist_tally($acc['drivers'][$name], $row);

        if ($row['track'] !== '') {
            $tk = edr_tb_hist_track_key($row['track']);
            if (!isset($acc['tracks'][$tk])) $acc['tracks'][$tk] = array('label' => $row['track'], 'team' => array(), 'by' => array());
            edr_tb_hist_tally($acc['tracks'][$tk]['team'], $row);
            if (!isset($acc['tracks'][$tk]['by'][$name])) $acc['tracks'][$tk]['by'][$name] = array();
            edr_tb_hist_tally($acc['tracks'][$tk]['by'][$name], $row);
        }
        if ($row['series'] !== '') {
            $sk = edr_tb_hist_series_key($row['series']);
            if (!isset($acc['series'][$sk])) $acc['series'][$sk] = array('label' => edr_tb_hist_series_label($row['series']), 'team' => array(), 'by' => array());
            edr_tb_hist_tally($acc['series'][$sk]['team'], $row);
            if (!isset($acc['series'][$sk]['by'][$name])) $acc['series'][$sk]['by'][$name] = array();
            edr_tb_hist_tally($acc['series'][$sk]['by'][$name], $row);
        }
    }
}

/** Day-window split into <=EDR_TB_HIST_CHUNK_DAYS spans (search_series caps a range at 90 days). */
function edr_tb_hist_chunks($days, $now) {
    $end = $now; $start = $now - $days * DAY_IN_SECONDS;
    $chunks = array();
    for ($a = $start; $a < $end; $a += EDR_TB_HIST_CHUNK_DAYS * DAY_IN_SECONDS) {
        $b = min($end, $a + EDR_TB_HIST_CHUNK_DAYS * DAY_IN_SECONDS);
        $chunks[] = array(gmdate('Y-m-d\TH:i:s\Z', $a), gmdate('Y-m-d\TH:i:s\Z', $b));
    }
    return $chunks;
}

/** Empty accumulator plus the task list: one (custId, name, from, to) per driver per chunk. */
function edr_tb_hist_state_init($members, $days = 0) {
    $days   = $days > 0 ? intval($days) : EDR_TB_HIST_DEFAULT_DAYS;
    $chunks = edr_tb_hist_chunks($days, time());
    $todo   = array();
    foreach ($members as $cust => $name) {
        foreach ($chunks as $c) $todo[] = array((string) $cust, (string) $name, $c[0], $c[1]);
    }
    return array(
        'days' => $days, 'todo' => $todo, 'nTasks' => count($todo),
        'tracks' => array(), 'series' => array(), 'drivers' => array(),
        'sawPos' => false, 'sawInc' => false, 'calls' => 0, 'races' => 0, 'started' => time(),
    );
}

/** One bounded slice, banking state after every call. Returns 'working', 'done', or WP_Error. */
function edr_tb_hist_step($base, $key, &$st) {
    $deadline = time() + EDR_TB_HIST_BUDGET;
    while ($st['todo'] && time() < $deadline) {
        if ($st['calls'] >= EDR_TB_HIST_MAX_CALLS) { $st['todo'] = array(); break; }
        $task = array_shift($st['todo']);
        $res  = edr_tb_hist_search($base, $key, $task[0], $task[2], $task[3]);
        $st['calls']++;
        if (is_wp_error($res)) { edr_tb_hist_state_save($st); return $res; }   // proxy down/expired: fail loudly
        edr_tb_hist_fold($st, $task[1], $res);
        edr_tb_hist_state_save($st);
        usleep(EDR_TB_HIST_PACE);
        edr_tb_hist_progress('drivers', $st['nTasks'] - count($st['todo']), $st['nTasks']);
    }
    return $st['todo'] ? 'working' : 'done';
}

/**
 * Build the whole history in memory (local/CLI use). In WordPress the sweep runs through
 * edr_tb_hist_step() one slice at a time so a short host request limit cannot stop it — see
 * edr_tb_hist_advance() in edr-team-builder.php. $members is custId => display name.
 *
 * Aggregates three ways off one pass, because the draft asks three different questions:
 *  - per track   -> "EDR here: 23 starts, 3 wins" on a round at that circuit
 *  - per series  -> who actually runs it, replacing the seeded WEEKLY_SERIES figures
 *  - per driver  -> the flare line, so a mention is earned rather than hand-written
 */
function edr_tb_hist_build($base, $key, $members, $days = 0) {
    if (!$members) return new WP_Error('no_members', 'No Garage 61 members with an iRacing ID.', array('status' => 400));
    $st = edr_tb_hist_state_init($members, $days);
    while ($st['todo']) {
        $r = edr_tb_hist_step($base, $key, $st);
        if (is_wp_error($r)) return $r;
        if ($r === 'done') break;
    }
    return edr_tb_hist_shape($st);
}

/**
 * Shape accumulators into the client payload: per-bucket driver lists sorted by starts, so
 * "who races this" is ordered by who actually turns up rather than alphabetically.
 */
function edr_tb_hist_shape($st) {
    $shape = function ($group) {
        $out = array();
        foreach ($group as $k => $g) {
            $by = array();
            foreach ($g['by'] as $nm => $t) {
                $by[] = array(
                    'name' => $nm, 'races' => $t['races'], 'wins' => $t['wins'],
                    'podiums' => $t['podiums'], 'top5' => $t['top5'],
                    'best' => $t['best'], 'bestLabel' => edr_tb_hist_ordinal($t['best']),
                );
            }
            usort($by, function ($a, $b) {
                return ($b['races'] - $a['races']) ?: strcmp($a['name'], $b['name']);
            });
            $t = $g['team'];
            $out[$k] = array(
                'label'     => $g['label'],
                'races'     => $t['races'],
                'wins'      => $t['wins'],
                'podiums'   => $t['podiums'],
                'top5'      => $t['top5'],
                'inc'       => $t['inc'],
                'best'      => $t['best'],
                'bestLabel' => edr_tb_hist_ordinal($t['best']),
                'bestBy'    => count($by) ? $by[0]['name'] : '',
                'drivers'   => $by,
            );
        }
        return $out;
    };

    // whoever holds the best finish at a track is worth naming, and it is rarely the busiest driver
    $tracksOut = $shape($st['tracks']);
    foreach ($tracksOut as $k => $t) {
        $bestBy = ''; $best = null;
        foreach ($t['drivers'] as $d) {
            if ($d['best'] !== null && ($best === null || $d['best'] < $best)) { $best = $d['best']; $bestBy = $d['name']; }
        }
        $tracksOut[$k]['bestBy'] = $bestBy;
    }

    $driversOut = array();
    foreach ($st['drivers'] as $nm => $t) {
        $driversOut[] = array(
            'name' => $nm, 'races' => $t['races'], 'wins' => $t['wins'], 'podiums' => $t['podiums'],
            'top5' => $t['top5'], 'inc' => $t['inc'], 'best' => $t['best'],
            'bestLabel' => edr_tb_hist_ordinal($t['best']),
        );
    }
    usort($driversOut, function ($a, $b) { return ($b['races'] - $a['races']) ?: strcmp($a['name'], $b['name']); });

    return array(
        'at'      => time(),
        'days'    => intval($st['days']),
        'calls'   => intval($st['calls']),
        'races'   => intval($st['races']),
        'capped'  => (intval($st['calls']) >= EDR_TB_HIST_MAX_CALLS),
        /* What the payload actually carried. The draft checks this before printing a win or
           podium count, so a proxy that omits positions reads as "starts only" instead of
           quietly claiming nobody has ever won anything. */
        'fields'  => array('pos' => !empty($st['sawPos']), 'inc' => !empty($st['sawInc'])),
        'tracks'  => $tracksOut,
        'series'  => $shape($st['series']),
        'drivers' => $driversOut,
    );
}
