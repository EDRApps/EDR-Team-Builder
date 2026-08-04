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
/**
 * Every scheduled week in a date window, for the weekly write-up.
 *
 * Kept deliberately separate from edr_ir_seasons(). That function only emits weeks that carry
 * session_times, and irMatchFor() picks the best-scoring entry out of it — feed it every week
 * of every season and it can settle on a sessionless one, at which point applyIrTiming() bails
 * and the official-times feature quietly stops working. Two shapes, two jobs, one HTTP call.
 *
 * Each item: {series, track, start_date, race_min, sessions:[iso...]}.
 */
/**
 * car_class_id => display name, from /data/carclass/get.
 *
 * The seasons payload only carries class IDs, so without this a write-up can say where a
 * series is racing but not what it is racing — which is most of what makes the line worth
 * reading ("the cup car is at Barcelona", not "series 12 is at Barcelona").
 */
/**
 * Readable track label. iRacing's config_name often repeats the tail of track_name, and naive
 * concatenation produced "St. Petersburg Grand Prix Grand Prix" and
 * "Circuit des 24 Heures du Mans 24 Heures du Mans" in the weekly write-up.
 */
function edr_ir_track_label($tr) {
    if (!is_array($tr)) return '';
    $name = trim((string) (isset($tr['track_name']) ? $tr['track_name'] : ''));
    $cfg  = trim((string) (isset($tr['config_name']) ? $tr['config_name'] : ''));
    // iRacing's results feed sends "N/A" as the config for tracks with no variant (Bathurst,
    // Long Beach…), while the schedule sends none — so a raw label diverges and the history line
    // fails to join. Treat "N/A" as no config so both sides land on the same name and key.
    if (strcasecmp($cfg, 'N/A') === 0) $cfg = '';
    if ($cfg === '' || $name === '' || strcasecmp($cfg, $name) === 0) return $name;
    if (stripos($name, $cfg) !== false) return $name;   // config already contained in the name
    return trim($name . ' ' . $cfg);
}

/**
 * car_class_id => display name, plus (by reference) each class's member car ids.
 *
 * $members is what lets a week's raw car list be named as a class: schedules[].race_week_cars
 * gives car ids only, and "GT3" reads better than the nine cars inside it.
 */
