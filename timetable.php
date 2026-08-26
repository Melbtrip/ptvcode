<?php
// ─── Credentials ──────────────────────────────────────────────────────────────
$devId   = ;
$devKey  = "";
$baseUrl = "https://timetableapi.ptv.vic.gov.au";

// ─── Helpers ──────────────────────────────────────────────────────────────────
function ptvSign($path, $devId, $devKey) {
    $ts  = time();
    $req = $path . (strpos($path, '?') === false ? '?' : '&') . 'devid=' . $devId . '&timestamp=' . $ts;
    $sig = strtoupper(hash_hmac('sha1', $req, $devKey));
    return $req . '&signature=' . $sig;
}

function ptvUrl($path, $baseUrl, $devId, $devKey) {
    return $baseUrl . ptvSign($path, $devId, $devKey);
}

function ptvGet($path, $baseUrl, $devId, $devKey) {
    $url = $baseUrl . ptvSign($path, $devId, $devKey);
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err)         return array('error' => $err);
    if ($code != 200) return array('error' => 'HTTP ' . $code);
    $d = json_decode($body, true);
    return json_last_error() ? array('error' => 'JSON error') : $d;
}

function ptvMulti($urls, $timeout = 20) {
    $mh = curl_multi_init();
    $ch = array();
    foreach ($urls as $k => $url) {
        $c = curl_init($url);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($c, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($c, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($c, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        curl_multi_add_handle($mh, $c);
        $ch[$k] = $c;
    }
    $run = null;
    do { curl_multi_exec($mh, $run); curl_multi_select($mh); } while ($run > 0);
    $out = array();
    foreach ($ch as $k => $c) {
        $body = curl_multi_getcontent($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $c);
        curl_close($c);
        if ($code != 200 || !$body) { $out[$k] = array('error' => 'HTTP ' . $code); continue; }
        $d = json_decode($body, true);
        $out[$k] = json_last_error() ? array('error' => 'JSON error') : $d;
    }
    curl_multi_close($mh);
    return $out;
}

function ptvFullDayDepartures($rt, $stopId, $routeId, $anchorUtc, $eodTs, $baseUrl, $devId, $devKey) {
    $dateParam = $anchorUtc->format('Y-m-d\TH:i:s\Z');
    $path = '/v3/departures/route_type/' . $rt . '/stop/' . $stopId
          . ($routeId ? '/route/' . $routeId : '')
          . '?expand=run&max_results=200&date_utc=' . urlencode($dateParam);
    $page1 = ptvGet($path, $baseUrl, $devId, $devKey);
    if (isset($page1['error'])) return array(array(), array());
    $deps   = isset($page1['departures']) ? $page1['departures'] : array();
    $runMap = isset($page1['runs'])       ? $page1['runs']       : array();

    if (!empty($deps)) {
        $last    = end($deps);
        $lastSrc = isset($last['scheduled_departure_utc']) ? $last['scheduled_departure_utc'] : null;
        if ($lastSrc) {
            $lastDt = new DateTime($lastSrc, new DateTimeZone('UTC'));
            if ($lastDt->getTimestamp() < $eodTs) {
                $lastDt->modify('+1 minute');
                $path2 = '/v3/departures/route_type/' . $rt . '/stop/' . $stopId
                       . ($routeId ? '/route/' . $routeId : '')
                       . '?expand=run&max_results=200&date_utc=' . urlencode($lastDt->format('Y-m-d\TH:i:s\Z'));
                $page2 = ptvGet($path2, $baseUrl, $devId, $devKey);
                if (!isset($page2['error'])) {
                    $deps = array_merge($deps, isset($page2['departures']) ? $page2['departures'] : array());
                    if (isset($page2['runs'])) $runMap = array_merge($runMap, $page2['runs']);
                }
            }
        }
    }
    return array($deps, $runMap);
}

$rtNames = array(0 => 'Train', 1 => 'Tram', 2 => 'Bus', 3 => 'V/Line', 4 => 'Night Bus');
$tz      = new DateTimeZone('Australia/Melbourne');
$now     = new DateTime('now', $tz);

// ─── AJAX: search stops ───────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');
    $q  = isset($_GET['q'])  ? trim($_GET['q'])  : '';
    $rt = isset($_GET['rt']) ? (int)$_GET['rt']  : 0;
    if (strlen($q) < 2) { echo json_encode(array('stops' => array())); exit; }

    $data = ptvGet(
        '/v3/search/' . urlencode($q) . '?route_types=' . $rt . '&include_outlets=false',
        $baseUrl, $devId, $devKey
    );
    $stops = array();
    if (isset($data['stops'])) {
        foreach ($data['stops'] as $s) {
            $stops[] = array(
                'stop_id'   => (int)$s['stop_id'],
                'stop_name' => isset($s['stop_name']) ? trim($s['stop_name']) : 'Stop ' . $s['stop_id'],
                'rt'        => $rt,
                'suburb'    => isset($s['stop_suburb']) ? $s['stop_suburb'] : '',
            );
            if (count($stops) >= 10) break;
        }
    }
    echo json_encode(array('stops' => $stops));
    exit;
}

// ─── AJAX: search lines (routes) ───────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_lines') {
    header('Content-Type: application/json');
    $q  = isset($_GET['q'])  ? trim($_GET['q'])  : '';
    $rt = isset($_GET['rt']) ? (int)$_GET['rt']  : 0;
    if (strlen($q) < 1) { echo json_encode(array('lines' => array())); exit; }

    $data  = ptvGet('/v3/routes?route_types=' . $rt, $baseUrl, $devId, $devKey);
    $lines = array();
    if (isset($data['routes'])) {
        foreach ($data['routes'] as $r) {
            $name = isset($r['route_name'])   ? $r['route_name']   : '';
            $num  = isset($r['route_number']) ? $r['route_number'] : '';
            if (stripos($name, $q) === false && stripos((string)$num, $q) === false) continue;
            $lines[] = array(
                'route_id'     => (int)$r['route_id'],
                'route_name'   => $name,
                'route_number' => $num,
                'rt'           => $rt,
            );
            if (count($lines) >= 12) break;
        }
    }
    echo json_encode(array('lines' => $lines));
    exit;
}

// ─── AJAX: full-line timetable (all stops x all services for one line/date) ──
if (isset($_GET['action']) && $_GET['action'] === 'line_timetable') {
    header('Content-Type: application/json');

    $routeId = isset($_GET['route_id']) ? (int)$_GET['route_id'] : null;
    $rt      = isset($_GET['rt'])       ? (int)$_GET['rt']       : 0;
    $dateStr = isset($_GET['date'])     ? trim($_GET['date'])     : $now->format('Y-m-d');
    $lineName= isset($_GET['name'])     ? trim($_GET['name'])     : null;

    if (!$routeId) { echo json_encode(array('error' => 'Missing route_id')); exit; }
    if (!$lineName) $lineName = 'Route #' . $routeId;

    // Parse date
    $dateParts = explode('-', $dateStr);
    if (count($dateParts) !== 3) $dateParts = array((int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d'));
    $dayDt = new DateTime('now', $tz);
    $dayDt->setDate((int)$dateParts[0], (int)$dateParts[1], (int)$dateParts[2]);
    $dayDt->setTime(0, 0, 0);
    $anchorUtc = clone $dayDt; $anchorUtc->setTimezone(new DateTimeZone('UTC'));
    $eodDt = clone $dayDt; $eodDt->modify('+1 day'); $eodTs = $eodDt->getTimestamp();

    // Directions for this route
    $dirData    = ptvGet('/v3/directions/route/' . $routeId, $baseUrl, $devId, $devKey);
    $directions = array();
    if (isset($dirData['directions'])) {
        foreach ($dirData['directions'] as $d) {
            $directions[] = array(
                'direction_id' => (int)$d['direction_id'],
                'name'         => isset($d['direction_name']) ? $d['direction_name'] : ('Direction ' . $d['direction_id']),
            );
        }
    }
    if (empty($directions)) { echo json_encode(array('error' => 'No directions found for this line')); exit; }

    // Ordered stop sequence along the route
    $stopsData = ptvGet('/v3/stops/route/' . $routeId . '/route_type/' . $rt, $baseUrl, $devId, $devKey);
    $stopList  = array();
    if (isset($stopsData['stops'])) {
        foreach ($stopsData['stops'] as $s) {
            $stopList[] = array(
                'stop_id'       => (int)$s['stop_id'],
                'stop_name'     => isset($s['stop_name']) ? trim($s['stop_name']) : ('Stop ' . $s['stop_id']),
                'stop_sequence' => isset($s['stop_sequence']) ? (int)$s['stop_sequence']   : 0,
                'stop_distance' => isset($s['stop_distance']) ? (float)$s['stop_distance'] : 0.0,
            );
        }
        // stop_sequence from the PTV API is not reliably in geographic order along the line,
        // but stop_distance (distance travelled along the route) is — sort by that primarily,
        // falling back to stop_sequence only to break ties (e.g. co-located stops).
        usort($stopList, function($a, $b) {
            if ($a['stop_distance'] != $b['stop_distance']) {
                return ($a['stop_distance'] < $b['stop_distance']) ? -1 : 1;
            }
            return $a['stop_sequence'] - $b['stop_sequence'];
        });
    }
    if (empty($stopList)) { echo json_encode(array('error' => 'No stops found for this line')); exit; }

    $firstStopId = $stopList[0]['stop_id'];
    $lastStopId  = $stopList[count($stopList) - 1]['stop_id'];

    // Collect every run scheduled that day by checking departures from both termini
    $runMeta = array(); // run_ref => direction_id, dest, express
    foreach (array($firstStopId, $lastStopId) as $originStopId) {
        list($deps, $runMap) = ptvFullDayDepartures($rt, $originStopId, $routeId, $anchorUtc, $eodTs, $baseUrl, $devId, $devKey);
        foreach ($deps as $d) {
            $sched = isset($d['scheduled_departure_utc']) ? $d['scheduled_departure_utc'] : null;
            if (!$sched) continue;
            $schedDt    = new DateTime($sched, new DateTimeZone('UTC'));
            $schedLocal = clone $schedDt; $schedLocal->setTimezone($tz);
            if ($schedLocal->format('Y-m-d') !== $dayDt->format('Y-m-d')) continue;

            $rref  = isset($d['run_ref'])      ? $d['run_ref']         : null;
            $dirId = isset($d['direction_id']) ? (int)$d['direction_id'] : null;
            if (!$rref || $dirId === null) continue;

            if (!isset($runMeta[$rref])) {
                $dest = null; $express = 0;
                if (isset($runMap[$rref])) {
                    $dest    = isset($runMap[$rref]['destination_name'])   ? trim($runMap[$rref]['destination_name']) : null;
                    $express = isset($runMap[$rref]['express_stop_count']) ? (int)$runMap[$rref]['express_stop_count'] : 0;
                }
                $runMeta[$rref] = array('direction_id' => $dirId, 'dest' => $dest, 'express' => $express);
            }
        }
    }

    if (empty($runMeta)) {
        echo json_encode(array(
            'line_name' => $lineName, 'rt' => $rt,
            'date' => $dayDt->format('Y-m-d'), 'date_label' => $dayDt->format('l, j F Y'),
            'stops' => $stopList, 'directions' => $directions, 'runs' => array(), 'total' => 0
        ));
        exit;
    }

    // Fetch the stopping pattern (times at every stop) for every run, in parallel
    $patUrls = array();
    foreach (array_keys($runMeta) as $rref) {
        $patUrls['run_' . $rref] = ptvUrl('/v3/pattern/run/' . $rref . '/route_type/' . $rt . '?expand=stop', $baseUrl, $devId, $devKey);
    }
    $patResponses = ptvMulti($patUrls, 20);

    $runs = array();
    foreach ($patResponses as $k => $data) {
        if (isset($data['error'])) continue;
        $rref = substr($k, 4);
        $meta = isset($runMeta[$rref]) ? $runMeta[$rref] : array('direction_id' => null, 'dest' => null, 'express' => 0);

        $stopTimes = array();
        $originTs  = null;
        foreach ((isset($data['departures']) ? $data['departures'] : array()) as $dep) {
            $sid   = isset($dep['stop_id']) ? (int)$dep['stop_id'] : 0;
            $sched = isset($dep['scheduled_departure_utc']) ? $dep['scheduled_departure_utc'] : null;
            $est   = isset($dep['estimated_departure_utc'])  ? $dep['estimated_departure_utc']  : null;
            if (!$sched || !$sid) continue;

            $sDt = new DateTime($sched, new DateTimeZone('UTC')); $sDt->setTimezone($tz);
            $ts  = $sDt->getTimestamp();
            $eFmt = null; $delay = null;
            if ($est) {
                $eDt   = new DateTime($est, new DateTimeZone('UTC'));
                $delay = (int)round(($eDt->getTimestamp() - $ts) / 60);
                $eDt->setTimezone($tz);
                $eFmt = $eDt->format('H:i');
            }
            if ($originTs === null || $ts < $originTs) $originTs = $ts;

            $stopTimes[$sid] = array(
                'sched'      => $sDt->format('H:i'),
                'est'        => $eFmt,
                'delay_mins' => $delay,
                'platform'   => (isset($dep['platform_number']) && $dep['platform_number'] !== '') ? $dep['platform_number'] : null,
                'at_plat'    => isset($dep['at_platform']) ? (bool)$dep['at_platform'] : false,
                'ts'         => $ts,
            );
        }
        if (empty($stopTimes) || $originTs === null) continue;

        $runs[] = array(
            'run_ref'      => $rref,
            'direction_id' => $meta['direction_id'],
            'dest'         => $meta['dest'],
            'express'      => $meta['express'],
            'origin_ts'    => $originTs,
            'stops'        => $stopTimes,
        );
    }

    usort($runs, function($a, $b) { return $a['origin_ts'] - $b['origin_ts']; });

    echo json_encode(array(
        'line_name'  => $lineName,
        'rt'         => $rt,
        'date'       => $dayDt->format('Y-m-d'),
        'date_label' => $dayDt->format('l, j F Y'),
        'stops'      => $stopList,
        'directions' => $directions,
        'runs'       => $runs,
        'total'      => count($runs),
    ));
    exit;
}

// ─── AJAX: full day timetable ─────────────────────────────────────────────────
// Strategy: fetch up to 200 departures anchored to midnight of the requested date,
// then page once more if the last result is still before end-of-day.
// Group by route+direction, sort within each group by time.
if (isset($_GET['action']) && $_GET['action'] === 'timetable') {
    header('Content-Type: application/json');

    $stopId    = isset($_GET['stop_id'])   ? (int)$_GET['stop_id']   : null;
    $rt        = isset($_GET['rt'])        ? (int)$_GET['rt']        : 0;
    $dateStr   = isset($_GET['date'])      ? trim($_GET['date'])      : $now->format('Y-m-d');
    $dirFilter = isset($_GET['direction']) ? (int)$_GET['direction']  : -1; // -1 = all

    if (!$stopId) { echo json_encode(array('error' => 'Missing stop_id')); exit; }

    // Validate / parse date
    $dateParts = explode('-', $dateStr);
    if (count($dateParts) !== 3) $dateParts = array((int)$now->format('Y'), (int)$now->format('m'), (int)$now->format('d'));
    $dayDt = new DateTime('now', $tz);
    $dayDt->setDate((int)$dateParts[0], (int)$dateParts[1], (int)$dateParts[2]);
    $dayDt->setTime(0, 0, 0);

    // Anchor UTC time = midnight Melbourne that day
    $anchorUtc = clone $dayDt;
    $anchorUtc->setTimezone(new DateTimeZone('UTC'));

    // End of day = next midnight minus 1 sec
    $eodDt = clone $dayDt;
    $eodDt->modify('+1 day');
    $eodTs = $eodDt->getTimestamp();

    // Fetch departures — two passes to cover full day (PTV caps at ~200 per call)
    $allDeps = array();
    $dateParam = $anchorUtc->format('Y-m-d\TH:i:s\Z');

    $page1 = ptvGet(
        '/v3/departures/route_type/' . $rt . '/stop/' . $stopId
        . '?expand=run&expand=route&expand=stop'
        . '&max_results=200'
        . '&date_utc=' . urlencode($dateParam),
        $baseUrl, $devId, $devKey
    );
    if (isset($page1['error'])) { echo json_encode(array('error' => $page1['error'])); exit; }

    $allDeps    = isset($page1['departures']) ? $page1['departures'] : array();
    $routeMap   = isset($page1['routes'])     ? $page1['routes']     : array();
    $runMap     = isset($page1['runs'])       ? $page1['runs']       : array();
    $stopsObj   = isset($page1['stops'])      ? $page1['stops']      : array();

    // If the last departure is still within the day, fetch another page
    if (!empty($allDeps)) {
        $last = end($allDeps);
        $lastSrc = isset($last['scheduled_departure_utc']) ? $last['scheduled_departure_utc'] : null;
        if ($lastSrc) {
            $lastDt = new DateTime($lastSrc, new DateTimeZone('UTC'));
            if ($lastDt->getTimestamp() < $eodTs) {
                // Fetch from the last departure time onwards
                $lastDt->modify('+1 minute');
                $page2 = ptvGet(
                    '/v3/departures/route_type/' . $rt . '/stop/' . $stopId
                    . '?expand=run&expand=route&expand=stop'
                    . '&max_results=200'
                    . '&date_utc=' . urlencode($lastDt->format('Y-m-d\TH:i:s\Z')),
                    $baseUrl, $devId, $devKey
                );
                if (!isset($page2['error'])) {
                    $p2deps = isset($page2['departures']) ? $page2['departures'] : array();
                    $allDeps = array_merge($allDeps, $p2deps);
                    if (isset($page2['routes'])) $routeMap = array_merge($routeMap, $page2['routes']);
                    if (isset($page2['runs']))   $runMap   = array_merge($runMap,   $page2['runs']);
                }
            }
        }
    }

    // Resolve station name
    $stationName = 'Stop #' . $stopId;
    foreach ($stopsObj as $k => $sv) {
        if ((string)$k === (string)$stopId && isset($sv['stop_name'])) {
            $stationName = $sv['stop_name']; break;
        }
    }
    if ($stationName === 'Stop #' . $stopId) {
        foreach ($stopsObj as $sv) {
            if (isset($sv['stop_id'], $sv['stop_name']) && (int)$sv['stop_id'] === $stopId) {
                $stationName = $sv['stop_name']; break;
            }
        }
    }

    // Index routeMap (keyed by route_id as int or string)
    $routeIdx = array();
    foreach ($routeMap as $rid => $rv) {
        $routeIdx[(int)$rid] = array(
            'number' => isset($rv['route_number']) && $rv['route_number'] !== '' ? $rv['route_number'] : null,
            'name'   => isset($rv['route_name'])   ? $rv['route_name']   : null,
        );
    }

    // Index runMap
    $runIdx = array();
    foreach ($runMap as $rref => $rv) {
        $runIdx[$rref] = array(
            'dest'    => isset($rv['destination_name']) ? trim($rv['destination_name']) : null,
            'express' => isset($rv['express_stop_count']) ? (int)$rv['express_stop_count'] : 0,
        );
    }

    // Fetch direction names for all routes in parallel
    $rids = array();
    foreach ($allDeps as $d) {
        if (isset($d['route_id'])) $rids[(int)$d['route_id']] = true;
    }
    $dirUrls = array();
    foreach (array_keys($rids) as $rid) {
        $dirUrls['dir_' . $rid] = ptvUrl('/v3/directions/route/' . $rid, $baseUrl, $devId, $devKey);
    }
    $dirResponses = !empty($dirUrls) ? ptvMulti($dirUrls, 15) : array();
    $dirMap = array();
    foreach ($dirResponses as $k => $data) {
        if (isset($data['error'])) continue;
        $rid = (int)substr($k, 4);
        if (isset($data['directions'])) {
            foreach ($data['directions'] as $dir) {
                if (isset($dir['direction_id'], $dir['direction_name']))
                    $dirMap[$rid][$dir['direction_id']] = $dir['direction_name'];
            }
        }
    }

    // Build flat list of departures within the requested day
    $dayStart = $dayDt->getTimestamp();
    $groups   = array(); // key: "routeId_directionId" => array(meta, services[])

    foreach ($allDeps as $d) {
        $sched  = isset($d['scheduled_departure_utc']) ? $d['scheduled_departure_utc'] : null;
        $est    = isset($d['estimated_departure_utc'])  ? $d['estimated_departure_utc']  : null;
        if (!$sched) continue;

        $schedDt = new DateTime($sched, new DateTimeZone('UTC'));
        $ts      = $schedDt->getTimestamp();

        // Filter to the requested day (Melbourne time)
        $schedLocal = clone $schedDt;
        $schedLocal->setTimezone($tz);
        $localDateStr = $schedLocal->format('Y-m-d');
        if ($localDateStr !== $dayDt->format('Y-m-d')) continue;

        $rid   = isset($d['route_id'])     ? (int)$d['route_id']  : null;
        $dirId = isset($d['direction_id']) ? (int)$d['direction_id'] : null;
        $rref  = isset($d['run_ref'])      ? $d['run_ref']         : null;

        // Apply direction filter
        if ($dirFilter >= 0 && $dirId !== $dirFilter) continue;

        $platNo = (isset($d['platform_number']) && $d['platform_number'] !== '') ? $d['platform_number'] : null;
        $atPlat = isset($d['at_platform']) ? (bool)$d['at_platform'] : false;

        $dest = null;
        if ($rref && isset($runIdx[$rref]['dest']) && $runIdx[$rref]['dest'])
            $dest = $runIdx[$rref]['dest'];
        elseif ($rid !== null && $dirId !== null && isset($dirMap[$rid][$dirId]))
            $dest = $dirMap[$rid][$dirId];

        $express = ($rref && isset($runIdx[$rref]['express'])) ? $runIdx[$rref]['express'] : 0;

        $estFmt = null;
        if ($est) {
            $estDt = new DateTime($est, new DateTimeZone('UTC'));
            $estDt->setTimezone($tz);
            $estFmt = $estDt->format('H:i');
        }

        $delayMins = null;
        if ($est) {
            $estDt2 = new DateTime($est, new DateTimeZone('UTC'));
            $delayMins = (int)round(($estDt2->getTimestamp() - $ts) / 60);
        }

        // Group key
        $gkey = ($rid !== null ? $rid : 'x') . '_' . ($dirId !== null ? $dirId : 'x');

        if (!isset($groups[$gkey])) {
            $routeNum  = ($rid !== null && isset($routeIdx[$rid]['number'])) ? $routeIdx[$rid]['number'] : null;
            $routeName = ($rid !== null && isset($routeIdx[$rid]['name']))   ? $routeIdx[$rid]['name']   : null;
            $dirName   = ($rid !== null && $dirId !== null && isset($dirMap[$rid][$dirId])) ? $dirMap[$rid][$dirId] : null;

            $groups[$gkey] = array(
                'route_id'     => $rid,
                'route_number' => $routeNum,
                'route_name'   => $routeName,
                'direction_id' => $dirId,
                'direction'    => $dirName,
                'services'     => array(),
            );
        }

        $groups[$gkey]['services'][] = array(
            'sched'      => $schedLocal->format('H:i'),
            'est'        => $estFmt,
            'delay_mins' => $delayMins,
            'platform'   => $platNo,
            'at_plat'    => $atPlat,
            'dest'       => $dest,
            'express'    => $express,
            'ts'         => $ts,
            'run_ref'    => $rref,
            'is_past'    => ($ts < $now->getTimestamp()),
        );
    }

    // Sort services within each group by scheduled time
    foreach ($groups as $gk => $g) {
        usort($groups[$gk]['services'], function($a, $b) {
            return $a['ts'] - $b['ts'];
        });
    }

    // Sort groups: by route_number numerically, then name
    $groupList = array_values($groups);
    usort($groupList, function($a, $b) {
        $na = $a['route_number']; $nb = $b['route_number'];
        if ($na !== null && $nb !== null) {
            $ia = (int)$na; $ib = (int)$nb;
            if ($ia !== $ib) return $ia - $ib;
        }
        $sa = ($a['route_name'] ? $a['route_name'] : '') . ($a['direction'] ? $a['direction'] : '');
        $sb = ($b['route_name'] ? $b['route_name'] : '') . ($b['direction'] ? $b['direction'] : '');
        return strcmp($sa, $sb);
    });

    // Build direction index for the filter UI
    $directions = array();
    foreach ($allDeps as $d) {
        $rid   = isset($d['route_id'])     ? (int)$d['route_id']    : null;
        $dirId = isset($d['direction_id']) ? (int)$d['direction_id'] : null;
        if ($rid === null || $dirId === null) continue;
        $dirKey = $rid . '_' . $dirId;
        if (!isset($directions[$dirKey]) && isset($dirMap[$rid][$dirId])) {
            $routeNum  = ($rid !== null && isset($routeIdx[$rid]['number'])) ? $routeIdx[$rid]['number'] : null;
            $directions[$dirKey] = array(
                'direction_id' => $dirId,
                'name'         => $dirMap[$rid][$dirId],
                'route_id'     => $rid,
                'route_number' => $routeNum,
            );
        }
    }
    $directions = array_values($directions);

    $totalServices = 0;
    foreach ($groupList as $g) $totalServices += count($g['services']);

    echo json_encode(array(
        'station'    => $stationName,
        'rt'         => $rt,
        'date'       => $dayDt->format('Y-m-d'),
        'date_label' => $dayDt->format('l, j F Y'),
        'groups'     => $groupList,
        'directions' => $directions,
        'total'      => $totalServices,
    ));
    exit;
}

// ─── AJAX: stopping pattern for a single run ──────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'pattern') {
    header('Content-Type: application/json');
    $runRef = isset($_GET['run_ref'])  ? trim($_GET['run_ref']) : null;
    $rt     = isset($_GET['rt'])       ? (int)$_GET['rt']      : 0;
    $stopId = isset($_GET['stop_id'])  ? (int)$_GET['stop_id'] : 0;
    if (!$runRef) { echo json_encode(array('error' => 'Missing run_ref')); exit; }

    $data = ptvGet(
        '/v3/pattern/run/' . urlencode($runRef) . '/route_type/' . $rt . '?expand=stop&expand=run',
        $baseUrl, $devId, $devKey
    );
    if (isset($data['error'])) { echo json_encode(array('error' => $data['error'])); exit; }

    $stopsIndex = isset($data['stops']) ? $data['stops'] : array();
    $stops = array();

    foreach ((isset($data['departures']) ? $data['departures'] : array()) as $dep) {
        $sid   = isset($dep['stop_id']) ? (int)$dep['stop_id'] : 0;
        $sname = 'Stop ' . $sid;

        if (isset($stopsIndex[$sid]['stop_name']))
            $sname = $stopsIndex[$sid]['stop_name'];
        elseif (isset($stopsIndex[(string)$sid]['stop_name']))
            $sname = $stopsIndex[(string)$sid]['stop_name'];
        else {
            foreach ($stopsIndex as $sv) {
                if (isset($sv['stop_id'], $sv['stop_name']) && (int)$sv['stop_id'] === $sid) {
                    $sname = $sv['stop_name']; break;
                }
            }
        }

        $sched = isset($dep['scheduled_departure_utc'])  ? $dep['scheduled_departure_utc']  : null;
        $est   = isset($dep['estimated_departure_utc'])   ? $dep['estimated_departure_utc']   : null;

        $schedFmt = null; $estFmt = null;
        if ($sched) {
            $dt = new DateTime($sched, new DateTimeZone('UTC'));
            $dt->setTimezone($tz);
            $schedFmt = $dt->format('H:i');
        }
        if ($est) {
            $dt = new DateTime($est, new DateTimeZone('UTC'));
            $dt->setTimezone($tz);
            $estFmt = $dt->format('H:i');
        }

        $delayMins = null;
        if ($sched && $est) {
            $s = new DateTime($sched, new DateTimeZone('UTC'));
            $e = new DateTime($est,   new DateTimeZone('UTC'));
            $delayMins = (int)round(($e->getTimestamp() - $s->getTimestamp()) / 60);
        }

        $platNo = (isset($dep['platform_number']) && $dep['platform_number'] !== '') ? $dep['platform_number'] : null;
        $atPlat = isset($dep['at_platform']) ? (bool)$dep['at_platform'] : false;

        $stops[] = array(
            'stop_id'    => $sid,
            'stop_name'  => trim($sname),
            'sched'      => $schedFmt,
            'est'        => $estFmt,
            'delay_mins' => $delayMins,
            'platform'   => $platNo,
            'at_plat'    => $atPlat,
            'is_current' => ($stopId && $sid === $stopId),
        );
    }

    if (!empty($stops)) {
        $stops[0]['is_origin']                   = true;
        $stops[count($stops) - 1]['is_terminus'] = true;
    }

    echo json_encode(array('stops' => $stops, 'count' => count($stops)));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>PTV Stop Timetable</title>
<link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@300;400;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:       #06070c;
  --panel:    #0a0c14;
  --surface:  #0e1018;
  --surface2: #12141e;
  --bdr:      #181d2e;
  --bdr2:     #1e2438;
  --acc:      #7c6dfa;
  --acc2:     #a899ff;
  --acc-dim:  #2a2260;
  --red:      #ff4d6a;
  --grn:      #3dffa0;
  --grn-d:    #197a48;
  --yel:      #ffd166;
  --blue:     #4db8ff;
  --pink:     #ff6dbd;
  --txt:      #c8d0e8;
  --txt-d:    #525c7a;
  --muted:    #252c42;

  --train:    #4db8ff;
  --tram:     #3dffa0;
  --bus:      #ffaa4d;
  --vline:    #c07aff;

  --font-head: 'Unbounded', sans-serif;
  --font-mono: 'DM Mono', monospace;
  --r:         10px;
}

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html,body { min-height:100%; background:var(--bg); }
body { color:var(--txt); font-family:var(--font-mono); font-size:13px; padding-bottom:60px; }

/* ── Header ── */
.hdr {
  background:var(--panel);
  border-bottom:1px solid var(--bdr);
  padding:16px 20px 14px;
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
}
.hdr-title {
  font-family:var(--font-head);
  font-size:1.1rem;
  font-weight:600;
  letter-spacing:-.01em;
  color:var(--acc2);
  line-height:1;
}
.hdr-sub {
  font-size:.55rem;
  color:var(--txt-d);
  letter-spacing:.12em;
  text-transform:uppercase;
  margin-top:5px;
}
.hdr-clock {
  font-family:var(--font-mono);
  font-size:.85rem;
  font-weight:500;
  color:var(--txt-d);
  padding-top:2px;
  white-space:nowrap;
}

/* ── Controls ── */
.ctrl {
  background:var(--panel);
  border-bottom:1px solid var(--bdr);
  padding:10px 20px;
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:8px;
}

/* Search */
.search-wrap {
  position:relative;
  flex:1;
  min-width:200px;
  max-width:380px;
}
.search-input {
  width:100%;
  background:var(--bg);
  border:1px solid var(--bdr2);
  border-radius:7px;
  color:var(--txt);
  font-family:var(--font-mono);
  font-size:.72rem;
  padding:7px 12px;
  outline:none;
  letter-spacing:.03em;
  transition:border-color .14s;
}
.search-input:focus { border-color:rgba(124,109,250,.5); }
.search-input::placeholder { color:var(--muted); }
.search-dd {
  display:none;
  position:absolute;
  top:calc(100% + 4px);
  left:0; right:0;
  background:var(--surface);
  border:1px solid var(--bdr2);
  border-radius:8px;
  z-index:800;
  overflow:hidden;
  box-shadow:0 12px 40px rgba(0,0,0,.9);
}
.search-dd.show { display:block; }
.sd-item {
  display:flex;
  align-items:center;
  gap:8px;
  padding:9px 12px;
  cursor:pointer;
  font-size:.7rem;
  border-bottom:1px solid var(--bdr);
  transition:background .1s;
}
.sd-item:last-child { border-bottom:none; }
.sd-item:hover { background:var(--surface2); }
.sd-dot  { width:7px;height:7px;border-radius:50%;flex-shrink:0; }
.sd-name { flex:1; color:var(--txt); }
.sd-sub  { font-size:.58rem; color:var(--txt-d); }
.sd-id   { font-size:.56rem; color:var(--muted); }

/* Mode toggle (Stop / Line) */
.mode-tabs { display:flex; gap:4px; }
.mode-tab {
  font-family:var(--font-mono);
  font-size:.6rem;
  padding:5px 11px;
  border-radius:6px;
  border:1px solid var(--bdr2);
  background:var(--bg);
  color:var(--txt-d);
  cursor:pointer;
  letter-spacing:.05em;
  transition:all .13s;
  white-space:nowrap;
}
.mode-tab.on {
  background:rgba(124,109,250,.14);
  border-color:var(--acc-dim);
  color:var(--acc2);
  font-weight:600;
}

/* RT tabs */
.rt-tabs { display:flex; gap:4px; }
.rt-tab {
  font-family:var(--font-mono);
  font-size:.6rem;
  padding:5px 11px;
  border-radius:6px;
  border:1px solid var(--bdr2);
  background:var(--bg);
  color:var(--txt-d);
  cursor:pointer;
  letter-spacing:.05em;
  transition:all .13s;
  white-space:nowrap;
}
.rt-tab.on { color:#000; font-weight:700; }
.rt-tab.on.t0 { background:var(--train); border-color:var(--train); }
.rt-tab.on.t1 { background:var(--tram);  border-color:var(--tram);  }
.rt-tab.on.t2 { background:var(--bus);   border-color:var(--bus);   }
.rt-tab.on.t3 { background:var(--vline); border-color:var(--vline); }

/* Date nav */
.date-nav {
  display:flex;
  align-items:center;
  gap:4px;
  background:var(--bg);
  border:1px solid var(--bdr2);
  border-radius:7px;
  overflow:hidden;
}
.date-btn {
  font-family:var(--font-mono);
  font-size:.68rem;
  padding:6px 10px;
  background:none;
  border:none;
  color:var(--txt-d);
  cursor:pointer;
  transition:color .12s;
}
.date-btn:hover { color:var(--acc2); }
.date-input {
  background:none;
  border:none;
  border-left:1px solid var(--bdr2);
  border-right:1px solid var(--bdr2);
  color:var(--txt);
  font-family:var(--font-mono);
  font-size:.68rem;
  padding:5px 8px;
  outline:none;
  cursor:pointer;
  color-scheme:dark;
  min-width:120px;
}

/* Direction filter */
.dir-wrap {
  display:flex;
  align-items:center;
  gap:5px;
  flex-wrap:wrap;
}
.dir-lbl {
  font-size:.55rem;
  color:var(--txt-d);
  text-transform:uppercase;
  letter-spacing:.1em;
  white-space:nowrap;
}
.dir-btn {
  font-family:var(--font-mono);
  font-size:.6rem;
  padding:4px 10px;
  border-radius:5px;
  border:1px solid var(--bdr2);
  background:var(--bg);
  color:var(--txt-d);
  cursor:pointer;
  white-space:nowrap;
  letter-spacing:.04em;
  transition:all .13s;
}
.dir-btn.on {
  background:rgba(124,109,250,.12);
  border-color:var(--acc-dim);
  color:var(--acc2);
}
.dir-wrap.hidden { display:none; }

/* Load button */
.btn-load {
  font-family:var(--font-mono);
  font-size:.67rem;
  padding:6px 16px;
  border-radius:7px;
  border:none;
  background:var(--acc);
  color:#fff;
  cursor:pointer;
  font-weight:500;
  letter-spacing:.04em;
  white-space:nowrap;
  transition:opacity .13s;
}
.btn-load:hover   { opacity:.85; }
.btn-load:disabled{ opacity:.35; cursor:default; }

/* ── Station nameplate ── */
.nameplate {
  padding:12px 20px;
  background:var(--panel);
  border-bottom:1px solid var(--bdr);
  display:none;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}
.nameplate.show { display:flex; }
.np-name {
  font-family:var(--font-head);
  font-size:1.25rem;
  font-weight:400;
  letter-spacing:-.01em;
  color:var(--txt);
  line-height:1.1;
}
.np-sub {
  font-size:.55rem;
  color:var(--txt-d);
  letter-spacing:.1em;
  text-transform:uppercase;
  margin-top:4px;
}
.np-stats {
  display:flex;
  gap:14px;
  flex-shrink:0;
}
.np-stat {
  text-align:right;
}
.np-stat .sv { font-family:var(--font-head); font-size:1.4rem; font-weight:300; color:var(--acc2); line-height:1; }
.np-stat .sl { font-size:.5rem; text-transform:uppercase; letter-spacing:.1em; color:var(--txt-d); margin-top:2px; }

/* ── Loading bar ── */
.load-bar {
  height:2px;
  background:var(--bdr);
  position:relative;
  overflow:hidden;
  display:none;
}
.load-bar.show { display:block; }
.load-bar::after {
  content:'';
  position:absolute;
  top:0; left:-40%; width:40%; height:100%;
  background:var(--acc);
  animation:loadSlide 1s ease-in-out infinite;
}
@keyframes loadSlide { to{left:110%;} }

/* Spinner */
.spin-ring {
  display:inline-block;
  width:14px; height:14px;
  border:2px solid rgba(124,109,250,.2);
  border-top-color:var(--acc2);
  border-radius:50%;
  animation:spin .65s linear infinite;
  flex-shrink:0;
}
@keyframes spin { to{transform:rotate(360deg)} }

/* ── Main content ── */
.content {
  max-width:960px;
  margin:0 auto;
  padding:20px 20px 0;
}

/* ── Empty / error states ── */
.state-empty {
  text-align:center;
  padding:80px 20px;
  color:var(--muted);
}
.state-empty .icon {
  font-size:2.5rem;
  margin-bottom:14px;
  opacity:.3;
}
.state-empty strong {
  display:block;
  font-family:var(--font-head);
  font-size:1.1rem;
  font-weight:400;
  color:var(--txt-d);
  letter-spacing:-.01em;
  margin-bottom:8px;
}
.state-empty p { font-size:.68rem; line-height:1.8; color:var(--muted); }
.err-box {
  background:rgba(255,77,106,.07);
  border:1px solid rgba(255,77,106,.2);
  border-radius:var(--r);
  padding:14px 16px;
  color:#ff8898;
  font-size:.72rem;
  margin-bottom:14px;
}

/* ── Route group card ── */
.route-group {
  margin-bottom:20px;
  border:1px solid var(--bdr);
  border-radius:var(--r);
  overflow:hidden;
  animation:fadeUp .2s ease both;
}
@keyframes fadeUp { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }

.rg-header {
  display:flex;
  align-items:center;
  gap:10px;
  padding:11px 16px;
  background:var(--surface);
  border-bottom:1px solid var(--bdr);
  cursor:pointer;
  user-select:none;
  -webkit-tap-highlight-color:transparent;
  transition:background .12s;
}
.rg-header:hover { background:var(--surface2); }

.rg-badge {
  font-family:var(--font-head);
  font-size:.75rem;
  font-weight:600;
  padding:4px 10px;
  border-radius:5px;
  letter-spacing:-.01em;
  flex-shrink:0;
  white-space:nowrap;
}
.rg-badge.t0 { background:rgba(77,184,255,.12); border:1px solid rgba(77,184,255,.25); color:var(--train); }
.rg-badge.t1 { background:rgba(61,255,160,.1);  border:1px solid rgba(61,255,160,.22); color:var(--tram);  }
.rg-badge.t2 { background:rgba(255,170,77,.1);  border:1px solid rgba(255,170,77,.22); color:var(--bus);   }
.rg-badge.t3 { background:rgba(192,122,255,.1); border:1px solid rgba(192,122,255,.22);color:var(--vline); }

.rg-info { flex:1; overflow:hidden; }
.rg-direction {
  font-size:.82rem;
  color:var(--txt);
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  line-height:1.2;
}
.rg-meta {
  font-size:.56rem;
  color:var(--txt-d);
  letter-spacing:.06em;
  margin-top:2px;
}
.rg-right {
  display:flex;
  align-items:center;
  gap:8px;
  flex-shrink:0;
}
.rg-count {
  font-size:.6rem;
  color:var(--txt-d);
  background:var(--surface2);
  border:1px solid var(--bdr2);
  border-radius:20px;
  padding:2px 9px;
  white-space:nowrap;
}
.rg-arrow {
  font-size:.6rem;
  color:var(--txt-d);
  transition:transform .18s;
}
.route-group.collapsed .rg-arrow { transform:rotate(-90deg); }

/* ── Services grid ── */
.rg-body { display:block; }
.route-group.collapsed .rg-body { display:none; }

/* Hour band */
.hour-band {
  display:flex;
  align-items:stretch;
  border-bottom:1px solid var(--bdr);
}
.hour-band:last-child { border-bottom:none; }

.hb-hour {
  font-family:var(--font-head);
  font-size:.95rem;
  font-weight:300;
  color:var(--acc-dim);
  width:52px;
  flex-shrink:0;
  display:flex;
  align-items:flex-start;
  justify-content:center;
  padding:10px 0 10px;
  border-right:1px solid var(--bdr);
  letter-spacing:-.01em;
  line-height:1;
  position:relative;
}
/* dim past hour */
.hour-band.past .hb-hour { color:var(--muted); }
.hour-band.current .hb-hour { color:var(--acc2); }

.hb-services {
  flex:1;
  display:flex;
  flex-wrap:wrap;
  gap:5px;
  padding:8px 10px;
  align-content:flex-start;
}

/* Service pill */
.svc-pill {
  display:inline-flex;
  flex-direction:column;
  align-items:center;
  min-width:48px;
  padding:5px 7px;
  border-radius:7px;
  cursor:default;
  transition:transform .1s, border-color .1s;
  position:relative;
  border:1px solid var(--bdr2);
  background:var(--surface2);
}
.svc-pill:hover { transform:translateY(-1px); border-color:var(--acc-dim); }
.svc-pill.past  { opacity:.38; }
.svc-pill.now   { border-color:var(--acc); background:rgba(124,109,250,.08); box-shadow:0 0 10px rgba(124,109,250,.18); }
.svc-pill.soon  { border-color:rgba(124,109,250,.4); background:rgba(124,109,250,.05); }
.svc-pill.express { border-color:rgba(255,209,102,.3); }

.sp-time {
  font-family:var(--font-head);
  font-size:.82rem;
  font-weight:400;
  letter-spacing:-.01em;
  line-height:1;
  color:var(--txt);
}
.svc-pill.past .sp-time    { color:var(--txt-d); }
.svc-pill.now  .sp-time    { color:var(--acc2); }

.sp-est {
  font-size:.58rem;
  color:var(--grn);
  margin-top:2px;
  line-height:1;
}
.sp-delay {
  font-size:.56rem;
  margin-top:1px;
  line-height:1;
}
.sp-delay.late  { color:var(--red);  }
.sp-delay.early { color:var(--blue); }
.sp-delay.ok    { color:var(--grn-d);}

.sp-plat {
  font-size:.54rem;
  color:var(--yel);
  margin-top:2px;
  line-height:1;
}
.sp-dest {
  font-size:.54rem;
  color:var(--txt-d);
  margin-top:2px;
  max-width:72px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  line-height:1;
}

/* Now marker line */
.now-line {
  width:100%;
  display:flex;
  align-items:center;
  gap:6px;
  padding:0 10px;
  margin:2px 0;
}
.now-line::before, .now-line::after {
  content:'';
  flex:1;
  height:1px;
  background:rgba(124,109,250,.3);
}
.now-line-label {
  font-size:.52rem;
  color:var(--acc);
  letter-spacing:.08em;
  text-transform:uppercase;
  white-space:nowrap;
}

/* Tooltip */
.svc-pill:hover .sp-tooltip { display:block; }
.sp-tooltip {
  display:none;
  position:absolute;
  bottom:calc(100% + 6px);
  left:50%;
  transform:translateX(-50%);
  background:var(--surface);
  border:1px solid var(--bdr2);
  border-radius:6px;
  padding:7px 10px;
  min-width:130px;
  z-index:200;
  box-shadow:0 6px 20px rgba(0,0,0,.8);
  pointer-events:none;
}
.sp-tooltip::after {
  content:'';
  position:absolute;
  top:100%;
  left:50%;
  transform:translateX(-50%);
  border:5px solid transparent;
  border-top-color:var(--bdr2);
}
.tt-row {
  display:flex;
  justify-content:space-between;
  gap:8px;
  margin-bottom:3px;
  font-size:.6rem;
}
.tt-row:last-child { margin-bottom:0; }
.tt-k { color:var(--txt-d); }
.tt-v { color:var(--txt); }

/* Legend */
.legend {
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  padding:10px 20px;
  border-top:1px solid var(--bdr);
  margin-top:20px;
}
.lg-item {
  display:flex;
  align-items:center;
  gap:5px;
  font-size:.58rem;
  color:var(--txt-d);
}
.lg-swatch {
  width:10px;height:10px;
  border-radius:3px;
  flex-shrink:0;
}

/* Jump-to-now fab */
#now-fab {
  display:none;
  position:fixed;
  bottom:24px;
  right:20px;
  z-index:900;
  font-family:var(--font-mono);
  font-size:.65rem;
  padding:9px 16px;
  border-radius:30px;
  border:1px solid var(--acc-dim);
  background:rgba(124,109,250,.15);
  color:var(--acc2);
  cursor:pointer;
  letter-spacing:.05em;
  backdrop-filter:blur(8px);
  box-shadow:0 4px 20px rgba(0,0,0,.6);
  transition:background .14s;
  white-space:nowrap;
}
#now-fab.show { display:flex; align-items:center; gap:6px; }
#now-fab:hover { background:rgba(124,109,250,.25); }

/* ── Pattern slide-up panel ── */
#pat-panel {
  display:none;
  position:fixed;
  inset:0;
  z-index:1000;
}
#pat-panel.show { display:block; }

#pat-backdrop {
  position:absolute;
  inset:0;
  background:rgba(0,0,0,.65);
  backdrop-filter:blur(3px);
  cursor:pointer;
}

