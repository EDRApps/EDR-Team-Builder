<?php
if (!defined('ABSPATH')) exit;

/* Garage 61 API client + roster pull. Mirrors files/colab_pull_garage61.py. */

define('EDR_G61_BASE', 'https://garage61.net/api/v1');

function edr_g61_get_paged($path, $params, $token) {
    $items = array();
    $offset = 0;
    do {
        $q = array_merge($params, array('limit' => 1000, 'offset' => $offset));
        $url = EDR_G61_BASE . $path . '?' . http_build_query($q);
        $res = wp_remote_get($url, array(
            'timeout' => 45,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
        ));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return new WP_Error('g61_http', 'Garage 61 returned ' . $code . '. Check the token / its driving_data scope.', array('status' => 502));
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $batch = isset($data['items']) ? $data['items'] : array();
        $items = array_merge($items, $batch);
        $total = isset($data['total']) ? intval($data['total']) : count($items);
        $offset += count($batch);
    } while (!empty($batch) && $offset < $total);
    return $items;
}

function edr_g61_get_json($path, $token) {
    $res = wp_remote_get(EDR_G61_BASE . $path, array(
        'timeout' => 45,
        'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
    ));
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) return new WP_Error('g61_http', 'Garage 61 returned ' . $code . ' for ' . $path, array('status' => 502));
    return json_decode(wp_remote_retrieve_body($res), true);
}

/* Unique member display names across EVERY Garage 61 team the token's account belongs to
   (all the EDR teams). Member shape varies a little between endpoints, so probe the
   common keys defensively. */
function edr_g61_all_members($token) {
    $teams = edr_g61_get_json('/teams', $token);
    if (is_wp_error($teams)) return $teams;
    $list = isset($teams['items']) && is_array($teams['items']) ? $teams['items'] : (is_array($teams) ? $teams : array());
    $names = array();
    foreach ($list as $t) {
        if (!is_array($t) || empty($t['slug'])) continue;
        $detail = edr_g61_get_json('/teams/' . rawurlencode($t['slug']), $token);
        if (is_wp_error($detail) || !is_array($detail)) continue;
        $members = array();
        foreach (array('members', 'drivers', 'users') as $k) {
            if (!empty($detail[$k]) && is_array($detail[$k])) { $members = $detail[$k]; break; }
        }
        foreach ($members as $m) {
            if (!is_array($m)) continue;
            $n = '';
            if (!empty($m['firstName']) || !empty($m['lastName'])) {
                $n = trim((isset($m['firstName']) ? $m['firstName'] : '') . ' ' . (isset($m['lastName']) ? $m['lastName'] : ''));
            } elseif (!empty($m['name'])) {
                $n = $m['name'];
            } elseif (!empty($m['driver']) && is_array($m['driver']) && !empty($m['driver']['name'])) {
                $n = $m['driver']['name'];
            }
            if ($n !== '') $names[$n] = true;
        }
    }
    $out = array_keys($names);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function edr_g61_tracks($token) {
    $tracks = edr_g61_get_paged('/tracks', array(), $token);
    if (is_wp_error($tracks)) return $tracks;
    $out = array();
    foreach ($tracks as $t) {
        $out[] = array(
            'id'      => isset($t['id']) ? intval($t['id']) : 0,
            'name'    => isset($t['name']) ? $t['name'] : '',
            'variant' => isset($t['variant']) ? $t['variant'] : '',
        );
    }
    usort($out, function ($a, $b) { return strcmp($a['name'] . $a['variant'], $b['name'] . $b['variant']); });
    return $out;
}

function edr_g61_median($arr) {
    if (empty($arr)) return null;
    sort($arr, SORT_NUMERIC);
    $n = count($arr);
    $mid = intdiv($n, 2);
    $m = ($n % 2) ? $arr[$mid] : ($arr[$mid - 1] + $arr[$mid]) / 2.0;
    return round($m, 3);
}

/* Mirror the JS nameKey(): fold diacritics to ASCII, lowercase, collapse spaces, apply aliases.
   Must match EDR-Team-Builder.html nameKey/NAME_ALIASES so the iRating join hits accented/alias drivers. */
function edr_tb_namekey($n) {
    $n = (string) $n;
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $n);
        if ($t !== false) $n = $t;
    } elseif (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $n);
        if ($t !== false) $n = $t;
    }
    $n = strtolower(trim(preg_replace('/\s+/', ' ', $n)));
    $aliases = array('joey tavora'=>'joseph tavora','matt halden'=>'matthew halden','zach martin'=>'zachary martin','chris wilson'=>'chris w','michael s cullen'=>'michael cullen');
    return isset($aliases[$n]) ? $aliases[$n] : $n;
}

