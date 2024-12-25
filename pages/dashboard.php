<?php
require_once '../includes/config.php';
requireLogin();

// Get statistics based on user type and company
$company_condition = !isSuperAdmin() && $_SESSION['company_id'] ? "company_id = " . $_SESSION['company_id'] : "";

// Build WHERE clauses properly
function buildWhereClause($conditions) {
    $where = array_filter($conditions); // Remove empty conditions
    return !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
}

// Total Orders
$orders_query = "SELECT 
    COUNT(*) as total_orders,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders,
    COUNT(CASE WHEN status = 'delivering' THEN 1 END) as delivering_orders,
    COUNT(CASE WHEN status = 'delivered' THEN 1 END) as delivered_orders,
    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_orders
    FROM Orders " . ($company_condition ? "WHERE $company_condition" : "");
$orders_result = mysqli_query($conn, $orders_query);
$orders_stats = mysqli_fetch_assoc($orders_result);

// Total Companies (Super Admin Only)
$companies_stats = array('total_companies' => 0);
if (isSuperAdmin()) {
    $companies_query = "SELECT COUNT(*) as total_companies FROM Companies";
    $companies_result = mysqli_query($conn, $companies_query);
    $companies_stats = mysqli_fetch_assoc($companies_result);
}

// Total Users by Type
$users_conditions = [];
if ($company_condition) {
    $users_conditions[] = $company_condition;
}
$users_where = buildWhereClause($users_conditions);