#pat-sheet {
  position:absolute;
  bottom:0; left:0; right:0;
  max-height:82vh;
  background:var(--surface);
  border-top:1px solid var(--bdr2);
  border-radius:16px 16px 0 0;
  display:flex;
  flex-direction:column;
  box-shadow:0 -8px 40px rgba(0,0,0,.8);
  transform:translateY(100%);
  transition:transform .28s cubic-bezier(.32,.72,0,1);
}
#pat-panel.show #pat-sheet {
  transform:translateY(0);
}

.pat-sheet-hdr {
  flex-shrink:0;
  padding:14px 18px 12px;
  border-bottom:1px solid var(--bdr);
  display:flex;
  align-items:center;
  gap:12px;
}
/* drag handle pill */
.pat-sheet-hdr::before {
  content:'';
  position:absolute;
  top:8px; left:50%;
  transform:translateX(-50%);
  width:36px; height:4px;
  border-radius:2px;
  background:var(--bdr2);
}
.pat-hdr-info { flex:1; overflow:hidden; }
.pat-hdr-title {
  font-family:var(--font-head);
  font-size:.95rem;
  font-weight:400;
  letter-spacing:-.01em;
  color:var(--txt);
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.pat-hdr-meta {
  font-size:.56rem;
  color:var(--txt-d);
  letter-spacing:.07em;
  margin-top:3px;
}
.pat-close {
  width:30px; height:30px;
  border-radius:8px;
  border:1px solid var(--bdr2);
  background:none;
  color:var(--txt-d);
  font-size:1rem;
  cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  flex-shrink:0;
  transition:all .13s;
}
.pat-close:hover { color:var(--txt); border-color:var(--bdr2); background:var(--surface2); }

.pat-sheet-body {
  flex:1;
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  padding:14px 18px 30px;
}

/* Loading / error inside sheet */
.pat-sheet-loading {
  display:flex;
  align-items:center;
  gap:8px;
  color:var(--txt-d);
  font-size:.68rem;
  padding:20px 0;
}
.pat-sheet-err {
  color:#ff8090;
  font-size:.68rem;
  padding:12px 0;
}

/* Timeline */
.pat-timeline {
  position:relative;
  padding-left:28px;
}
/* spine */
.pat-timeline::before {
  content:'';
  position:absolute;
  left:7px; top:12px; bottom:12px;
  width:2px;
  background:var(--bdr2);
}

.pt-stop {
  position:relative;
  display:flex;
  align-items:center;
  gap:10px;
  padding:7px 0;
  min-height:38px;
}
/* dot */
.pt-stop::before {
  content:'';
  position:absolute;
  left:-21px;
  top:50%; transform:translateY(-50%);
  width:12px; height:12px;
  border-radius:50%;
  background:var(--bdr2);
  border:2px solid var(--surface);
  z-index:1;
}
.pt-stop.is-origin::before,
.pt-stop.is-terminus::before {
  width:14px; height:14px;
  left:-22px;
  background:var(--acc-dim);
  border:2px solid var(--acc);
}
.pt-stop.is-current::before {
  background:var(--acc);
  border-color:var(--acc2);
  box-shadow:0 0 10px rgba(124,109,250,.6);
  width:14px; height:14px;
  left:-22px;
}
.pt-stop.is-atplat::before {
  background:var(--grn);
  border-color:var(--grn);
  box-shadow:0 0 8px rgba(61,255,160,.4);
}
.pt-stop.passed::before {
  background:var(--muted);
  border-color:var(--bdr2);
}
/* passed spine segment */
.pt-stop.passed-line::after {
  content:'';
  position:absolute;
  left:-15px; top:0; bottom:50%;
  width:2px;
  background:var(--muted);
}

.pt-name {
  flex:1;
  font-size:.78rem;
  color:var(--txt);
  line-height:1.3;
  min-width:0;
  word-break:break-word;
}
.pt-stop.is-current  .pt-name { color:var(--acc2); font-weight:500; }
.pt-stop.is-terminus .pt-name { color:var(--txt); }
.pt-stop.passed      .pt-name { color:var(--txt-d); }

.pt-badges {
  display:flex;
  gap:4px;
  align-items:center;
  flex-wrap:wrap;
  flex-shrink:0;
}
.pt-badge {
  font-size:.52rem;
  border-radius:3px;
  padding:2px 6px;
  white-space:nowrap;
}
.pt-badge.you  { background:rgba(124,109,250,.12); border:1px solid rgba(124,109,250,.3); color:var(--acc2); }
.pt-badge.org  { background:rgba(255,209,102,.08); border:1px solid rgba(255,209,102,.2); color:var(--yel); }
.pt-badge.trm  { background:rgba(255,77,106,.07);  border:1px solid rgba(255,77,106,.2);  color:var(--red); }
.pt-badge.atp  { background:rgba(61,255,160,.08);  border:1px solid rgba(61,255,160,.2);  color:var(--grn); }
.pt-badge.plt  { background:rgba(255,209,102,.06); border:1px solid rgba(255,209,102,.15);color:var(--yel); }

.pt-time {
  font-size:.68rem;
  color:var(--txt-d);
  white-space:nowrap;
  text-align:right;
  flex-shrink:0;
  min-width:36px;
}
.pt-time.live { color:var(--grn); }

.pt-delay {
  font-size:.6rem;
  white-space:nowrap;
  flex-shrink:0;
  min-width:40px;
  text-align:right;
}
.pt-delay.late  { color:var(--red);  }
.pt-delay.early { color:var(--blue); }
.pt-delay.ok    { color:var(--grn-d);}

/* Make pills clickable */
.svc-pill {
  cursor:pointer;
}
.svc-pill:active { transform:scale(.96); }

/* ── Full-line timetable grid ── */
.line-tt-wrap {
  overflow:auto;
  max-height:74vh;
  border:1px solid var(--bdr);
  border-radius:var(--r);
  margin-bottom:20px;
  -webkit-overflow-scrolling:touch;
}
table.line-tt {
  border-collapse:separate;
  border-spacing:0;
  font-family:var(--font-mono);
  font-size:.68rem;
  white-space:nowrap;
}
table.line-tt th, table.line-tt td {
  padding:6px 9px;
  border-bottom:1px solid var(--bdr);
  border-right:1px solid var(--bdr);
  text-align:center;
}
.ltt-corner {
  position:sticky;
  left:0; top:0;
  z-index:30;
  background:var(--surface);
  min-width:150px;
  text-align:left !important;
}
.ltt-stop-name {
  position:sticky;
  left:0;
  z-index:10;
  background:var(--panel);
  text-align:left !important;
  color:var(--txt);
  min-width:150px;
  max-width:220px;
  overflow:hidden;
  text-overflow:ellipsis;
}
.ltt-col-hdr {
  position:sticky;
  top:0;
  z-index:20;
  background:var(--surface);
  cursor:pointer;
  min-width:56px;
  transition:background .12s;
}
.ltt-col-hdr:hover { background:var(--surface2); }
.ltt-col-hdr.past { opacity:.4; }
.ltt-hdr-time {
  display:block;
  font-family:var(--font-head);
  font-size:.78rem;
  font-weight:500;
  color:var(--acc2);
}
.ltt-hdr-dest {
  display:block;
  font-size:.5rem;
  color:var(--txt-d);
  max-width:70px;
  margin:2px auto 0;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.ltt-hdr-exp {
  display:inline-block;
  font-size:.48rem;
  color:var(--yel);
  margin-top:2px;
  letter-spacing:.05em;
}
.ltt-cell {
  color:var(--txt);
}
.ltt-cell.past { color:var(--txt-d); opacity:.5; }
.ltt-cell.empty { color:var(--muted); }
.ltt-delay {
  display:block;
  font-size:.5rem;
  line-height:1;
  margin-top:1px;
}
.ltt-delay.late  { color:var(--red);  }
.ltt-delay.early { color:var(--blue); }

@media(max-width:600px) {
  .hdr, .ctrl, .nameplate { padding-left:12px; padding-right:12px; }
  .content { padding:12px 12px 0; }
  .np-stats { display:none; }
  .hb-hour  { width:40px; font-size:.8rem; }
  .svc-pill { min-width:42px; }
  .sp-time  { font-size:.75rem; }
}
</style>
</head>
<body>
<style>
.menu{background:#333;padding:10px;position:sticky;top:0;z-index:9999;}
.menu a{color:#fff;margin-right:15px;text-decoration:none;font-weight:bold;font-family:Arial,sans-serif;font-size:14px;}
.menu a:hover{text-decoration:underline;}
</style>

<div class="menu">
  <a href="nearmea.php">near map</a>
  <a href="stationtime2.php">Station Time</a>
  <a href="tracker.php">Tracker</a>
  <a href="ptvboard.php">PTV Board</a>
  <a href="ptvboard4.php">PTV Board 4</a>
  <a href="distributions3.php">Distributions</a>
  <a href="fare.php">fares</a>
</div>
<!-- Header -->
<div class="hdr">
  <div>
    <div class="hdr-title">Stop Timetable</div>
    <div class="hdr-sub">Full day schedule &middot; Melbourne</div>
  </div>
  <div class="hdr-clock" id="clock">--:--:--</div>
</div>

<!-- Loading bar -->
<div class="load-bar" id="load-bar"></div>

<!-- Controls -->
<div class="ctrl">
  <div class="mode-tabs" id="mode-tabs">
    <button class="mode-tab on" data-mode="stop" onclick="setMode('stop')">Stop</button>
    <button class="mode-tab"    data-mode="line" onclick="setMode('line')">Line</button>
  </div>

  <div class="rt-tabs" id="rt-tabs">
    <button class="rt-tab on t0" data-rt="0">Train</button>
    <button class="rt-tab t1"    data-rt="1">Tram</button>
    <button class="rt-tab t2"    data-rt="2">Bus</button>
    <button class="rt-tab t3"    data-rt="3">V/Line</button>
  </div>

  <div class="search-wrap">
    <input
      type="text"
      class="search-input"
      id="search-input"
      placeholder="Search station or stop…"
      autocomplete="off"
      autocorrect="off"
      spellcheck="false"
    >
    <div class="search-dd" id="search-dd"></div>
  </div>

  <div class="date-nav">
    <button class="date-btn" id="btn-prev" onclick="shiftDate(-1)">&#8249;</button>
    <input type="date" class="date-input" id="date-input">
    <button class="date-btn" id="btn-next" onclick="shiftDate(1)">&#8250;</button>
  </div>

  <button class="btn-load" id="btn-load" onclick="loadTimetable()" disabled>Load</button>
</div>

<!-- Direction filter (shown after load) -->
<div class="ctrl" id="dir-ctrl" style="padding-top:8px;padding-bottom:8px;display:none">
  <div class="dir-wrap" id="dir-wrap">
    <span class="dir-lbl">Direction</span>
  </div>
</div>

<!-- Nameplate -->
<div class="nameplate" id="nameplate">
  <div>
    <div class="np-name" id="np-name">—</div>
    <div class="np-sub"  id="np-sub">—</div>
  </div>
  <div class="np-stats">
    <div class="np-stat">
      <div class="sv" id="np-total">—</div>
      <div class="sl">Services</div>
    </div>
    <div class="np-stat">
      <div class="sv" id="np-groups">—</div>
      <div class="sl">Directions</div>
    </div>
  </div>
</div>

<!-- Content -->
<div class="content" id="content">
  <div class="state-empty" id="state-empty">
    <div class="icon">&#128197;</div>
    <strong>Choose a stop and date</strong>
    <p>Search for a station above, pick a date,<br>and press Load to see the full day's timetable.</p>
  </div>
</div>

<!-- Jump-to-now fab -->
<button id="now-fab" onclick="scrollToNow()">&#9654; Now</button>

<!-- Stopping pattern panel -->
<div id="pat-panel">
  <div id="pat-backdrop" onclick="closePattern()"></div>
  <div id="pat-sheet">
    <div class="pat-sheet-hdr">
      <div class="pat-hdr-info">
        <div class="pat-hdr-title" id="pat-title">Stopping pattern</div>
        <div class="pat-hdr-meta"  id="pat-meta"></div>
      </div>
      <button class="pat-close" onclick="closePattern()">&#215;</button>
    </div>
    <div class="pat-sheet-body" id="pat-body">
      <div class="pat-sheet-loading"><span class="spin-ring"></span> Loading…</div>
    </div>
  </div>
</div>

<script>
// ── State ─────────────────────────────────────────────────────────────────────
var _stopId   = null;
var _rt       = 0;
var _date     = null;   // 'YYYY-MM-DD'
var _dirFilter= -1;     // -1 = all
var _loading  = false;
var _searchTmr= null;
var _timetable= null;   // last loaded timetable data
var _mode     = 'stop'; // 'stop' | 'line'
var _routeId  = null;
var _lineName = null;

function setMode(m) {
  _mode = m;
  var btns = document.querySelectorAll('#mode-tabs .mode-tab');
  for (var i = 0; i < btns.length; i++) {
    btns[i].className = 'mode-tab' + (btns[i].getAttribute('data-mode') === m ? ' on' : '');
  }
  _stopId = null; _routeId = null; _lineName = null;
  document.getElementById('search-input').value = '';
  document.getElementById('search-input').placeholder =
    (m === 'line') ? 'Search line (e.g. Belgrave, Frankston)…' : 'Search station or stop…';
  closeDd();
  document.getElementById('btn-load').disabled = true;
}

var RTN  = {0:'Train', 1:'Tram', 2:'Bus', 3:'V/Line', 4:'Night Bus'};
var RTC  = {0:'#4db8ff', 1:'#3dffa0', 2:'#ffaa4d', 3:'#c07aff'};

function esc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function pad2(n) { return n < 10 ? '0'+n : ''+n; }

function todayStr() {
  var d = new Date();
  return d.getFullYear() + '-' + pad2(d.getMonth()+1) + '-' + pad2(d.getDate());
}

// ── Clock ─────────────────────────────────────────────────────────────────────
function tickClock() {
  var d = new Date();
  document.getElementById('clock').textContent =
    pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
}
setInterval(tickClock, 1000);
tickClock();

// ── Date input init ───────────────────────────────────────────────────────────
(function() {
  var inp = document.getElementById('date-input');
  _date = todayStr();
  inp.value = _date;
  inp.addEventListener('change', function() {
    _date = this.value;
    if (_stopId) loadTimetable();
  });
  // min/max: today ±14 days
  var today = new Date();
  var minD  = new Date(today); minD.setDate(minD.getDate() - 0);
  var maxD  = new Date(today); maxD.setDate(maxD.getDate() + 14);
  inp.min = todayStr();
  inp.max = maxD.getFullYear() + '-' + pad2(maxD.getMonth()+1) + '-' + pad2(maxD.getDate());
}());

function shiftDate(delta) {
  var inp  = document.getElementById('date-input');
  var d    = new Date(inp.value + 'T00:00:00');
  d.setDate(d.getDate() + delta);
  var s = d.getFullYear() + '-' + pad2(d.getMonth()+1) + '-' + pad2(d.getDate());
  if (s < inp.min || s > inp.max) return;
  inp.value = s;
  _date = s;
  if (_stopId) loadTimetable();
}

// ── RT tabs ───────────────────────────────────────────────────────────────────
(function() {
  var tabs = document.querySelectorAll('#rt-tabs .rt-tab');
  for (var i = 0; i < tabs.length; i++) {
    tabs[i].addEventListener('click', function() {
      _rt = parseInt(this.getAttribute('data-rt'), 10);
      for (var j = 0; j < tabs.length; j++)
        tabs[j].className = 'rt-tab t' + tabs[j].getAttribute('data-rt');
      this.className = 'rt-tab on t' + _rt;
    });
  }
}());

// ── Search ────────────────────────────────────────────────────────────────────
document.getElementById('search-input').addEventListener('input', function() {
  clearTimeout(_searchTmr);
  var q = this.value.trim();
  var minLen = (_mode === 'line') ? 1 : 2;
  if (q.length < minLen) { closeDd(); return; }
  _searchTmr = setTimeout(function() {
    if (_mode === 'line') doSearchLines(q); else doSearch(q);
  }, 260);
});
document.getElementById('search-input').addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeDd();
});
document.addEventListener('click', function(e) {
  if (!e.target.closest('#search-input') && !e.target.closest('#search-dd')) closeDd();
});

function doSearch(q) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '?action=search&q=' + encodeURIComponent(q) + '&rt=' + _rt, true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4 || xhr.status !== 200) return;
    var d; try { d = JSON.parse(xhr.responseText); } catch(ex) { return; }
    renderDd(d.stops || []);
  };
  xhr.send();
}