function edr_ir_car_classes($base, $key, &$members = null) {
    $members = array();
    $data = edr_ir_get($base, $key, '/data/carclass/get');
    if (is_wp_error($data) || !is_array($data)) return array();
    $rows = isset($data['car_classes']) && is_array($data['car_classes']) ? $data['car_classes'] : $data;
    $map = array();
    foreach ($rows as $c) {
        if (!is_array($c) || !isset($c['car_class_id'])) continue;
        // short_name is the one people actually say out loud ("GT3" over "GT3 Class")
        $n = '';
        foreach (array('short_name', 'name') as $k) {
            if (!empty($c[$k])) { $n = trim((string) $c[$k]); break; }
        }
        if ($n === '') continue;
        $k = (string) intval($c['car_class_id']);
        $map[$k] = $n;
        $ids = array();
        foreach ((isset($c['cars_in_class']) && is_array($c['cars_in_class'])) ? $c['cars_in_class'] : array() as $cic) {
            if (is_array($cic) && isset($cic['car_id'])) $ids[] = intval($cic['car_id']);
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        if ($ids) $members[$k] = $ids;
    }
    return $map;
}

/** Class names for a season, from whichever shape the payload happens to use. */
function edr_ir_season_cars($s, $classes) {
    $out = array();
    foreach ((isset($s['car_class_ids']) && is_array($s['car_class_ids'])) ? $s['car_class_ids'] : array() as $id) {
        $k = (string) intval($id);
        if (isset($classes[$k]) && !in_array($classes[$k], $out, true)) $out[] = $classes[$k];
    }
    if (!$out) {   // some seasons expose car_types instead of resolvable class ids
        foreach ((isset($s['car_types']) && is_array($s['car_types'])) ? $s['car_types'] : array() as $t) {
            $v = is_array($t) ? (isset($t['car_type']) ? $t['car_type'] : '') : (string) $t;
            $v = trim((string) $v);
            if ($v !== '' && !in_array($v, $out, true)) $out[] = strtoupper($v);
        }
    }
    return $out;
}

/**
 * The cars actually running in one scheduled week.
 *
 * `schedules[].race_week_cars` is the per-week authority and the only field that moves in a
 * rotating-car series. Reading only the season-level `car_class_ids` is what made Ring Meister
 * announce the same car every week of the season: that list is every car the season may use,
 * not this week's. Same field iracing-week-planner scrapes.
 *
 * Naming: prefer class names, because "GT3" beats the nine cars inside it. Take the season's
 * own classes that sit wholly inside the week's car list, largest first, and only trust the
 * result if between them they account for every car — otherwise the week is a rotation slice
 * rather than a class, and the car's own name is the honest answer.
 *
 * Returns array($names, $known). $known false means we had per-week car ids and could not turn
 * them into names — the one case where the answer really is unknown and the draft should say so.
 *
 * An **empty** race_week_cars is not that case. iRacing sends `[]` for every series that does not
 * vary its cars by week (verified against iracing-week-planner's own fixture), so the season list
 * is the authority there, not a guess. Flagging those put "please confirm car" on 13 of 14 series
 * and buried the one round it was meant to catch.
 */
function edr_ir_week_cars($wk, $seasonIds, $seasonCars, $classes, $members) {
    $ids = array(); $names = array();
    foreach ((isset($wk['race_week_cars']) && is_array($wk['race_week_cars'])) ? $wk['race_week_cars'] : array() as $c) {
        if (!is_array($c)) continue;
        if (isset($c['car_id'])) $ids[] = intval($c['car_id']);
        foreach (array('car_name_abbreviated', 'car_name', 'name') as $k) {
            if (!empty($c[$k])) {
                $n = trim((string) $c[$k]);
                if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
                break;
            }
        }
    }

    if ($ids) {
        $want = array_values(array_unique($ids));
        sort($want);
        // candidate classes: the season's own, restricted to those fully present this week
        $cand = array();
        foreach ($seasonIds as $cid) {
            $k = (string) intval($cid);
            if (!isset($members[$k]) || !isset($classes[$k])) continue;
            if (array_diff($members[$k], $want)) continue;
            $cand[] = array('name' => $classes[$k], 'ids' => $members[$k]);
        }
        usort($cand, function ($a, $b) { return count($b['ids']) - count($a['ids']); });
        $out = array(); $covered = array();
        foreach ($cand as $cl) {
            // a single-make class inside a class we have already named adds nothing to read
            if (!array_diff($cl['ids'], $covered)) continue;
            if (!in_array($cl['name'], $out, true)) $out[] = $cl['name'];
            $covered = array_values(array_unique(array_merge($covered, $cl['ids'])));
        }
        sort($covered);
        if ($out && $covered === $want) return array($out, true);
    }

    if ($names) return array($names, true);

    /* car_restrictions predates race_week_cars and still narrows some weeks by class. */
    $restrict = array();
    foreach ((isset($wk['car_restrictions']) && is_array($wk['car_restrictions'])) ? $wk['car_restrictions'] : array() as $cr) {
        if (is_array($cr) && !empty($cr['car_class_id'])) {
            $k = (string) intval($cr['car_class_id']);
            if (isset($classes[$k]) && !in_array($classes[$k], $restrict, true)) $restrict[] = $classes[$k];
        }
    }
    if ($restrict) return array($restrict, true);

    /* No per-week car ids at all — the series does not rotate cars and the season list is the
       authority (iRacing sends an empty race_week_cars for every non-rotating series, verified
       against the reference planner's data). Only a week that HAD per-week ids we could not turn
       into any name is genuinely unknown; that is the one case worth flagging. */
    return array($seasonCars, empty($ids));
}


/**
 * Absolute ISO timestamp from whatever the schedule gave us.
 *
 * race_time_descriptors carry the repeating start as a bare time of day ("13:00:00"), which is
 * only meaningful against the week's start_date. Full datetimes and already-ISO session times
 * pass straight through. Returns '' when there is nothing usable, so callers can tell.
 */
function edr_ir_abs_time($raw, $day) {
    $raw = trim((string) $raw);
    if ($raw === '') return '';
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $raw)) {          // bare time of day
        if ($day === '') return '';
        $t = strtotime($day . ' ' . $raw . ' UTC');
        return ($t === false) ? '' : gmdate('Y-m-d\TH:i:s\Z', $t);
    }
    $t = strtotime($raw);                                            // full datetime of some form
    return ($t === false) ? '' : gmdate('Y-m-d\TH:i:s\Z', $t);
}

