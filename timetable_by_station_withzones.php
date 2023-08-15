<?php
 $key = ""; // supplied by PTV
$developerId = ; // supplied by PTV

$max_results ="5";
 $route_number ="5";

$stop_number = "1228"; // Default stop number
$stop_name = "Mernda Station"; // Default stop name

if (isset($_POST['change_stop'])) {
    if (isset($_POST['stop_id']) && isset($_POST['stop_name'])) {
        $stop_number = $_POST['stop_id'];
        $stop_name = $_POST['stop_name'];
    }
}

$specificurl = "/v3/departures/route_type/0/stop/".$stop_number."?max_results=".$max_results."&include_cancelled=false&look_backwards=false";
$signedUrl = generateURLWithDevIDAndKey($specificurl, $developerId, $key);


function generateURLWithDevIDAndKey($apiEndpoint, $developerId, $key)
{
    if (strpos($apiEndpoint, '?') > 0) {
        $apiEndpoint .= "&";
    } else {
        $apiEndpoint .= "?";
    }
    $apiEndpoint .= "devid=" . $developerId;

    $signature = strtoupper(hash_hmac("sha1", $apiEndpoint, $key, false));

    return "https://timetableapi.ptv.vic.gov.au" . $apiEndpoint . "&signature=" . $signature;
}



$response = file_get_contents($signedUrl);
$data = json_decode($response, true);

function convertToMelbourneTime($utc_time) {
    $utc = new DateTime($utc_time, new DateTimeZone('UTC'));
    $utc->setTimezone(new DateTimeZone('Australia/Melbourne'));
    return $utc->format('H:i');
}

$routes_url = 'https://timetableapi.ptv.vic.gov.au/v3/routes?devid=' . $developerId . '&signature=' . $key;
$routes_data = file_get_contents($routes_url);
$routes = json_decode($routes_data, true);

$all_runs = array();
$directions_urls = array();

foreach ($routes['routes'] as $route) {
    $directionId = $route['route_directions'][0]['direction_id'];
    $directions_url = 'https://timetableapi.ptv.vic.gov.au/v3/directions/route/' . $directionId . '?devid=' . $developerId . '&signature=' . $key;
    array_push($directions_urls, $directions_url);
}

$all_directions = array();
foreach ($directions_urls as $url) {
    $directions_data = file_get_contents($url);
    $directions = json_decode($directions_data, true);
    $all_directions = array_merge($all_directions, $directions['directions']);
}

$current_time_melbourne = new DateTime('now', new DateTimeZone('Australia/Melbourne'));

?>

<html>
<head>
    <title>Departures Table</title>
    <style>
    html, body{
  margin:0 !important;
  padding:0 !important;
}
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid black;
            padding: 8px;
        }
        th {
            background-color: #f2f2f2;
        }
        .route-1, .route-2, .route-9, .route-7 {
            background-color: #00008B;
            color: #FFFFFF;
        }
        .route-8, .route-5 {
            background-color: #FF0000;
            color: #FFFFFF;
        }
        .route-3, .route-14, .route-15 {
            background-color: #FFFF00;
            color: #000000;
        }
        .route-16, .route-17, .route-6, .route-13 {
            background-color: #006400;
            color: #FFFFFF;
        }
        .route-4, .route-11 {
            background-color: #ADD8E6;
            color: #000000;
        }
        .route-12 {
            background-color: #FFC0CB;
            color: #FFFFFF;
        }
        .sign {
            background-color: blue;
            color: white;
        }

    .yellow-bg-black-text {
        background-color: yellow;
        color: black;
    }
    
    .blue-bg-white-text {
        background-color: blue;
        color: white;
    }
    
    .split-background {
        background: linear-gradient(to right, yellow 50%, blue 50%);
        display: inline-block;
        padding: 10px;
    }

</style>

</head>
<body>