function renderDd(stops) {
  var dd = document.getElementById('search-dd');
  if (!stops.length) { dd.className = 'search-dd'; return; }
  var h = '';
  for (var i = 0; i < stops.length; i++) {
    var s = stops[i], col = RTC[s.rt] || '#fff';
    h += '<div class="sd-item" onclick="selectStop(' + s.stop_id + ',' + s.rt + ',\'' + esc(s.stop_name.replace(/\\/g,'\\\\').replace(/'/g,"\\'")) + '\')">'
       + '<span class="sd-dot" style="background:' + col + '"></span>'
       + '<span class="sd-name">' + esc(s.stop_name) + '</span>'
       + (s.suburb ? '<span class="sd-sub">' + esc(s.suburb) + '</span>' : '')
       + '<span class="sd-id">#' + s.stop_id + '</span>'
       + '</div>';
  }
  dd.innerHTML = h;
  dd.className = 'search-dd show';
}

function closeDd() {
  document.getElementById('search-dd').className = 'search-dd';
}

function selectStop(stopId, rt, name) {
  _stopId = stopId;
  _rt     = rt;
  var tabs = document.querySelectorAll('#rt-tabs .rt-tab');
  for (var i = 0; i < tabs.length; i++) {
    var trt = parseInt(tabs[i].getAttribute('data-rt'), 10);
    tabs[i].className = 'rt-tab t' + trt + (trt === rt ? ' on' : '');
  }
  document.getElementById('search-input').value = name;
  closeDd();
  document.getElementById('btn-load').disabled = false;
  loadTimetable();
}

function doSearchLines(q) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '?action=search_lines&q=' + encodeURIComponent(q) + '&rt=' + _rt, true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4 || xhr.status !== 200) return;
    var d; try { d = JSON.parse(xhr.responseText); } catch(ex) { return; }
    renderLineDd(d.lines || []);
  };
  xhr.send();
}