function edr_ir_weeks_from($data, $from_ts, $to_ts, $classes = array(), $members = array()) {
    if (!is_array($data)) return array();
    $out = array();
    foreach ($data as $s) {
        if (empty($s['official'])) continue;
        $name = isset($s['season_name']) ? $s['season_name'] : '';
        $cars = edr_ir_season_cars($s, $classes);
        $seasonIds = (isset($s['car_class_ids']) && is_array($s['car_class_ids'])) ? $s['car_class_ids'] : array();
        $scheds = isset($s['schedules']) && is_array($s['schedules']) ? $s['schedules'] : array();
        $rounds = count($scheds);   // recurring series vs one-off special
        // a team event is worth calling out whatever its length — this is the API's own flag
        $teamEv = (isset($s['max_team_drivers']) && intval($s['max_team_drivers']) > 1);
        // discipline, for the Weekly tab's filters. iRacing split "road" into sports_car and
        // formula_car in 2024, so prefer the string and fall back to the legacy id.
        $cat = '';
        foreach (array('category', 'track_types') as $k) {
            if (!empty($s[$k])) {
                $v = $s[$k];
                if (is_array($v)) { $v = reset($v); if (is_array($v)) $v = reset($v); }
                $cat = strtolower(trim((string) $v));
                break;
            }
        }
        if ($cat === '' && isset($s['category_id'])) {
            $legacy = array(1 => 'oval', 2 => 'road', 3 => 'dirt_oval', 4 => 'dirt_road');
            $cid = intval($s['category_id']);
            $cat = isset($legacy[$cid]) ? $legacy[$cid] : '';
        }
        foreach ($scheds as $wk) {
            $sd = isset($wk['start_date']) ? strtotime((string) $wk['start_date'] . ' 00:00:00 UTC') : false;
            if ($sd === false || $sd < $from_ts || $sd >= $to_ts) continue;
            $tr = isset($wk['track']) && is_array($wk['track']) ? $wk['track'] : array();
            /* Two shapes here. A fixed-schedule round (endurance, specials) lists explicit
               session_times. A repeating sprint instead gives a first start plus an interval,
               which is what "on the :00, even hours" comes from.
               The repeating shape is the awkward one: the start arrives as a bare TIME OF DAY
               (first_session_time, "13:00:00") that only means something combined with the
               week's start_date. Probing only for a full datetime found nothing, so every
               sprint came back with no pattern at all. Probe the known spellings of both and
               keep the raw values so the tab can say what actually arrived. */
            /* One descriptor decides the whole pattern. Reading each field from whichever
               descriptor happened to carry it first could describe a schedule that exists in
               none of them — a repeat interval from the weekday entry with a first start from
               the weekend one. Descriptor [0] is the pattern (iracing-week-planner reads only
               that); look past it only when [0] carries neither shape. */
            $times = array(); $repeat = 0; $first = ''; $rawFirst = '';
            $pick = null;
            foreach ((isset($wk['race_time_descriptors']) && is_array($wk['race_time_descriptors'])) ? $wk['race_time_descriptors'] : array() as $d) {
                if (!is_array($d)) continue;
                if ($pick === null) $pick = $d;
                $usable = (!empty($d['session_times']) && is_array($d['session_times']))
                       || !empty($d['repeat_minutes']) || !empty($d['repeatMinutes']) || !empty($d['repeat_mins']);
                if ($usable) { $pick = $d; break; }
            }
            if (is_array($pick)) {
                if (!empty($pick['session_times']) && is_array($pick['session_times'])) $times = $pick['session_times'];
                foreach (array('repeat_minutes', 'repeatMinutes', 'repeat_mins') as $rk) {
                    if (!empty($pick[$rk])) { $repeat = intval($pick[$rk]); break; }
                }
                foreach (array('first_session_time', 'firstSessionTime', 'start_time', 'startTime') as $fk) {
                    if (!empty($pick[$fk])) { $rawFirst = (string) $pick[$fk]; break; }
                }
            }
            if ($rawFirst === '' && $times) $rawFirst = (string) $times[0];
            $first = edr_ir_abs_time($rawFirst, isset($wk['start_date']) ? (string) $wk['start_date'] : '');
            /* this week's cars, not the season's — see edr_ir_week_cars() */
            list($wkCars, $perWeekCars) = edr_ir_week_cars($wk, $seasonIds, $cars, $classes, $members);

            $out[] = array(
                'series'     => $name,
                'track'      => edr_ir_track_label($tr),
                'start_date' => isset($wk['start_date']) ? $wk['start_date'] : '',
                'race_min'   => isset($wk['race_time_limit']) ? intval($wk['race_time_limit']) : 0,
                // most ovals, the cup cars and Ring Meister are lap-limited, so the honest
                // length is a lap count — reading only race_time_limit dropped it entirely
                'race_laps'  => isset($wk['race_lap_limit']) ? intval($wk['race_lap_limit']) : 0,
                'week_num'   => isset($wk['race_week_num']) ? intval($wk['race_week_num']) : -1,
                'carsWeek'   => (bool) $perWeekCars,
                'sessions'   => array_values($times),
                'repeat'     => $repeat,
                'first'      => $first,
                'firstRaw'   => $rawFirst,   // what the API actually sent, for diagnosing a blank pattern
                'cars'       => array_values($wkCars),
                'rounds'     => $rounds,
                'team'       => $teamEv,
                'cat'        => $cat,
            );
        }
    }
    return $out;
}

