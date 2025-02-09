<?php

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    // Haversine formula to calculate distance between two points
    $earthRadius = 6371000; // Earth's radius in meters
    
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);
    
    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;
    
    $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earthRadius * $c;
}

function parseGPX($gpxFile) {
    // Load the GPX file
    $xml = simplexml_load_file($gpxFile);
    
    if (!$xml) {
        return json_encode(['error' => 'Invalid GPX file']);
    }

    // Register the GPX namespace
    $xml->registerXPathNamespace('gpx', 'http://www.topografix.com/GPX/1/1');
    
    // Initialize array to store trackpoints
    $trackpoints = [];
    
    // Variables for splits analysis
    $splits = [];
    $currentSplit = null;
    $totalDistance = 0;
    $prevPoint = null;
    $elevationThreshold = 1; // Minimum elevation change to consider (meters)
    $distanceThreshold = 400; // Minimum distance for a split (meters)
    
    // Find all trackpoint elements
    $points = $xml->xpath('//gpx:trkpt');
    
    foreach ($points as $point) {
        $currentPoint = [
            'latitude' => (float)$point['lat'],
            'longitude' => (float)$point['lon'],
            'elevation' => isset($point->ele) ? (float)$point->ele : null,
        ];
        
        // Calculate distance from start for trackpoint
        if ($prevPoint) {
            $distance = calculateDistance(
                $prevPoint['latitude'],
                $prevPoint['longitude'],
                $currentPoint['latitude'],
                $currentPoint['longitude']
            );
            $totalDistance += $distance;
        }
        
        // Calculate grade using previous point
        $grade = 0;
        if ($prevPoint && $currentPoint['elevation'] !== null && $prevPoint['elevation'] !== null) {
            $elevationChange = $currentPoint['elevation'] - $prevPoint['elevation'];
            if ($distance > 0) {
                $grade = round(($elevationChange / $distance) * 100, 2);
            }
        }
        
        $trackpoints[] = [
            'latitude' => $currentPoint['latitude'],
            'longitude' => $currentPoint['longitude'],
            'elevation' => $currentPoint['elevation'],
            'distance' => round($totalDistance, 2), // Add distance from start
            'grade' => $grade // Add grade percentage
        ];
        
        if ($prevPoint && $currentPoint['elevation'] !== null && $prevPoint['elevation'] !== null) {
            // Calculate elevation change
            $elevationChange = $currentPoint['elevation'] - $prevPoint['elevation'];
            
            // Determine if we need to start a new split or continue current one
            if ($currentSplit === null) {
                if (abs($elevationChange) >= 1) { // Minimum 1m change to start a split
                    $currentSplit = [
                        'type' => $elevationChange > 0 ? 'ascent' : 'descent',
                        'start_distance' => $totalDistance - $distance,
                        'start_elevation' => $prevPoint['elevation'],
                        'total_elevation_change' => 0,
                        'total_distance' => 0
                    ];
                }
            } else {
                $isAscending = $elevationChange > 0;
                $splitType = $isAscending ? 'ascent' : 'descent';
                
                // If direction changes significantly, end current split
                if ($currentSplit['type'] !== $splitType && abs($elevationChange) >= $elevationThreshold) {
                    // Check if current split meets minimum distance requirement
                    if ($currentSplit['total_distance'] >= $distanceThreshold) {
                        $currentSplit['end_distance'] = $totalDistance - $distance;
                        $currentSplit['end_elevation'] = $prevPoint['elevation'];
                        $currentSplit['average_grade'] = round(($currentSplit['total_elevation_change'] / $currentSplit['total_distance']) * 100, 2);
                        $splits[] = $currentSplit;
                        
                        // Start new split
                        $currentSplit = [
                            'type' => $splitType,
                            'start_distance' => $totalDistance - $distance,
                            'start_elevation' => $prevPoint['elevation'],
                            'total_elevation_change' => 0,
                            'total_distance' => 0
                        ];
                    } else {
                        // If current split is too short, merge it with the new direction
                        $currentSplit['type'] = $splitType;
                        // Reset elevation change since we're changing direction
                        $currentSplit['total_elevation_change'] = 0;
                    }
                }
            }
            
            // Update current split if exists
            if ($currentSplit !== null) {
                $currentSplit['total_distance'] += $distance;
                $currentSplit['total_elevation_change'] += $elevationChange;
            }
        }
        
        $prevPoint = $currentPoint;
    }
    
    // Add final split if exists
    if ($currentSplit !== null && $currentSplit['total_distance'] > 0) {
        // Only add final split if it meets the distance threshold
        if ($currentSplit['total_distance'] >= $distanceThreshold) {
            $currentSplit['end_distance'] = $totalDistance;
            $currentSplit['end_elevation'] = $prevPoint['elevation'];
            $currentSplit['average_grade'] = round(($currentSplit['total_elevation_change'] / $currentSplit['total_distance']) * 100, 2);
            $splits[] = $currentSplit;
        } else if (count($splits) > 0) {
            // Merge short final split with the previous split
            $lastSplit = array_pop($splits);
            $lastSplit['end_distance'] = $totalDistance;
            $lastSplit['end_elevation'] = $prevPoint['elevation'];
            $lastSplit['total_distance'] += $currentSplit['total_distance'];
            $lastSplit['total_elevation_change'] += $currentSplit['total_elevation_change'];
            $lastSplit['average_grade'] = round(($lastSplit['total_elevation_change'] / $lastSplit['total_distance']) * 100, 2);
            $splits[] = $lastSplit;
        }
    }
    
    // Merge adjacent splits of the same type
    if (count($splits) > 1) {
        $mergedSplits = [];
        $currentMergedSplit = $splits[0];
        
        for ($i = 1; $i < count($splits); $i++) {
            if ($splits[$i]['type'] === $currentMergedSplit['type']) {
                // Merge with current split
                $currentMergedSplit['end_distance'] = $splits[$i]['end_distance'];
                $currentMergedSplit['end_elevation'] = $splits[$i]['end_elevation'];
                $currentMergedSplit['total_distance'] += $splits[$i]['total_distance'];
                $currentMergedSplit['total_elevation_change'] += $splits[$i]['total_elevation_change'];
                $currentMergedSplit['average_grade'] = round(
                    ($currentMergedSplit['total_elevation_change'] / $currentMergedSplit['total_distance']) * 100, 
                    2
                );
            } else {
                // Add current merged split and start a new one
                $mergedSplits[] = $currentMergedSplit;
                $currentMergedSplit = $splits[$i];
            }
        }
        
        // Add the last merged split
        $mergedSplits[] = $currentMergedSplit;
        $splits = $mergedSplits;
    }
    
    return json_encode([
        'status' => 'success',
        'splits' => array_map(function($split) {
            return [
                'type' => $split['type'],
                'start_distance' => round($split['start_distance'], 2),
                'end_distance' => round($split['end_distance'], 2),
                'start_elevation' => round($split['start_elevation'], 2),
                'end_elevation' => round($split['end_elevation'], 2),
                'elevation_change' => round($split['total_elevation_change'], 2),
                'distance' => round($split['total_distance'], 2),
                'average_grade' => $split['average_grade']
            ];
        }, $splits),
        'trackpoints' => $trackpoints
    ]);
}