function renderLineDd(lines) {
  var dd = document.getElementById('search-dd');
  if (!lines.length) { dd.className = 'search-dd'; return; }
  var h = '';
  for (var i = 0; i < lines.length; i++) {
    var l = lines[i];
    var label = l.route_number ? (l.route_number + ' \u2014 ' + l.route_name) : l.route_name;
    h += '<div class="sd-item" onclick="selectLine(' + l.route_id + ',' + l.rt + ',\'' + esc(label.replace(/\\/g,'\\\\').replace(/'/g,"\\'")) + '\')">'
       + '<span class="sd-dot" style="background:' + (RTC[l.rt] || '#fff') + '"></span>'
       + '<span class="sd-name">' + esc(label) + '</span>'
       + '</div>';
  }
  dd.innerHTML = h;
  dd.className = 'search-dd show';
}

function selectLine(routeId, rt, name) {
  _routeId  = routeId;
  _rt       = rt;
  _lineName = name;
  var tabs = document.querySelectorAll('#rt-tabs .rt-tab');
  for (var i = 0; i < tabs.length; i++) {
    var trt = parseInt(tabs[i].getAttribute('data-rt'), 10);
    tabs[i].className = 'rt-tab t' + trt + (trt === rt ? ' on' : '');
  }
  document.getElementById('search-input').value = name;
  closeDd();
  document.getElementById('btn-load').disabled = false;
  loadTimetable();
}

// ── Load timetable ────────────────────────────────────────────────────────────
function loadTimetable() {
  if (_mode === 'stop' && !_stopId) return;
  if (_mode === 'line' && !_routeId) return;
  if (_loading) return;
  _loading = true;
  _dirFilter = -1;
  document.getElementById('btn-load').disabled = true;
  document.getElementById('load-bar').className = 'load-bar show';
  document.getElementById('dir-ctrl').style.display = 'none';

  var url;
  if (_mode === 'line') {
    url = '?action=line_timetable'
        + '&route_id=' + _routeId
        + '&rt='       + _rt
        + '&name='     + encodeURIComponent(_lineName || '')
        + '&date='     + encodeURIComponent(_date || todayStr());
  } else {
    url = '?action=timetable'
        + '&stop_id='  + _stopId
        + '&rt='       + _rt
        + '&date='     + encodeURIComponent(_date || todayStr());
  }

  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) return;
    _loading = false;
    document.getElementById('btn-load').disabled = false;
    document.getElementById('load-bar').className = 'load-bar';

    if (xhr.status !== 200) { showError('Server error ' + xhr.status); return; }
    var d; try { d = JSON.parse(xhr.responseText); } catch(ex) { showError('Parse error'); return; }
    if (d.error) { showError(d.error); return; }

    _timetable = d;
    if (_mode === 'line') renderLineTimetableTop(d); else renderTimetable(d);
  };
  xhr.send();
}

