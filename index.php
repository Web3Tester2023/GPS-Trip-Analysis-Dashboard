<?php
/**
 * GPS Trip Visualizer
 *
 * This script reads a CSV file of GPS data points, processes them into trips,
 * and displays them on an interactive map using Leaflet.js.
 * It also shows processing statistics and the content of the rejects log.
 *
 * @version 1.0
 * @author John Michael | Web3mike.xyz
 */

// --- Configuration ---
ini_set('memory_limit', '512M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

const INPUT_CSV = 'points.csv';
const REJECTS_LOG = 'rejects.log';
const TIME_GAP_THRESHOLD = 25 * 60; // 25 minutes in seconds
const DISTANCE_JUMP_THRESHOLD = 2.0; // 2 kilometers

// --- Core PHP Processing Logic ---

function processGpsData() {
    // Clear previous rejects log
    if (file_exists(REJECTS_LOG)) {
        unlink(REJECTS_LOG);
    }

    // 1. Read and Clean Data
    list($points, $rejectedCount, $totalRows) = readAndCleanCsv(INPUT_CSV);
    
    $features = [];
    if (!empty($points)) {
        // 2. Order by Timestamp
        usort($points, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        // 3. Split into Trips
        $trips = splitIntoTrips($points);

        // 4. Compute Trip Statistics and Prepare GeoJSON Features
        $tripColors = ['#e6194B', '#3cb44b', '#ffe119', '#4363d8', '#f58231', '#911eb4', '#42d4f4', '#f032e6', '#bfef45', '#fabed4', '#808000', '#ffd8b1'];
        $colorCount = count($tripColors);

        foreach ($trips as $index => $trip) {
            if (count($trip) < 2) continue;

            $tripStats = calculateTripStats($trip);

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'trip_id' => 'trip_' . ($index + 1),
                    'color' => $tripColors[$index % $colorCount],
                    'total_distance_km' => round($tripStats['total_distance'], 2),
                    'duration_min' => round($tripStats['duration'] / 60, 2),
                    'avg_speed_kmh' => round($tripStats['avg_speed'], 2),
                    'max_speed_kmh' => round($tripStats['max_speed'], 2),
                    'point_count' => count($trip),
                    'start_time' => date('Y-m-d H:i:s', $trip[0]['timestamp']),
                    'end_time' => date('Y-m-d H:i:s', end($trip)['timestamp']),
                ],
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => array_map(function ($point) {
                        return [$point['lon'], $point['lat']];
                    }, $trip)
                ]
            ];
        }
    }
    
    // After processing, get the content of the log file
    $rejectLogContent = file_exists(REJECTS_LOG) ? file_get_contents(REJECTS_LOG) : 'No rejected rows.';

    return [
        'geoJson' => ['type' => 'FeatureCollection', 'features' => $features],
        'stats' => [
            'total_rows' => $totalRows,
            'valid_points' => count($points),
            'rejected_rows' => $rejectedCount
        ],
        'reject_log_content' => $rejectLogContent
    ];
}


// --- Helper Functions ---

function readAndCleanCsv(string $filename): array {
    $points = []; $rejectedCount = 0; $totalRows = 0;
    if (!file_exists($filename) || !is_readable($filename)) { return [$points, $rejectedCount, $totalRows]; }
    if (($handle = fopen($filename, 'r')) !== FALSE) {
        fgetcsv($handle); // Skip header
        while (($data = fgetcsv($handle)) !== FALSE) {
            $totalRows++;
            if (count($data) < 4) { logReject(implode(',', $data), "Invalid column count"); $rejectedCount++; continue; }
            $lat = filter_var($data[1], FILTER_VALIDATE_FLOAT);
            $lon = filter_var($data[2], FILTER_VALIDATE_FLOAT);
            $timestamp = strtotime($data[3]);
            if ($lat === false || $lat < -90 || $lat > 90 || $lon === false || $lon < -180 || $lon > 180 || $timestamp === false) {
                logReject(implode(',', $data), "Invalid coordinates or timestamp"); $rejectedCount++; continue;
            }
            $points[] = ['device_id' => $data[0], 'lat' => $lat, 'lon' => $lon, 'timestamp' => $timestamp];
        }
        fclose($handle);
    }
    return [$points, $rejectedCount, $totalRows];
}

function splitIntoTrips(array $points): array {
    if (empty($points)) return [];
    $trips = []; $currentTrip = [$points[0]];
    for ($i = 1; $i < count($points); $i++) {
        $prevPoint = $points[$i - 1]; $currentPoint = $points[$i];
        $timeDiff = $currentPoint['timestamp'] - $prevPoint['timestamp'];
        $distance = haversineGreatCircleDistance($prevPoint['lat'], $prevPoint['lon'], $currentPoint['lat'], $currentPoint['lon']);
        if ($timeDiff > TIME_GAP_THRESHOLD || $distance > DISTANCE_JUMP_THRESHOLD) {
            $trips[] = $currentTrip; $currentTrip = [];
        }
        $currentTrip[] = $currentPoint;
    }
    if (!empty($currentTrip)) $trips[] = $currentTrip;
    return $trips;
}

function calculateTripStats(array $trip): array {
    $totalDistance = 0; $maxSpeed = 0;
    for ($i = 1; $i < count($trip); $i++) {
        $p1 = $trip[$i - 1]; $p2 = $trip[$i];
        $distance = haversineGreatCircleDistance($p1['lat'], $p1['lon'], $p2['lat'], $p2['lon']);
        $totalDistance += $distance;
        $timeDiffSeconds = $p2['timestamp'] - $p1['timestamp'];
        if ($timeDiffSeconds > 0) {
            $speed = ($distance / ($timeDiffSeconds / 3600));
            if ($speed > $maxSpeed) $maxSpeed = $speed;
        }
    }
    $durationSeconds = end($trip)['timestamp'] - $trip[0]['timestamp'];
    $avgSpeed = ($durationSeconds > 0) ? ($totalDistance / ($durationSeconds / 3600)) : 0;
    return ['total_distance' => $totalDistance, 'duration' => $durationSeconds, 'avg_speed' => $avgSpeed, 'max_speed' => $maxSpeed];
}

