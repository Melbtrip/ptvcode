<?php
// Initiate curl session in a variable (resource)
$curl_handle = curl_init();

$url = "https://melbtrip.com/api/json/matt.json";

// Set the curl URL option
curl_setopt($curl_handle, CURLOPT_URL, $url);

// This option will return data as a string instead of direct output
curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);

// Execute curl & store data in a variable
$curl_data = curl_exec($curl_handle);

curl_close($curl_handle);

// Decode JSON into PHP array
$response_data = json_decode($curl_data);

// Print all data if needed
// print_r($response_data);
// die();

// All user data exists in 'data' object
$user_data = $response_data->data;

// Extract only first 5 user data (or 5 array elements)
$user_data = array_slice($user_data, 0);

	echo "<table>";
 echo "<tr>";
   echo "<th>Min zone</th>";
   echo "<th>Max zone</th>";
   echo" <th>Fare type</th>";
   echo" <th>Two Hour (single Fare)</th>";
    echo" <th>Daily Cap</th>";
  echo "</tr>";

// Traverse array and print employee data
foreach ($user_data as $user) {
//	echo "Min zone: ".$user->min_zone;
//	echo "Max zone: ".$user->max_zone;
//	echo "PassengerType: ".$user->PassengerType;
//	echo "<br />";

   echo" <tr>";
   echo "<th>";
   echo $user->min_zone;
   echo "</th>";
     echo "<th>";
   echo $user->max_zone;
   echo "</th>"; 
     echo "<th>";
   echo $user->PassengerType;
   echo "</th>";
        echo "<th>";
   echo "$", $user->Fare2HourPeak;
   echo "</th>";
     echo "<th>";
   echo "$", $user->FareDailyPeak;
   echo "</th>";
  echo "</tr>";
	
}

 echo "</table>";
?>