/* Build a namekey => sports_car iRating map from every EDR team's membership (F8). */
function edr_g61_member_ratings($token) {
    $teams = edr_g61_get_json('/teams', $token);
    if (is_wp_error($teams)) return array();
    $list = isset($teams['items']) && is_array($teams['items']) ? $teams['items'] : (is_array($teams) ? $teams : array());
    $map = array();
    foreach ($list as $t) {
        if (!is_array($t) || empty($t['slug'])) continue;
        $detail = edr_g61_get_json('/teams/' . rawurlencode($t['slug']), $token);
        if (is_wp_error($detail) || !is_array($detail)) continue;
        $members = array();
        foreach (array('members','drivers','users') as $k) { if (!empty($detail[$k]) && is_array($detail[$k])) { $members = $detail[$k]; break; } }
        foreach ($members as $m) {
            if (!is_array($m)) continue;
            $n = trim((isset($m['firstName'])?$m['firstName']:'') . ' ' . (isset($m['lastName'])?$m['lastName']:''));
            if ($n === '' && !empty($m['name'])) $n = $m['name'];
            if ($n === '') continue;
            $ir = 0;
            $accts = isset($m['accounts']) && is_array($m['accounts']) ? $m['accounts'] : array();
            foreach ($accts as $ac) {
                if (!is_array($ac) || empty($ac['ratings']) || !is_array($ac['ratings'])) continue;
                foreach ($ac['ratings'] as $r) {
                    if (is_array($r) && isset($r['type']) && $r['type'] === 'irating'
                        && isset($r['category']) && $r['category'] === 'sports_car' && isset($r['rating'])) {
                        $ir = max($ir, intval($r['rating']));
                    }
                }
            }
            if ($ir > 0) { $k2 = edr_tb_namekey($n); if (empty($map[$k2]) || $ir > $map[$k2]) $map[$k2] = $ir; }
        }
    }
    return $map;
}

/* Returns [{name: <g61 slug or name>, cars: {carName: {laps, medianLap, cleanPct}}, irating}] */
function edr_g61_roster($token, $trackIds, $teamSlug) {
    $ratings = edr_g61_member_ratings($token);
    $laps = edr_g61_get_paged('/laps', array(
        'tracks'       => implode(',', array_map('intval', $trackIds)),
        'teams'        => $teamSlug,
        'unclean'      => 'true',
        'group'        => 'none',
        'age'          => '-1',
    ), $token);
    if (is_wp_error($laps)) return $laps;

    $bucket = array(); // name => car => ['clean'=>[], 'total'=>int]
    foreach ($laps as $lap) {
        $drv = isset($lap['driver']) && is_array($lap['driver']) ? $lap['driver'] : array();
        // display name from firstName+lastName (project convention) — the API's `name`
        // field is usually empty, and when set it is the raw iRacing name with a digit
        // suffix ("Sam Millar2") that never matches the roster
        $name = trim((isset($drv['firstName']) ? $drv['firstName'] : '') . ' ' . (isset($drv['lastName']) ? $drv['lastName'] : ''));
        if ($name === '') $name = !empty($drv['name']) ? $drv['name'] : (!empty($drv['slug']) ? $drv['slug'] : 'Unknown');
        $car  = isset($lap['car']) && !empty($lap['car']['name']) ? $lap['car']['name'] : 'Unknown car';
        if (!isset($bucket[$name])) $bucket[$name] = array();
        if (!isset($bucket[$name][$car])) $bucket[$name][$car] = array('clean' => array(), 'total' => 0);
        $bucket[$name][$car]['total']++;
        if (!empty($lap['clean']) && isset($lap['lapTime'])) $bucket[$name][$car]['clean'][] = floatval($lap['lapTime']);
    }

    $roster = array();
    ksort($bucket);
    foreach ($bucket as $name => $cars) {
        $cs = array();
        foreach ($cars as $car => $v) {
            $cs[$car] = array(
                'laps'      => $v['total'],
                'medianLap' => edr_g61_median($v['clean']),
                'cleanPct'  => $v['total'] ? round(count($v['clean']) / $v['total'], 3) : 0,
            );
        }
        $ir = isset($ratings[edr_tb_namekey($name)]) ? $ratings[edr_tb_namekey($name)] : null;
        $roster[] = array('name' => $name, 'cars' => $cs, 'irating' => $ir);
    }
    return $roster;
}
