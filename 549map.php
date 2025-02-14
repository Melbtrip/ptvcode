<!DOCTYPE html>
<html>
<head>
    <title>PTV Stops Map</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
</head>
<body>
    <div style="width: 50%; float:left; height: 600px;">
        <table id="stopTable">
            <thead>
                <tr>
                    <th>Stop ID</th>
                    <th>Stop Name</th>
                    <th>Zone</th>
                </tr>
            </thead>
            <tbody>
                <!-- Data rows will be inserted here dynamically -->
            </tbody>
        </table>
        <table id="departureTable">
            <thead>
                <tr>
                    <th>Route Number</th>
                    <th>Route Name</th>
                    <th>Direction Name</th>
                    <th>Departure Time</th>
                </tr>
            </thead>
            <tbody>
                <!-- Departure rows will be inserted here dynamically -->
            </tbody>
        </table>
    </div>
    <div id="map" style="width: 50%; height: 600px;"></div>

    <script>
        var map = L.map('map').setView([-37.8136, 144.9631], 10);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        <?php
        $Bus_icon = '';

// ptv codes goes here 
        $key = "";
        $developerId = ;

        $routeUrl = "/v3/stops/route/867/route_type/2?direction_id=30";
        function generateURLWithDevIDAndKey($apiEndpoint, $developerId, $key) {
            $apiEndpoint .= (strpos($apiEndpoint, '?') !== false ? '&' : '?') . 'devid=' . $developerId;
            $signature = strtoupper(hash_hmac("sha1", $apiEndpoint, $key, false));
            return 'https://timetableapi.ptv.vic.gov.au' . $apiEndpoint . '&signature=' . $signature;
        }

        $url = generateURLWithDevIDAndKey($routeUrl, $developerId, $key);
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        foreach ($data['stops'] as $stop) {
            $stopName = addslashes($stop['stop_name']);
            $stopID = $stop['stop_id'];
            $zone = isset($stop['stop_ticket']['ticket_zones']) ? implode(', ', $stop['stop_ticket']['ticket_zones']) : 'Unknown Zone';
            $departureUrl = generateURLWithDevIDAndKey("/v3/departures/route_type/2/stop/$stopID", $developerId, $key);
        ?>
            L.marker([<?php echo $stop['stop_latitude']; ?>, <?php echo $stop['stop_longitude']; ?>], {icon: L.icon({iconUrl: '<?php echo $Bus_icon; ?>', iconSize: [20, 20]})})
                .addTo(map)
                .bindPopup('<?php echo $stopName; ?><br/>Zone: <?php echo $zone; ?>')
                .on('click', async function() {
                    // Clear table rows except the header
                    var stopTable = document.getElementById('stopTable').getElementsByTagName('tbody')[0];
                    var departureTable = document.getElementById('departureTable').getElementsByTagName('tbody')[0];
                    stopTable.innerHTML = '';
                    departureTable.innerHTML = '';

                    // Insert Stop details into table
                    var stopRow = stopTable.insertRow();
                    stopRow.insertCell(0).innerHTML = '<?php echo $stopID; ?>';
                    stopRow.insertCell(1).innerHTML = '<?php echo $stopName; ?>';
                    stopRow.insertCell(2).innerHTML = '<?php echo $zone; ?>';

                    // Fetch departure data for this stop
                    let departuresUrl = '<?php echo $departureUrl; ?>';
                    let response = await fetch(departuresUrl);
                    let departureData = await response.json();

                    // Add Route details (Route Name, Route Number, Departure Time)
                    if (departureData.departures) {
                        const melbourneTimeZone = 'Australia/Melbourne';
                        const melbourneCurrentTime = new Date(new Date().toLocaleString('en-US', { timeZone: melbourneTimeZone }));
                        const routeCount = {};

                        for (const dep of departureData.departures) {
                            const routeId = dep.route_id;
                            const directionId = dep.direction_id;
                            const utcTime = new Date(dep.scheduled_departure_utc);
                            const melbourneDepartureTime = new Date(utcTime.toLocaleString('en-US', { timeZone: melbourneTimeZone }));

                            if (!routeCount[routeId]) {
                                routeCount[routeId] = 0;
                            }

                            // Only display upcoming departures and limit to 2 per route
                            if (melbourneDepartureTime > melbourneCurrentTime && routeCount[routeId] < 2) {
                                const routeDetails = await fetchRouteDetails(routeId); // Fetch Route Name and Route Number
                                const directionName = await fetchDirectionName(routeId, directionId); // Fetch Direction Name

                                // Add departure data row
                                let depRow = departureTable.insertRow();
                                depRow.insertCell(0).innerHTML = routeDetails.routeNumber;  // Route Number
                                depRow.insertCell(1).innerHTML = routeDetails.routeName;    // Route Name
                                depRow.insertCell(2).innerHTML = directionName;             // Direction Name

                                const melbourneTime = new Intl.DateTimeFormat('en-AU', {
                                    timeZone: melbourneTimeZone,
                                    hour: '2-digit',
                                    minute: '2-digit',
                                    second: '2-digit',
                                    hour12: true,
                                }).format(melbourneDepartureTime);

                                depRow.insertCell(3).innerHTML = melbourneTime;  // Departure Time

                                // Increment route count
                                routeCount[routeId]++;
                            }
                        }
                    }
                });
        <?php } ?>
        
        async function fetchRouteDetails(routeId) {
            const apiKey = '<?php echo $key; ?>';
            const developerId = <?php echo $developerId; ?>;
            const endpoint = `/v3/routes/${routeId}`;
            const url = generateURLWithDevIDAndKey(endpoint, developerId, apiKey);

            const response = await fetch(url);
            const data = await response.json();

            return {
                routeNumber: data.route.route_number,
                routeName: data.route.route_name
            };
        }

        async function fetchDirectionName(routeId, directionId) {
            const apiKey = '<?php echo $key; ?>';
            const developerId = <?php echo $developerId; ?>;
            const endpoint = `/v3/directions/route/${routeId}`;
            const url = generateURLWithDevIDAndKey(endpoint, developerId, apiKey);

            const response = await fetch(url);
            const data = await response.json();

            const direction = data.directions.find(d => d.direction_id === directionId);
            return direction ? direction.direction_name : 'Unknown Direction';
        }

        function generateURLWithDevIDAndKey(apiEndpoint, developerId, key) {
            apiEndpoint += (apiEndpoint.indexOf('?') !== -1 ? '&' : '?') + 'devid=' + developerId;
            const signature = CryptoJS.HmacSHA1(apiEndpoint, key).toString(CryptoJS.enc.Hex).toUpperCase();
            return 'https://timetableapi.ptv.vic.gov.au' + apiEndpoint + '&signature=' + signature;
        }
    </script>
</body>
</html>