function haversineGreatCircleDistance(float $latFrom, float $lonFrom, float $latTo, float $lonTo, float $earthRadius = 6371.0): float {
    $latFrom = deg2rad($latFrom); $lonFrom = deg2rad($lonFrom);
    $latTo = deg2rad($latTo); $lonTo = deg2rad($lonTo);
    $latDelta = $latTo - $latFrom; $lonDelta = $lonTo - $lonFrom;
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
}

function logReject(string $rowData, string $reason): void {
    $logEntry = date('Y-m-d H:i:s') . " | REJECTED: $rowData | REASON: $reason\n";
    file_put_contents(REJECTS_LOG, $logEntry, FILE_APPEND);
}

// Execute the processing function to get all the page data
$pageData = processGpsData();
$geoJsonData = $pageData['geoJson'];
$processingStats = $pageData['stats'];
$rejectLogContent = $pageData['reject_log_content'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Trip Visualizer</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Leaflet CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; }
        /* Custom style for Leaflet popups to match UI */
        .leaflet-popup-content-wrapper { border-radius: 8px; }
        .leaflet-popup-content { font-size: 14px; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">

    <header class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
            <h1 class="text-2xl font-bold text-gray-900">GPS Trip Analysis Dashboard</h1>
        </div>
    </header>

    <main class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Processing Summary Section -->
            <div class="mb-8">
                <h2 class="text-xl font-semibold text-gray-700 mb-4">Processing Summary</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <div class="bg-white p-5 rounded-lg shadow">
                        <p class="text-sm font-medium text-gray-500">Total Rows Read</p>
                        <p class="mt-1 text-3xl font-semibold text-indigo-600"><?= htmlspecialchars($processingStats['total_rows']) ?></p>
                    </div>
                    <div class="bg-white p-5 rounded-lg shadow">
                        <p class="text-sm font-medium text-gray-500">Valid Points Processed</p>
                        <p class="mt-1 text-3xl font-semibold text-green-600"><?= htmlspecialchars($processingStats['valid_points']) ?></p>
                    </div>
                    <div class="bg-white p-5 rounded-lg shadow">
                        <p class="text-sm font-medium text-gray-500">Rejected Rows</p>
                        <p class="mt-1 text-3xl font-semibold text-red-600"><?= htmlspecialchars($processingStats['rejected_rows']) ?></p>
                    </div>
                </div>
                <div class="mt-5 bg-white rounded-lg shadow">
                    <details class="p-5">
                        <summary class="font-medium text-indigo-600 cursor-pointer">Click to View Rejects Log</summary>
                        <div class="mt-4">
                            <pre class="bg-gray-800 text-white p-4 rounded-md text-sm leading-6 overflow-x-auto"><?= htmlspecialchars($rejectLogContent) ?></pre>
                        </div>
                    </details>
                </div>
            </div>

            <!-- Main Content: Map and Table -->
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-8">
                
                <!-- Map -->
                <div class="lg:col-span-3 bg-white p-2 rounded-lg shadow">
                    <div id="map" class="h-[60vh] lg:h-[75vh] rounded-md"></div>
                </div>

                <!-- Trip Summary Table -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-lg shadow overflow-hidden">
                        <div class="p-5">
                            <h2 class="text-xl font-semibold text-gray-700">Trip Details</h2>
                        </div>
                        <?php if (empty($geoJsonData['features'])): ?>
                            <p class="p-5 text-gray-500">No trip data to display. Please check your 'points.csv' file.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trip</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Distance</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Speed</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($geoJsonData['features'] as $feature): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <div class="flex items-center">
                                                        <div class="h-2.5 w-2.5 rounded-full mr-3" style="background-color: <?= htmlspecialchars($feature['properties']['color']) ?>;"></div>
                                                        <?= htmlspecialchars(str_replace('_', ' ', $feature['properties']['trip_id'])) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($feature['properties']['total_distance_km']) ?> km</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($feature['properties']['duration_min']) ?> min</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($feature['properties']['avg_speed_kmh']) ?> km/h</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        const geoJsonData = <?= json_encode($geoJsonData, JSON_NUMERIC_CHECK); ?>;
        const map = L.map('map', {
            scrollWheelZoom: false // Optional: prevent zooming with scroll wheel
        });
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        if (geoJsonData.features.length > 0) {
            const geoJsonLayer = L.geoJSON(geoJsonData, {
                style: function(feature) {
                    return { color: feature.properties.color, weight: 5, opacity: 0.85 };
                },
                onEachFeature: function(feature, layer) {
                    const props = feature.properties;
                    const popupContent = `
                        <div class="space-y-1">
                            <p class="font-bold text-base">${props.trip_id.replace('_', ' ')}</p>
                            <p><b>Distance:</b> ${props.total_distance_km} km</p>
                            <p><b>Duration:</b> ${props.duration_min} min</p>
                            <p><b>Avg Speed:</b> ${props.avg_speed_kmh} km/h</p>
                            <p><b>Max Speed:</b> ${props.max_speed_kmh} km/h</p>
                        </div>
                    `;
                    layer.bindPopup(popupContent);
                }
            }).addTo(map);
            map.fitBounds(geoJsonLayer.getBounds().pad(0.1));
        } else {
            map.setView([14.3112, 121.0429], 12); // Default to Carmona, PH
        }
    </script>

</body>
</html>
