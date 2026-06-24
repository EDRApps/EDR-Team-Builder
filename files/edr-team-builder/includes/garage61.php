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

/* Returns [{name: <g61 slug or name>, cars: {carName: {laps, medianLap, cleanPct}}}] */
function edr_g61_roster($token, $trackIds, $teamSlug) {
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
        $name = !empty($drv['name']) ? $drv['name'] : (!empty($drv['slug']) ? $drv['slug'] : 'Unknown');
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
        $roster[] = array('name' => $name, 'cars' => $cs);
    }
    return $roster;
}