function showError(msg) {
  document.getElementById('content').innerHTML = '<div class="err-box">&#9888; ' + esc(msg) + '</div>';
  document.getElementById('nameplate').className = 'nameplate';
}

// ── Direction filter UI ───────────────────────────────────────────────────────
function buildDirFilter(directions) {
  if (!directions || !directions.length) {
    document.getElementById('dir-ctrl').style.display = 'none';
    return;
  }
  var h = '<span class="dir-lbl">Filter</span>'
        + '<button class="dir-btn on" data-dir="-1" onclick="setDirFilter(-1)">All</button>';
  for (var i = 0; i < directions.length; i++) {
    var dir = directions[i];
    var label = dir.name || ('Direction ' + dir.direction_id);
    if (dir.route_number) label = dir.route_number + ' \u2192 ' + label;
    h += '<button class="dir-btn" data-dir="' + dir.direction_id + '" onclick="setDirFilter(' + dir.direction_id + ')">'
       + esc(label) + '</button>';
  }
  document.getElementById('dir-wrap').innerHTML = h;
  document.getElementById('dir-ctrl').style.display = '';
}

function setDirFilter(dirId) {
  _dirFilter = dirId;
  var btns = document.querySelectorAll('#dir-wrap .dir-btn');
  for (var i = 0; i < btns.length; i++) {
    var v = parseInt(btns[i].getAttribute('data-dir'), 10);
    btns[i].className = 'dir-btn' + (v === dirId ? ' on' : '');
  }
  if (_mode === 'line') renderLineTimetable(_timetable, dirId);
  else renderGroups(_timetable, dirId);
}