<div style="width: 70%; float: right;">
    
    <table>
        <tr>
            <th colspan="2"><img src="https://www.melbtrip.com/assets/Uploads/train2.png" alt="Metro Services" width="50" height="50"></th>
            <th colspan="6" class="sign"><h2><?php echo $stop_name; ?></h2></th>
        </tr>
        <tr>
            <th>Route Name</th>
            <th>Route ID</th>
            <th>Destination Name</th>
            <th>Scheduled Departure (AEST)</th>
            <th>Estimated Departure (AEST)</th>
            <th>Scheduled Departure (Minutes) in</th>
            <th>Platform Number</th>
            <th>At Platform</th>
        </tr>
        <?php
        // URLs for API calls
        
        $departures_url = $signedUrl;
        
       // $specificurl = "/v3/departures/route_type/3/stop/1181"; // Replace with your specific URL
       $routesa = "/v3/routes";
$signedUrla = generateURLWithDevIDAndKey($routesa, $developerId, $key);
 $routes_url = $signedUrla;
 // 1
$Alamein_runa = "/v3/runs/route/1";
$Alamein_signedUrl = generateURLWithDevIDAndKey($Alamein_runa, $developerId, $key);
$Alamein_run = $Alamein_signedUrl;

// 2
$Belgrave_runa = "/v3/runs/route/2";
$Belgrave_signedUrl = generateURLWithDevIDAndKey($Belgrave_runa, $developerId, $key);
$Belgrave_run = $Belgrave_signedUrl;

// 3
$Craigieburn_runa = "/v3/runs/route/3";
$Craigieburn_signedUrl = generateURLWithDevIDAndKey($Craigieburn_runa, $developerId, $key);
$Craigieburn_run = $Craigieburn_signedUrl;
// 4
$Cranbourne_runa = "/v3/runs/route/4";
$Cranbourne_signedUrl = generateURLWithDevIDAndKey($Cranbourne_runa, $developerId, $key);
$Cranbourne_run = $Cranbourne_signedUrl;

// 5 Mernda
$Mernda_runa = "/v3/runs/route/5";
$Mernda_signedUrl = generateURLWithDevIDAndKey($Mernda_runa, $developerId, $key);
$Mernda_run = $Mernda_signedUrl;

//Frankston 6
$Frankston_runa = "/v3/runs/route/6";
$Frankston_signedUrl = generateURLWithDevIDAndKey($Frankston_runa, $developerId, $key);
$Frankston_run = $Frankston_signedUrl;

//Glen Waverley 7
$Glen_runa = "/v3/runs/route/7";
$Glen_signedUrl = generateURLWithDevIDAndKey($Glen_runa, $developerId, $key);
$Glen_run = $Glen_signedUrl;


//Hurstbridge 8
$Hurstbridge_runa = "/v3/runs/route/8";
$Hurstbridge_signedUrl = generateURLWithDevIDAndKey($Hurstbridge_runa, $developerId, $key);
$Hurstbridge_run = $Hurstbridge_signedUrl;

//Lilydale 9
$Lilydale_runa = "/v3/runs/route/9";
$Lilydale_signedUrl = generateURLWithDevIDAndKey($Lilydale_runa, $developerId, $key);
$Lilydale_run = $Lilydale_signedUrl;
//Pakenham 11
$Pakenham_runa = "/v3/runs/route/11";
$Pakenham_signedUrl = generateURLWithDevIDAndKey($Pakenham_runa, $developerId, $key);
$Pakenham_run = $Pakenham_signedUrl;

//Sandringham 12
$Sandringham_runa = "/v3/runs/route/12";
$Sandringham_signedUrl = generateURLWithDevIDAndKey($Sandringham_runa, $developerId, $key);
$Sandringham_run = $Sandringham_signedUrl;
//Stony Point 13
$Stony_runa = "/v3/runs/route/13";
$Stony_signedUrl = generateURLWithDevIDAndKey($Stony_runa, $developerId, $key);
$Stony_run = $Stony_signedUrl;
//Sunbury 14
$Sunbury_runa = "/v3/runs/route/14";
$Sunbury_signedUrl = generateURLWithDevIDAndKey($Sunbury_runa, $developerId, $key);
$Sunbury_run = $Sunbury_signedUrl;

//Upfield 15
$Upfield_runa = "/v3/runs/route/15";
$Upfield_signedUrl = generateURLWithDevIDAndKey($Upfield_runa, $developerId, $key);
$Upfield_run = $Upfield_signedUrl;

