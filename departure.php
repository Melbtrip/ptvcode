

<?php
$url = "https://timetableapi.ptv.vic.gov.au";
$dev = "";
$signature ="";
$dep ="/v3/departures/route_type/0/stop/1071?max_results=10";

//$json  =file_get_contents('');


$json  =file_get_contents($url. $dep .'&devid='.$dev.'&signature='.$signature);

$data = json_decode($json, true);

if (isset($data['departures'])) {
    $departures = $data['departures'];
    echo'Flinders Street';
   echo '<table id="myTable">';
    echo '<thead>';
    echo '<tr>';
    //echo '<th>Stop ID</th>';
    echo '<th>Route ID</th>';
    echo '<th>Run ID</th>';
    echo '<th>Direction ID</th>';
    echo '<th>Scheduled Departure (UTC)</th>';
    echo '<th>Estimated Departure (UTC)</th>';
    echo '<th>At Platform</th>';
    echo '<th>Platform Number</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($departures as $departure) {
        echo '<tr>';
       // echo '<td>' . $departure['stop_id'] . '</td>';
        echo '<td>' . $departure['route_id'] . '</td>';
        echo '<td>' . $departure['run_id'] . '</td>';
        echo '<td>' . $departure['direction_id'] . '</td>';
        echo '<td>' . $departure['scheduled_departure_utc'] . '</td>';
        echo '<td>' . $departure['estimated_departure_utc'] . '</td>';
        echo '<td>' . ($departure['at_platform'] ? 'Yes' : 'No') . '</td>';
        echo '<td>' . $departure['platform_number'] . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
} else {
    echo 'No departures found.';
}

?>