// ── Main render ───────────────────────────────────────────────────────────────
function renderTimetable(data) {
  // Nameplate
  var np = document.getElementById('nameplate');
  np.className = 'nameplate show';
  document.getElementById('np-name').textContent  = data.station || '—';
  document.getElementById('np-sub').textContent   = RTN[data.rt] + ' \u00b7 ' + (data.date_label || data.date);
  document.getElementById('np-total').textContent  = data.total  || 0;
  document.getElementById('np-groups').textContent = (data.groups ? data.groups.length : 0);

  buildDirFilter(data.directions || []);
  renderGroups(data, -1);

  // Show jump-to-now only for today
  var fab = document.getElementById('now-fab');
  if (data.date === todayStr()) {
    fab.className = 'show';
    setTimeout(scrollToNow, 400);
  } else {
    fab.className = '';
  }
}

function renderLineTimetableTop(data) {
  var np = document.getElementById('nameplate');
  np.className = 'nameplate show';
  document.getElementById('np-name').textContent  = data.line_name || '—';
  document.getElementById('np-sub').textContent   = RTN[data.rt] + ' \u00b7 ' + (data.date_label || data.date) + ' \u00b7 all stops';
  document.getElementById('np-total').textContent  = data.total || 0;
  document.getElementById('np-groups').textContent = (data.directions ? data.directions.length : 0);

  buildDirFilter(data.directions || []);
  renderLineTimetable(data, -1);

  // Grid view doesn't use the jump-to-now fab
  document.getElementById('now-fab').className = '';
}

