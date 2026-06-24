<?php
if (!defined('ABSPATH')) exit;

/* Assemble the import payload. The Garage 61 pace roster and the iRacePlan event
   metadata + availability are returned to the browser, which performs the
   name-match + builds the final driver objects (so matches can be reviewed and
   a pasted bookmarklet scrape can be merged in the same step). */

function edr_tb_merge($roster, $event, $overrides = array()) {
    $payload = array(
        'roster'             => $roster,            // [{name(slug), cars}]
        'overrides'          => (array) $overrides, // {irpName: g61Slug}
        'window_start'       => null,
        'window_min'         => null,
        'race_min'           => 360,
        'candidate_starts'   => array(),
        'availability'       => array(),            // [{name(irp), windows_min:[[s,e]]}]
        'needs_availability' => true,
    );
    if (is_array($event)) {
        foreach (array('window_start','window_min','race_min','candidate_starts','availability','needs_availability') as $k) {
            if (array_key_exists($k, $event)) $payload[$k] = $event[$k];
        }
    }
    return $payload;
}