/** Both shapes from one fetch — the weekly write-up and the timing matcher each need their own. */
function edr_ir_all($base, $key, $from_ts, $to_ts) {
    $data = edr_ir_get($base, $key, '/data/series/seasons?include_series=1');
    if (is_wp_error($data)) return $data;
    // second call, but the whole /iracing response is cached 12h so it is one lookup a day
    $members = array();
    $classes = edr_ir_car_classes($base, $key, $members);
    return array(
        'seasons' => edr_ir_seasons_from($data, $classes),
        'weeks'   => edr_ir_weeks_from($data, $from_ts, $to_ts, $classes, $members),
    );
}

function edr_ir_seasons($base, $key) {
    $data = edr_ir_get($base, $key, '/data/series/seasons?include_series=1');
    if (is_wp_error($data)) return $data;
    return edr_ir_seasons_from($data);
}

function edr_ir_seasons_from($data, $classes = array()) {
    if (!is_array($data)) return array();
    $out = array();
    foreach ($data as $s) {
        if (empty($s['official'])) continue;
        $seasonCars = edr_ir_season_cars($s, $classes);
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
                'track'      => edr_ir_track_label($tr),
                'start_date' => isset($wk['start_date']) ? $wk['start_date'] : '',
                'race_min'   => isset($wk['race_time_limit']) ? intval($wk['race_time_limit']) : 0,
                'sessions'   => array_values($times),
                'weather'    => isset($wk['weather']) ? $wk['weather'] : null,
                'cars'       => $seasonCars,
            );
            // no break: seasons like Creventic carry several rounds (Mugello, Spa, …) —
            // emit every week that has session_times so each round is matchable
        }
    }
    return $out;
}