// Total Users by Type
$users_conditions = [];
if (isSuperAdmin()) {
    // For super admin, show all users
    $users_query = "SELECT 
        COUNT(*) as total_users,
        COUNT(CASE WHEN user_type = 'Super Admin' THEN 1 END) as super_admins,
        COUNT(CASE WHEN user_type = 'Admin' THEN 1 END) as admins,
        COUNT(CASE WHEN user_type = 'Rider' THEN 1 END) as riders
        FROM Users";
} else {
    // For company admin, show only their company users
    $users_query = "SELECT 
        COUNT(DISTINCT u.id) as total_users,
        COUNT(DISTINCT CASE WHEN user_type = 'Admin' AND u.company_id = " . $_SESSION['company_id'] . " THEN u.id END) as admins,
        COUNT(DISTINCT CASE WHEN user_type = 'Rider' AND (u.company_id = " . $_SESSION['company_id'] . 
        " OR rc.company_id = " . $_SESSION['company_id'] . ") THEN u.id END) as riders,
        0 as super_admins
        FROM Users u
        LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
        WHERE u.company_id = " . $_SESSION['company_id'] . 
        " OR rc.company_id = " . $_SESSION['company_id'];
}

// $users_result = mysqli_query($conn, $users_query);
// $users_stats = mysqli_fetch_assoc($users_result);


$users_result = mysqli_query($conn, $users_query);
$users_stats = mysqli_fetch_assoc($users_result);

// Active Manifests
$manifests_conditions = [];
if ($company_condition) {
    $manifests_conditions[] = $company_condition;
}
$manifests_where = buildWhereClause($manifests_conditions);

$manifests_query = "SELECT 
    COUNT(*) as total_manifests,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_manifests,
    COUNT(CASE WHEN status = 'delivering' THEN 1 END) as active_manifests,
    COUNT(CASE WHEN status = 'delivered' THEN 1 END) as completed_manifests
    FROM Manifests $manifests_where";
$manifests_result = mysqli_query($conn, $manifests_query);
$manifests_stats = mysqli_fetch_assoc($manifests_result);

// Today's Orders
$today_conditions = [];
if ($company_condition) {
    $today_conditions[] = $company_condition;
}
$today_conditions[] = "DATE(created_at) = CURDATE()";
$today_where = buildWhereClause($today_conditions);

$today_orders_query = "SELECT COUNT(*) as today_orders FROM Orders $today_where";
$today_orders_result = mysqli_query($conn, $today_orders_query);
$today_orders = mysqli_fetch_assoc($today_orders_result);


// Get current rider locations
$riders_location_query = "
    SELECT DISTINCT rl.*, u.name as rider_name
    FROM RidersLocations rl
    INNER JOIN (
        SELECT rider_id, MAX(created_at) as latest_location
        FROM RidersLocations
        GROUP BY rider_id
    ) latest ON rl.rider_id = latest.rider_id AND rl.created_at = latest.latest_location
    INNER JOIN Users u ON rl.rider_id = u.id
    LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
    WHERE u.user_type = 'Rider'";

if (!isSuperAdmin() && $_SESSION['company_id']) {
    $riders_location_query .= " AND (u.company_id = " . $_SESSION['company_id'] . 
                            " OR rc.company_id = " . $_SESSION['company_id'] . ")";
}

$riders_location_result = mysqli_query($conn, $riders_location_query);
$riders_locations = [];
while ($location = mysqli_fetch_assoc($riders_location_result)) {
    $riders_locations[] = $location;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Add Leaflet CSS and JS for map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .dot {
            top: 1px;
            left: 1px;
            transition: transform 0.3s ease-in-out;
        }
        input:checked ~ .dot {
            transform: translateX(100%);
        }
        input:checked + div {
            background-color: #4F46E5;
        }

        .rider-marker {
            background: none;
            border: none;
        }

        .marker-content {
            position: relative;
            text-align: center;
        }

        .rider-dot {
            width: 12px;
            height: 12px;
            background: #4F46E5;
            border-radius: 50%;
            margin: 0 auto;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.2);
            animation: pulse 2s infinite;
        }

        .rider-name {
            background: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            margin-top: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(79, 70, 229, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(79, 70, 229, 0);
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <?php include_once '../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-8">Dashboard</h1>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Orders -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-indigo-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Total Orders</dt>
                                <dd class="text-2xl font-semibold text-gray-900"><?php echo $orders_stats['total_orders']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-5 py-3">
                    <div class="text-sm">
                        <div class="font-medium text-indigo-700">Today: <?php echo $today_orders['today_orders']; ?></div>
                    </div>
                </div>
            </div>

            <!-- Order Status -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-green-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Delivered Orders</dt>
                                <dd class="text-2xl font-semibold text-gray-900"><?php echo $orders_stats['delivered_orders']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-5 py-3">
                    <div class="text-sm space-y-1">
                        <div class="font-medium text-orange-700">Pending: <?php echo $orders_stats['pending_orders']; ?></div>
                    </div>
                </div>
            </div>

            <!-- Active Manifests -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-yellow-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Active Manifests</dt>
                                <dd class="text-2xl font-semibold text-gray-900"><?php echo $manifests_stats['active_manifests']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-5 py-3">
                    <div class="text-sm">
                        <div class="font-medium text-blue-700">Total: <?php echo $manifests_stats['total_manifests']; ?></div>
                    </div>
                </div>
            </div>

            <!-- Riders/Users -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-purple-500 rounded-md p-3">
                            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Total Riders</dt>
                                <dd class="text-2xl font-semibold text-gray-900"><?php echo $users_stats['riders']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-5 py-3">
                    <div class="text-sm">
                        <div class="font-medium text-purple-700">Active Users: <?php echo $users_stats['total_users']; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isSuperAdmin()): ?>
        <!-- Super Admin Stats -->
        <div class="bg-white shadow rounded-lg p-6 mb-8">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">System Overview</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">Companies</h3>
                    <p class="text-3xl font-bold text-indigo-600"><?php echo $companies_stats['total_companies']; ?></p>
                </div>
                <div>
                    <h3 class="text-lg font-medium text-gray-900">Admins</h3>
                    <p class="text-3xl font-bold text-indigo-600"><?php echo $users_stats['admins']; ?></p>
                </div>
                <div>
                    <h3 class="text-lg font-medium text-gray-900">Super Admins</h3>
                    <p class="text-3xl font-bold text-indigo-600"><?php echo $users_stats['super_admins']; ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Orders -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Recent Orders</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order Number</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        $recent_orders_query = "SELECT * FROM Orders " . 
                            ($company_condition ? "WHERE $company_condition " : "") . 
                            "ORDER BY created_at DESC LIMIT 5";
                        $recent_orders_result = mysqli_query($conn, $recent_orders_query);
                        while ($order = mysqli_fetch_assoc($recent_orders_result)):
                        ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo $order['order_number']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $order['customer_name']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?php
                                    switch($order['status']) {
                                        case 'delivered':
                                            echo 'bg-green-100 text-green-800';
                                            break;
                                        case 'pending':
                                            echo 'bg-yellow-100 text-yellow-800';
                                            break;
                                        case 'failed':
                                            echo 'bg-red-100 text-red-800';
                                            break;
                                        default:
                                            echo 'bg-blue-100 text-blue-800';
                                    }
                                    ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if (mysqli_num_rows($recent_orders_result) == 0): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                No recent orders found
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (mysqli_num_rows($recent_orders_result) > 0): ?>
            <div class="mt-4">
                <a href="orders/index.php" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                    View all orders →
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Riders Map
        <div class="bg-white shadow rounded-lg p-6 mt-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold text-gray-900">Riders Location Map</h2>
                <div class="flex items-center space-x-4">
                    <button id="refreshMap" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                        <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Refresh Map
                    </button>

                    <label class="flex items-center cursor-pointer">
                        <span class="mr-2 text-sm text-gray-700">Auto Refresh</span>
                        <div class="relative">
                            <input type="checkbox" id="autoRefresh" class="sr-only">
                            <div class="w-10 h-5 bg-gray-200 rounded-full"></div>
                            <div class="dot absolute w-5 h-4 bg-white rounded-full transition "></div>
                        </div>
                    </label>
                    
                    <span id="lastUpdate" class="text-sm text-gray-500"></span>
                </div>
            </div>
            <div id="map" style="height: 500px;" class="rounded-lg"></div>
        </div> -->


        <!-- Riders Map -->
        <div class="bg-white shadow rounded-lg p-6 mt-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold text-gray-900">Riders Location Map</h2>
                <div class="flex items-center space-x-4">
                    <div id="connectionStatus" class="px-3 py-1 rounded-full text-sm">⚫ Connecting...</div>
                    <span id="lastUpdate" class="text-sm text-gray-500"></span>
                </div>
            </div>
            <div id="map" style="height: 500px;" class="rounded-lg"></div>
        </div>




    </div>



<script>
    // Initialize map with UK center and bounds
const UK_CENTER = [54.5, -2];
const UK_ZOOM = 6;
const UK_BOUNDS = [
    [49.8, -8.0], // Southwest corner
    [60.9, 2.0]   // Northeast corner
];

// Add these at the start
const CURRENT_USER_TYPE = '<?php echo $_SESSION['user_type']; ?>';
const CURRENT_COMPANY_ID = <?php echo isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 'null'; ?>;

console.log('User Session:', {
    type: CURRENT_USER_TYPE,
    companyId: CURRENT_COMPANY_ID
});

let map = L.map('map', {
    center: UK_CENTER,
    zoom: UK_ZOOM,
    minZoom: 5,
    maxZoom: 13,
    maxBounds: UK_BOUNDS,
    maxBoundsViscosity: 1.0
}).setView(UK_CENTER, UK_ZOOM);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

let markers = {};
let socket;
let reconnectAttempts = 0;
const maxReconnectAttempts = 5;

function initWebSocket() {
    const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const wsUrl = `${wsProtocol}//${window.location.hostname}:8080`;
    
    socket = new WebSocket(wsUrl);

    socket.onopen = function() {
        updateConnectionStatus('Connected', 'green');
        reconnectAttempts = 0;
        socket.send(JSON.stringify({ type: 'request_locations' }));
    };

    socket.onclose = function() {
        updateConnectionStatus('Disconnected', 'red');
        if (reconnectAttempts < maxReconnectAttempts) {
            reconnectAttempts++;
            setTimeout(initWebSocket, 3000 * reconnectAttempts);
        }
    };

    socket.onerror = function(error) {
        console.error('WebSocket error:', error);
        updateConnectionStatus('Error', 'red');
    };

    socket.onmessage = function(event) {
        try {
            const data = JSON.parse(event.data);
            console.log('Received WebSocket data:', data);

            if (data.type === 'initial' || data.type === 'locations_update') {
                let locationsToShow;

                if (CURRENT_USER_TYPE === 'Super Admin') {
                    locationsToShow = data.locations;
                    console.log('Super Admin - showing all locations:', locationsToShow);
                } else {
                    // For regular admin, filter by company
                    locationsToShow = data.locations.filter(location => {
                        const isCompanyRider = location.company_id == CURRENT_COMPANY_ID || 
                                             location.assigned_company_id == CURRENT_COMPANY_ID;
                        
                        console.log('Filtering rider:', {
                            riderName: location.rider_name,
                            locationCompanyId: location.company_id,
                            assignedCompanyId: location.assigned_company_id,
                            currentCompanyId: CURRENT_COMPANY_ID,
                            matches: isCompanyRider
                        });
                        
                        return isCompanyRider;
                    });
                    console.log('Admin - filtered locations:', locationsToShow);
                }

                if (locationsToShow && locationsToShow.length > 0) {
                    updateMarkers(locationsToShow);
                    
                    // Update status with rider count
                    const riderCount = locationsToShow.length;
                    const countText = CURRENT_USER_TYPE === 'Super Admin' 
                        ? `Showing all ${riderCount} riders`
                        : `Showing ${riderCount} company riders`;
                    
                    document.getElementById('lastUpdate').textContent = 
                        `${countText} - Last updated: ${new Date().toLocaleTimeString()}`;
                } else {
                    console.log('No riders to display');
                    document.getElementById('lastUpdate').textContent = 
                        'No riders available - ' + new Date().toLocaleTimeString();
                }
            }
        } catch (error) {
            console.error('Error processing message:', error);
        }
    };
}

    function updateConnectionStatus(status, color) {
        const statusEl = document.getElementById('connectionStatus');
        const dot = status === 'Connected' ? '🟢' : status === 'Disconnected' ? '🔴' : '⚫';
        statusEl.innerHTML = `${dot} ${status}`;
        statusEl.className = `px-3 py-1 rounded-full text-sm text-${color}-600 bg-${color}-50`;
    }

    function createMarkerIcon(riderName) {
        return L.divIcon({
            className: 'rider-marker',
            html: `
                <div class="marker-content">
                    <div class="rider-dot"></div>
                    <div class="rider-name">${riderName}</div>
                </div>
            `
        });
    }

    function animateMarker(marker, newLatLng, duration = 1000) {
        const startLatLng = marker.getLatLng();
        const startTime = Date.now();
        
        function animate() {
            const elapsed = Date.now() - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Easing function for smooth movement
            const easeInOutQuad = t => t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
            const easedProgress = easeInOutQuad(progress);
            
            const lat = startLatLng.lat + (newLatLng.lat - startLatLng.lat) * easedProgress;
            const lng = startLatLng.lng + (newLatLng.lng - startLatLng.lng) * easedProgress;
            
            marker.setLatLng([lat, lng]);
            
            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        }
        
        animate();
    }

    function updateMarkers(locations) {
        locations.forEach(location => {
            const lat = parseFloat(location.lat);
            const lng = parseFloat(location.lng);
            const riderId = location.rider_id;
            
            if (!isNaN(lat) && !isNaN(lng)) {
                const newLatLng = L.latLng(lat, lng);
                
                if (markers[riderId]) {
                    // Update existing marker position with animation
                    animateMarker(markers[riderId], newLatLng);
                    
                    // Update popup content
                    markers[riderId].getPopup().setContent(`
                        <div class="marker-popup">
                            <h3 class="font-bold">${location.rider_name}</h3>
                            <p class="text-sm text-gray-600">Last updated: ${new Date(location.created_at).toLocaleString()}</p>
                        </div>
                    `);
                } else {
                    // Create new marker
                    const marker = L.marker(newLatLng, {
                        icon: createMarkerIcon(location.rider_name)
                    });

                    marker.bindPopup(`
                        <div class="marker-popup">
                            <h3 class="font-bold">${location.rider_name}</h3>
                            <p class="text-sm text-gray-600">Last updated: ${new Date(location.created_at).toLocaleString()}</p>
                        </div>
                    `);

                    marker.addTo(map);
                    markers[riderId] = marker;
                }
            }
        });

        // Remove markers for riders not in the update
        Object.keys(markers).forEach(riderId => {
            if (!locations.find(loc => loc.rider_id.toString() === riderId.toString())) {
                map.removeLayer(markers[riderId]);
                delete markers[riderId];
            }
        });

        document.getElementById('lastUpdate').textContent = 
            'Last updated: ' + new Date().toLocaleTimeString();
    }

    // Add styles for markers
    const style = document.createElement('style');
    style.textContent = `
        .rider-marker {
            background: none;
            border: none;
        }

        .marker-content {
            position: relative;
            text-align: center;
        }

        .rider-dot {
            width: 12px;
            height: 12px;
            background: #4F46E5;
            border-radius: 50%;
            margin: 0 auto;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.2);
            animation: pulse 2s infinite;
        }

        .rider-name {
            background: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            margin-top: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            white-space: nowrap;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(79, 70, 229, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(79, 70, 229, 0);
            }
        }

        .marker-popup {
            padding: 5px;
            min-width: 150px;
        }
    `;
    document.head.appendChild(style);

    // Initialize WebSocket connection
    initWebSocket();
</script>

</body>
</html>