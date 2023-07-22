
<?php
    header("refresh: 15;");
?>
<?php
// Function to make the API request and get JSON response
function get_api_data($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// API URL
$urlA = "https://timetableapi.ptv.vic.gov.au";
$devA = "";
$signatureA = "";
$depA = "/v3/routes";

// Build the parameter string for the API request
$params = 'devid=' . $devA . '&signature=' . $signatureA;
// Combine the API URL with the endpoint and parameters
$api_url = $urlA . $depA . '?' . $params;
//$data = json_decode($json, true);


// Fetch data from the API
$json_data = get_api_data($api_url);

// Decode JSON data
$data = json_decode($json_data, true);

// Route ID to Text Mapping (Modify this array with actual route IDs and their names)
$route_id_to_text = array();
if (isset($data['routes'])) {
    foreach ($data['routes'] as $route) {
        $route_id_to_text[$route['route_id']] = $route['route_name'];
    }
} else {
    echo 'Failed to fetch route data from the API.';
    exit;
}
// Direction ID to Text Mapping (Modify this array with actual direction IDs and their names)
$direction_id_to_text = array(
    '0'=> '1',
    '1' => 'Flinders Street',
    '2' => '2n',
    '3' => '3',
    '4' => '4',
    '5' => '5',
    '6' => '6',
    '7' => '7',
    '8' => '8',
    '9' => 'Ballarat',
    '10' => '10',
    '11' => '11',
    '12' => 'Bairnsdale',
    '13' => '13',
    '14' => 'Bendigo',
    '15' => '15',
    '16'=> '16',
    '17'=> '17',
    '18'=> 'ab',
    '19'=> 'abc',
    '20'=> 'abcd',
    '21'=> 'Geelong',
    '22'=> 'abcdef',
    // Add more directions as needed
);


// Example usage:
$url = "https://timetableapi.ptv.vic.gov.au";
$dev = "";
$signature = "";
$dep = "/v3/departures/route_type/3/stop/1181?max_results=15";

$json = file_get_contents($url . $dep . '&devid=' . $dev . '&signature=' . $signature);
$data = json_decode($json, true);

?>

<!DOCTYPE html>
<html>
<head>
    <title>Departures</title>
    <style>
        /* Burnley group Dark Blue lines  */
        .route-1, .route-2, .route-9, .route-7 {
            background-color: #00008B;
            color: #FFFFFF;
        }
        /* Clifton Hill  group Red lines  */
        .route-8 ,.route-5 {
            background-color: #FF0000;
            color: #FFFFFF;
        }
        /* Northern group group yellow lines  */
        .route-3, .route-14, .route-15 {
            background-color: #FFFF00;
            color: #000000;
        }
        /*Cross-City group green */
        .route-16, .route-17, .route-6 {
            background-color: #006400;
            color: #FFFFFF;
        }
        /*Caulfield group light blue*/
        .route-4, .route-11 {
            background-color: #ADD8E6;
            color: #000000;
        }
        /*Caulfield group pink*/
        .route-12 {
            background-color: #FFC0CB;
            color: #ffffff;
        }

        /* Add more classes for other routes as needed */
    </style>
</head>
<body>
<?php
if (isset($data['departures'])) {
    $departures = $data['departures'];
    echo '<h1>'.'Southern Cross Station'.'</h1>';
    echo '<table id="myTable" align="center" >';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Route</th>';
   // echo '<th>Direction</th>';
    echo '<th>Scheduled Departure (Local Time)</th>';
   // echo '<th>Estimated Departure (Local Time)</th>';
    //echo '<th>At Platform</th>';
    //echo '<th>Platform Number</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($departures as $departure) {
        // Convert route_id to text using the mapping
        $route_id = $departure['route_id'];
        $route_name = isset($route_id_to_text[$route_id]) ? $route_id_to_text[$route_id] : 'Unknown Route';

        // Convert direction_id to text using the mapping
        $direction_id = $departure['direction_id'];
        $direction_name = isset($direction_id_to_text[$direction_id]) ? $direction_id_to_text[$direction_id] : 'Unknown Direction';

        // Add the appropriate CSS class to the table row based on the route ID
        $css_class = 'route-' . $route_id;

        echo '<tr>';
        echo '<td class="' . $css_class . '">' . $route_name . '</td>';
      //  echo '<td>' . $direction_name . '</td>';
        echo '<td align="center">' . date('H:i', strtotime($departure['scheduled_departure_utc'])) . '</td>';
       // echo '<td align="center">' . date('H:i', strtotime($departure['estimated_departure_utc'])) . '</td>';
       // echo '<td align="center">' . ($departure['at_platform'] ? 'Yes' : 'No') . '</td>';
       // echo '<td align="center">' . $departure['platform_number'] . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
} else {
    echo 'No departures found.';
}
?>
</body>
</html>