function formatHM(ts) {
  var d = new Date(ts * 1000);
  return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
}

function renderLineTimetable(data, dirFilter) {
  var content = document.getElementById('content');
  var stops   = data.stops || [];
  var runs    = (data.runs || []).filter(function(r) {
    return dirFilter < 0 || r.direction_id === dirFilter;
  });

  if (!stops.length || !runs.length) {
    content.innerHTML = '<div class="state-empty"><div class="icon">&#128197;</div><strong>No services</strong><p>No line timetable data for this date' + (dirFilter >= 0 ? '/direction' : '') + '.</p></div>';
    return;
  }

  var nowTs   = Math.floor(Date.now() / 1000);
  var isToday = (data.date === todayStr());

  var h = '<div class="line-tt-wrap"><table class="line-tt"><thead><tr><th class="ltt-corner">Stop</th>';
  for (var c = 0; c < runs.length; c++) {
    var r = runs[c];
    var isPast = isToday && r.origin_ts < nowTs;
    h += '<th class="ltt-col-hdr' + (isPast ? ' past' : '') + '"'
       +   ' onclick="openPattern(event,\'' + esc(r.run_ref) + '\',' + data.rt + ',0,\'' + esc(formatHM(r.origin_ts)) + '\',\'' + esc(r.dest || '') + '\')">'
       +   '<span class="ltt-hdr-time">' + esc(formatHM(r.origin_ts)) + '</span>'
       +   (r.dest ? '<span class="ltt-hdr-dest">' + esc(r.dest) + '</span>' : '')
       +   (r.express ? '<span class="ltt-hdr-exp">EXP</span>' : '')
       + '</th>';
  }
  h += '</tr></thead><tbody>';

  for (var si = 0; si < stops.length; si++) {
    var st = stops[si];
    h += '<tr><td class="ltt-stop-name">' + esc(st.stop_name) + '</td>';
    for (var cj = 0; cj < runs.length; cj++) {
      var run = runs[cj];
      var cell = run.stops[st.stop_id];
      if (!cell) { h += '<td class="ltt-cell empty">&#183;</td>'; continue; }
      var cls = 'ltt-cell' + ((isToday && cell.ts < nowTs) ? ' past' : '');
      var delayHtml = '';
      if (cell.delay_mins !== null && cell.delay_mins !== 0) {
        delayHtml = '<span class="ltt-delay ' + (cell.delay_mins > 0 ? 'late' : 'early') + '">'
                  + (cell.delay_mins > 0 ? '+' : '') + cell.delay_mins + 'm</span>';
      }
      h += '<td class="' + cls + '">' + esc(cell.est || cell.sched) + delayHtml + '</td>';
    }
    h += '</tr>';
  }
  h += '</tbody></table></div>';

  content.innerHTML = h;
}