//Werribee 16
$Werribee_runa = "/v3/runs/route/16";
$Werribee_signedUrl = generateURLWithDevIDAndKey($Werribee_runa, $developerId, $key);
$Werribee_run = $Werribee_signedUrl;


//Williamstown 17
$Williamstown_runa = "/v3/runs/route/17";
$Williamstown_signedUrl = generateURLWithDevIDAndKey($Williamstown_runa, $developerId, $key);
$Williamstown_run = $Williamstown_signedUrl;

//Showgrounds - Flemington Racecourse 1482
$Showgrounds_runa = "/v3/runs/route/1482";
$Showgrounds_signedUrl = generateURLWithDevIDAndKey($Showgrounds_runa, $developerId, $key);
$Showgrounds_run = $Showgrounds_signedUrl;

if ($stop_number === "1181" || $stop_number === "1071" || $stop_number === "1068" || $stop_number === "1120" || $stop_number === "1155") {
    $runs_urls = array(
        $Alamein_run,
        $Belgrave_run,
        $Craigieburn_run,
        $Cranbourne_run,
        $Mernda_run,
        $Frankston_run,
        $Glen_run,
        $Hurstbridge_run,
        $Lilydale_run,
        $Pakenham_run,
        $Sandringham_run,
        $Sunbury_run,
        $Werribee_run,
        $Upfield_run,
        $Williamstown_run,
        $Showgrounds_run,
        $Stony_run
        // Add more URLs for runs here, separated by commas
    );
} else {
    $runs_urls = array(
        $Mernda_run,
        $Hurstbridge_run
        // Add more URLs for runs here, separated by commas
    );
}
// Rest of your code

        // Fetch JSON data for departures
        $departures_data = file_get_contents($departures_url);
        $data = json_decode($departures_data, true);

        // Fetch JSON data for routes
        $routes_data = file_get_contents($routes_url);
        $routes = json_decode($routes_data, true);

        // Fetch JSON data for runs
        $all_runs = array();
        foreach ($runs_urls as $url) {
            $runs_data = file_get_contents($url);
            $runs = json_decode($runs_data, true);
            $all_runs = array_merge($all_runs, $runs['runs']);
        }

        // Fetch JSON data for directions
        $all_directions = array();
        foreach ($directions_urls as $url) {
            $directions_data = file_get_contents($url);
            $directions = json_decode($directions_data, true);
            $all_directions = array_merge($all_directions, $directions['directions']);
        }

        // Get current time in UTC to compare with scheduled departure time
        $current_time_utc = new DateTime('now', new DateTimeZone('UTC'));

        // Process and display the data
        foreach ($data['departures'] as $departure) {
            // Convert the scheduled and estimated departure times to Melbourne time
            $scheduled_departure_melbourne = convertToMelbourneTime($departure['scheduled_departure_utc']);
            $estimated_departure_melbourne = convertToMelbourneTime($departure['estimated_departure_utc']);

            // Find the matching route name
            $route_name = 'Unknown Route';
            foreach ($routes['routes'] as $route) {
                if ($route['route_id'] === $departure['route_id']) {
                    $route_name = $route['route_name'];
                    break;
                }
            }

            // Find the matching run details (including destination name)
            $run_details = null;
            foreach ($all_runs as $run) {
                if ($run['run_id'] === $departure['run_id']) {
                    $run_details = $run;
                    break;
                }
            }

            // Get the destination name using the direction ID from run details
            $destination_name = 'Unknown Destination';
            if ($run_details) {
                foreach ($all_directions as $direction) {
                    if ($direction['direction_id'] === $run_details['direction_id']) {
                        $destination_name = $direction['direction_name'];
                        break;
                    }
                }
            }

         // Calculate the estimated departure time in minutes from the current time (in Melbourne time)
$scheduled_time_melbourne = new DateTime($departure['scheduled_departure_utc'], new DateTimeZone('UTC'));
$scheduled_time_melbourne->setTimezone(new DateTimeZone('Australia/Melbourne'));
$estimated_time_melbourne = new DateTime($departure['estimated_departure_utc'], new DateTimeZone('UTC'));
$estimated_time_melbourne->setTimezone(new DateTimeZone('Australia/Melbourne'));
$current_time_melbourne = new DateTime('now', new DateTimeZone('Australia/Melbourne'));
//$estimated_minutes = $current_time_melbourne->diff($scheduled_time_melbourne)->format('%h:%i');
//$estimated_minutes = $current_time_melbourne->diff($scheduled_time_melbourne)->format('%i');
$interval = $current_time_melbourne->diff($scheduled_time_melbourne);
$estimated_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
//echo "Estimated Departure Time Difference: $estimated_minutes minutes";
           // $estimated_minutes = $current_time_melbourne->diff($estimated_time_melbourne)->format('%h:%i');
          echo '<tr>';
           echo '<td class="route-' . $departure['route_id'] . '">' . $route_name . '</td>';
            echo '<td ' . $departure['route_id'] . '>' . $departure['route_id'] . '</td>';
            echo '<td>' . $run_details['destination_name'] . '</td>';
            echo '<td align=\'center\'>' . $scheduled_departure_melbourne . '</td>';
            echo '<td align=\'center\'>' . $estimated_departure_melbourne . '</td>';
            echo '<td align=\'center\'>' . $estimated_minutes . " mins".'</td>';
            echo '<td align=\'center\'>' . $departure['platform_number'] . '</td>';
            echo '<td align=\'center\'>' . ($departure['at_platform'] ? 'Yes' : 'No') . '</td>';
            
            echo '</tr>';
        }
        ?>
    </table>
    </div>
  <div style="width: 30%; text-align: left;">
      
    <?php
    // Fetch JSON data for the specified URL