// Handle file upload and URL processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = null;
    
    if (isset($_POST['gpxUrl']) && !empty($_POST['gpxUrl'])) {
        $url = filter_var($_POST['gpxUrl'], FILTER_SANITIZE_URL);
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['error' => 'Invalid URL provided']);
            exit;
        }
        
        // Verify URL points to a GPX file
        $urlInfo = pathinfo($url);
        if (strtolower($urlInfo['extension']) !== 'gpx') {
            echo json_encode(['error' => 'URL must point to a GPX file']);
            exit;
        }
        
        // Download the file
        $tempFile = tempnam(sys_get_temp_dir(), 'gpx_');
        $gpxContent = @file_get_contents($url);
        
        if ($gpxContent === false) {
            echo json_encode(['error' => 'Failed to download GPX file from URL']);
            @unlink($tempFile); // Clean up temp file
            exit;
        }
        
        file_put_contents($tempFile, $gpxContent);
        $result = parseGPX($tempFile);
        @unlink($tempFile); // Clean up temp file
    }else if (isset($_FILES['gpxFile'])) {
        $uploadedFile = $_FILES['gpxFile'];
        
        // Check for upload errors
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'File upload failed']);
            exit;
        }
        
        // Verify file type
        $fileInfo = pathinfo($uploadedFile['name']);
        if (strtolower($fileInfo['extension']) !== 'gpx') {
            echo json_encode(['error' => 'Invalid file type. Please upload a GPX file']);
            exit;
        }
        
        $result = parseGPX($uploadedFile['tmp_name']);
    } else {
        echo json_encode(['error' => 'No file uploaded or URL provided']);
        exit;
    }
    
    // Set JSON content type header
    header('Content-Type: application/json');
    echo $result;
}
?>