function renderGroups(data, dirFilter) {
  var groups = data.groups || [];
  var content = document.getElementById('content');

  if (!groups.length) {
    content.innerHTML = '<div class="state-empty"><div class="icon">&#128197;</div><strong>No services</strong><p>No departures found for this stop on this date.</p></div>';
    return;
  }

  // Current time for "past" colouring
  var nowTs = Math.floor(Date.now() / 1000);
  var isToday = (data.date === todayStr());
  var nowHHMM = isToday ? (new Date().getHours() * 60 + new Date().getMinutes()) : -1;

  var h = '';
  var shownGroups = 0;

  for (var gi = 0; gi < groups.length; gi++) {
    var g = groups[gi];

    // Direction filter
    if (dirFilter >= 0 && g.direction_id !== dirFilter) continue;
    shownGroups++;

    var rt = data.rt;
    var badgeLabel = g.route_number ? g.route_number : (rt === 0 ? 'TRN' : rt === 1 ? 'TRM' : rt === 2 ? 'BUS' : 'VLN');

    h += '<div class="route-group" id="rg' + gi + '">'
       +   '<div class="rg-header" onclick="toggleGroup(' + gi + ')">'
       +     '<span class="rg-badge t' + rt + '">' + esc(String(badgeLabel)) + '</span>'
       +     '<div class="rg-info">'
       +       '<div class="rg-direction">' + esc(g.direction || g.route_name || 'Unknown direction') + '</div>'
       +       '<div class="rg-meta">'
       +         (g.route_name ? esc(g.route_name) + ' \u00b7 ' : '')
       +         g.services.length + ' services'
       +       '</div>'
       +     '</div>'
       +     '<div class="rg-right">'
       +       '<span class="rg-count">' + g.services.length + '</span>'
       +       '<span class="rg-arrow">&#9660;</span>'
       +     '</div>'
       +   '</div>'
       +   '<div class="rg-body">';

    // Group services by hour
    var byHour = {};
    for (var si = 0; si < g.services.length; si++) {
      var svc = g.services[si];
      var hh  = svc.sched ? parseInt(svc.sched.split(':')[0], 10) : 0;
      if (!byHour[hh]) byHour[hh] = [];
      byHour[hh].push(svc);
    }

    var hours = Object.keys(byHour).sort(function(a,b){ return parseInt(a,10)-parseInt(b,10); });
    var nowInserted = false;

    for (var hi = 0; hi < hours.length; hi++) {
      var hh     = parseInt(hours[hi], 10);
      var svcs   = byHour[hours[hi]];
      var isPastHour    = isToday && hh < new Date().getHours();
      var isCurrentHour = isToday && hh === new Date().getHours();

      var bandCls = 'hour-band';
      if (isPastHour)    bandCls += ' past';
      if (isCurrentHour) bandCls += ' current';

      h += '<div class="' + bandCls + '">'
         +   '<div class="hb-hour">' + pad2(hh) + '</div>'
         +   '<div class="hb-services">';

      for (var sv = 0; sv < svcs.length; sv++) {
        var s     = svcs[sv];
        var mm    = s.sched ? parseInt(s.sched.split(':')[1], 10) : 0;
        var svcTs = s.ts;
        var secsAway = svcTs - nowTs;

        // Insert "now" marker line at the right position
        if (isToday && !nowInserted && secsAway > 0 && secsAway < 3600) {
          h += '</div></div>'; // close prev hour band
          h += '<div class="now-line"><span class="now-line-label">&#9654; Now</span></div>';
          h += '<div class="' + bandCls + '"><div class="hb-hour">' + pad2(hh) + '</div><div class="hb-services">';
          nowInserted = true;
        }

        var pillCls = 'svc-pill';
        if (s.is_past)    pillCls += ' past';
        else if (secsAway < 90)  pillCls += ' now';
        else if (secsAway < 600) pillCls += ' soon';
        if (s.express)    pillCls += ' express';

        // Tooltip content
        var ttRows = '';
        ttRows += '<div class="tt-row"><span class="tt-k">Sched</span><span class="tt-v">' + esc(s.sched || '—') + '</span></div>';
        if (s.est && s.est !== s.sched)
          ttRows += '<div class="tt-row"><span class="tt-k">Est</span><span class="tt-v" style="color:var(--grn)">' + esc(s.est) + '</span></div>';
        if (s.delay_mins !== null)
          ttRows += '<div class="tt-row"><span class="tt-k">Delay</span><span class="tt-v">' + (s.delay_mins === 0 ? 'On time' : (s.delay_mins > 0 ? '+' + s.delay_mins + ' min' : s.delay_mins + ' min')) + '</span></div>';
        if (s.platform)
          ttRows += '<div class="tt-row"><span class="tt-k">Platform</span><span class="tt-v">' + esc(String(s.platform)) + '</span></div>';
        if (s.dest)
          ttRows += '<div class="tt-row"><span class="tt-k">To</span><span class="tt-v">' + esc(s.dest) + '</span></div>';
        if (s.express)
          ttRows += '<div class="tt-row"><span class="tt-k">Type</span><span class="tt-v" style="color:var(--yel)">Express</span></div>';

        h += '<div class="' + pillCls + '" id="svc-' + esc(s.ts) + '"'
           +   (s.run_ref ? ' onclick="openPattern(event,\'' + esc(s.run_ref) + '\',' + data.rt + ',' + _stopId + ',\'' + esc(s.sched || '') + '\',\'' + esc(s.dest || '') + '\')"' : '')
           +   '>'
           +   '<span class="sp-time">' + esc(s.sched || '—') + '</span>';

        if (s.est && s.est !== s.sched)
          h += '<span class="sp-est">' + esc(s.est) + '</span>';

        if (s.delay_mins !== null) {
          if (s.delay_mins > 0)      h += '<span class="sp-delay late">+' + s.delay_mins + 'm</span>';
          else if (s.delay_mins < 0) h += '<span class="sp-delay early">' + s.delay_mins + 'm</span>';
          else                       h += '<span class="sp-delay ok">&#10003;</span>';
        }

        if (s.platform) h += '<span class="sp-plat">P' + esc(String(s.platform)) + '</span>';
        if (s.dest)     h += '<span class="sp-dest">' + esc(s.dest) + '</span>';

        h += '<div class="sp-tooltip">' + ttRows + '</div>';
        h += '</div>'; // svc-pill
      }

      h += '</div></div>'; // hb-services + hour-band
    }

    h += '</div></div>'; // rg-body + route-group
  }

  if (!shownGroups) {
    h = '<div class="state-empty"><div class="icon">&#128197;</div><strong>No services</strong><p>No services in this direction on this date.</p></div>';
  }

  content.innerHTML = h;

  // Append legend
  content.innerHTML += '<div class="legend">'
    + '<div class="lg-item"><div class="lg-swatch" style="background:rgba(124,109,250,.4);border:1px solid rgba(124,109,250,.6)"></div> Departing now</div>'
    + '<div class="lg-item"><div class="lg-swatch" style="background:rgba(124,109,250,.1);border:1px solid rgba(124,109,250,.3)"></div> Next up</div>'
    + '<div class="lg-item"><div class="lg-swatch" style="background:rgba(255,209,102,.08);border:1px solid rgba(255,209,102,.25)"></div> Express</div>'
    + '<div class="lg-item"><div class="lg-swatch" style="background:var(--surface2);opacity:.38"></div> Past</div>'
    + '<div class="lg-item" style="color:var(--grn);font-size:.58rem">&#9632; Green time = estimated</div>'
    + '</div>';
}

// ── Toggle route group ────────────────────────────────────────────────────────
function toggleGroup(i) {
  var el = document.getElementById('rg' + i);
  if (!el) return;
  el.className = el.className.indexOf('collapsed') !== -1
    ? el.className.replace(' collapsed','')
    : el.className + ' collapsed';
}

// ── Scroll to now ─────────────────────────────────────────────────────────────
function scrollToNow() {
  var nowLine = document.querySelector('.now-line');
  if (nowLine) {
    nowLine.scrollIntoView({ behavior:'smooth', block:'center' });
    return;
  }
  var pills = document.querySelectorAll('.svc-pill:not(.past)');
  if (pills.length) pills[0].scrollIntoView({ behavior:'smooth', block:'center' });
}

// ── Stopping pattern panel ────────────────────────────────────────────────────
function openPattern(evt, runRef, rt, stopId, schedTime, dest) {
  evt.stopPropagation();

  var panel = document.getElementById('pat-panel');
  var title = document.getElementById('pat-title');
  var meta  = document.getElementById('pat-meta');
  var body  = document.getElementById('pat-body');

  title.textContent = dest || 'Stopping pattern';
  meta.textContent  = (schedTime ? schedTime + ' departure' : '') + (stopId ? ' \u00b7 Stop #' + stopId : '');
  body.innerHTML    = '<div class="pat-sheet-loading"><span class="spin-ring"></span> Loading stops\u2026</div>';

  panel.className = 'show';
  document.body.style.overflow = 'hidden';

  var xhr = new XMLHttpRequest();
  xhr.open('GET',
    '?action=pattern'
    + '&run_ref='  + encodeURIComponent(runRef)
    + '&rt='       + encodeURIComponent(rt)
    + '&stop_id='  + encodeURIComponent(stopId || 0),
    true
  );
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) return;
    if (xhr.status !== 200) {
      body.innerHTML = '<div class="pat-sheet-err">&#9888; Server error ' + xhr.status + '</div>';
      return;
    }
    var d; try { d = JSON.parse(xhr.responseText); } catch(ex) {
      body.innerHTML = '<div class="pat-sheet-err">&#9888; Parse error</div>';
      return;
    }
    if (d.error) {
      body.innerHTML = '<div class="pat-sheet-err">&#9888; ' + esc(d.error) + '</div>';
      return;
    }
    meta.textContent = (schedTime ? schedTime + ' departure' : '')
      + (stopId ? ' \u00b7 Stop #' + stopId : '')
      + ' \u00b7 ' + (d.count || 0) + ' stops';
    body.innerHTML = renderPatternTimeline(d.stops || [], stopId);
    // Scroll to current stop
    var cur = body.querySelector('.pt-stop.is-current');
    if (cur) setTimeout(function(){ cur.scrollIntoView({ block:'center', behavior:'smooth' }); }, 120);
  };
  xhr.send();
}

function closePattern() {
  document.getElementById('pat-panel').className = '';
  document.body.style.overflow = '';
}

// Close on Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closePattern();
});

function renderPatternTimeline(stops, currentStopId) {
  if (!stops.length) return '<div class="pat-sheet-err">No stop data available.</div>';

  var curIdx = -1;
  for (var i = 0; i < stops.length; i++) {
    if (stops[i].is_current) { curIdx = i; break; }
  }

  var h = '<div class="pat-timeline">';

  for (var j = 0; j < stops.length; j++) {
    var s      = stops[j];
    var passed = curIdx >= 0 && j < curIdx;

    var cls = 'pt-stop';
    if (s.is_origin)   cls += ' is-origin';
    if (s.is_terminus) cls += ' is-terminus';
    if (s.is_current)  cls += ' is-current';
    if (s.at_plat)     cls += ' is-atplat';
    if (passed)        cls += ' passed';
    if (passed && j > 0) cls += ' passed-line';

    // Time — show estimated in green if it differs from scheduled
    var timeHtml = '';
    if (s.est && s.sched && s.est !== s.sched) {
      timeHtml = '<span class="pt-time live">' + esc(s.est) + '</span>';
    } else if (s.sched) {
      timeHtml = '<span class="pt-time">' + esc(s.sched) + '</span>';
    } else if (s.est) {
      timeHtml = '<span class="pt-time live">' + esc(s.est) + '</span>';
    } else {
      timeHtml = '<span class="pt-time">—</span>';
    }

    // Delay
    var delayHtml = '';
    if (s.delay_mins !== null) {
      if (s.delay_mins > 0)      delayHtml = '<span class="pt-delay late">+' + s.delay_mins + 'm</span>';
      else if (s.delay_mins < 0) delayHtml = '<span class="pt-delay early">' + s.delay_mins + 'm</span>';
      else                       delayHtml = '<span class="pt-delay ok">&#10003;</span>';
    }

    // Badges
    var badges = '';
    if (s.is_current)  badges += '<span class="pt-badge you">&#9654; Here</span>';
    if (s.is_origin)   badges += '<span class="pt-badge org">Origin</span>';
    if (s.is_terminus) badges += '<span class="pt-badge trm">Terminus</span>';
    if (s.at_plat)     badges += '<span class="pt-badge atp">&#9679; At platform</span>';
    if (s.platform)    badges += '<span class="pt-badge plt">Plt ' + esc(String(s.platform)) + '</span>';

    h += '<div class="' + cls + '">'
       +   '<span class="pt-name">' + esc(s.stop_name) + '</span>'
       +   (badges ? '<span class="pt-badges">' + badges + '</span>' : '')
       +   timeHtml
       +   delayHtml
       + '</div>';
  }

  h += '</div>';
  return h;
}
</script>
</body>
</html>