$line_stopurl = "/v3/stops/route/".$route_number."/route_type/0?direction_id=1";
$line_stopurl_signed = generateURLWithDevIDAndKey($line_stopurl, $developerId, $key);
echo "<br/>";

//echo $line_stopurl_signed;
$line_stop_data = file_get_contents($line_stopurl_signed);
$line_stops = json_decode($line_stop_data, true);

// Function to compare stops by their stop_sequence
function compareStops($a, $b) {
    return $a['stop_sequence'] - $b['stop_sequence'];
}

// Sort the stops array based on stop_sequence
usort($line_stops['stops'], 'compareStops');

// Output the fetched data in a table
echo '<table>';
echo '<tr>';
echo '<td colspan="4"><h2>List of Stations</h2></td>';
echo '</tr>';
echo '<tr>';
echo '<th>Stop ID</th>';
echo '<th>Stop Name</th>';
echo '<th colspan="2">Zone</th>';
echo '</tr>';
foreach ($line_stops['stops'] as $stop) {
    $zone = $stop['stop_ticket']['zone'];

    echo '<tr>';
    echo '<td>' . $stop['stop_id'] . '</td>';
    echo '<td>';
    echo '<form method="post">';
    echo '<input type="hidden" name="stop_id" value="' . $stop['stop_id'] . '">';
    echo '<input type="hidden" name="stop_name" value="' . $stop['stop_name'] . '">';
    echo '<button type="submit" name="change_stop" value="Change">' . $stop['stop_name'] . '</button>';
    echo '</form>';
    
    if ($zone === "Zone 1") {
        echo '<td colspan="2" class="yellow-bg-black-text">' . $zone . '</td>';
    } elseif ($zone === "Zone 2") {
        echo '<td colspan="2" class="blue-bg-white-text">' . $zone . '</td>';
    } elseif ($zone === "Zone 1,Zone 2") {
        $zones = explode(',', $zone);
        echo '<td class="yellow-bg-black-text">' . $zones[0] . '</td>';
        echo '<td class="blue-bg-white-text">' . $zones[1] . '</td>';
    } else {
        echo '<td>' . $zone . '</td>';
    }
    
    echo '</tr>';
}

echo '</table>';
?>

 </div>  

<?php
session_start();

if (isset($_POST['change_stop'])) {
    if (isset($_POST['stop_id'])) {
        $_SESSION['stop_number'] = $_POST['stop_id'];
    }
}
?>

</body>
</html>
