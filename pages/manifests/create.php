<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';

// Fetch available riders with company association
$riders_query = "SELECT DISTINCT u.id, u.name 
                FROM Users u 
                LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
                WHERE u.user_type = 'Rider' AND u.is_active = 1";
                
if (!isSuperAdmin()) {
    $riders_query .= " AND (u.company_id = " . $_SESSION['company_id'] . 
                    " OR rc.company_id = " . $_SESSION['company_id'] . ")";
}
$riders_query .= " ORDER BY u.name";
$riders_result = mysqli_query($conn, $riders_query);

// Get search parameters
$search = isset($_GET['search']) ? cleanInput($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? cleanInput($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? cleanInput($_GET['date_to']) : '';

// Build the orders query with search conditions
$orders_query = "SELECT o.*, c.name as company_name 
                FROM Orders o 
                LEFT JOIN ManifestOrders mo ON o.id = mo.order_id
                LEFT JOIN Companies c ON o.company_id = c.id
                WHERE mo.id IS NULL AND o.status = 'pending'";

if (!isSuperAdmin()) {
    $orders_query .= " AND o.company_id = " . $_SESSION['company_id'];
}

if ($search) {
    $orders_query .= " AND (o.order_number LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' 
                      OR o.customer_name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'
                      OR o.address_line1 LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'
                      OR o.city LIKE '%" . mysqli_real_escape_string($conn, $search) . "%')";
}

if ($date_from) {
    $orders_query .= " AND DATE(o.created_at) >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}

if ($date_to) {
    $orders_query .= " AND DATE(o.created_at) <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
}

$orders_query .= " ORDER BY o.created_at DESC";
$orders_result = mysqli_query($conn, $orders_query);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rider_id = !empty($_POST['rider_id']) ? cleanInput($_POST['rider_id']) : null;
    $selected_orders = isset($_POST['orders']) ? $_POST['orders'] : [];
    $company_id = !isSuperAdmin() ? $_SESSION['company_id'] : cleanInput($_POST['company_id']);

    if (empty($selected_orders)) {
        $error = 'Please select at least one order';
    } else {
        mysqli_begin_transaction($conn);
        try {
            // Set status based on rider assignment
            $manifest_status = $rider_id ? 'assigned' : 'pending';
            $order_status = $rider_id ? 'assigned' : 'pending';

            // Create manifest
            $manifest_query = "INSERT INTO Manifests (rider_id, status, total_orders_assigned, company_id) 
                             VALUES (?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $manifest_query);
            $total_orders = count($selected_orders);
            mysqli_stmt_bind_param($stmt, "isii", $rider_id, $manifest_status, $total_orders, $company_id);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_stmt_error($stmt));
            }
            
            $manifest_id = mysqli_insert_id($conn);

            // Add orders to manifest
            $order_query = "INSERT INTO ManifestOrders (manifest_id, order_id) VALUES (?, ?)";
            $stmt = mysqli_prepare($conn, $order_query);
            
            foreach ($selected_orders as $order_id) {
                // Add to manifest
                mysqli_stmt_bind_param($stmt, "ii", $manifest_id, $order_id);
                mysqli_stmt_execute($stmt);

                // Update order status
                $update_order = "UPDATE Orders SET status = ? WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_order);
                mysqli_stmt_bind_param($update_stmt, "si", $order_status, $order_id);
                mysqli_stmt_execute($update_stmt);

                // Add status log
                $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, ?, ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                mysqli_stmt_bind_param($log_stmt, "isi", $order_id, $order_status, $_SESSION['user_id']);
                mysqli_stmt_execute($log_stmt);
            }

            mysqli_commit($conn);
            $success = 'Manifest created successfully';
            header("Location: index.php");
            exit();
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = 'Error creating manifest: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Manifest - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Create New Manifest</h1>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $error; ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $success; ?></span>
        </div>
        <?php endif; ?>

        <!-- Search and Filter Form -->
        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6 mb-6">
            <form method="GET" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Order #, Customer, Address..."
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-700">Date From</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700">Date To</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                        Filter Orders
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
            <form action="" method="POST" id="manifestForm">
                <div class="mb-6">
                    <label for="rider_id" class="block text-sm font-medium text-gray-700">Assign Rider</label>
                    <select name="rider_id" id="rider_id"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Rider (Optional)</option>
                        <?php while ($rider = mysqli_fetch_assoc($riders_result)): ?>
                            <option value="<?php echo $rider['id']; ?>"><?php echo htmlspecialchars($rider['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">
                        Note: If no rider is selected, the manifest and orders will remain in 'pending' status. 
                        If a rider is selected, they will be set to 'assigned' status.
                    </p>
                </div>

                <div class="mb-6">
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-sm font-medium text-gray-700">Select Orders</label>
                        <div class="space-x-2">
                            <button type="button" id="selectAll" class="text-sm text-indigo-600 hover:text-indigo-800">
                                Select All
                            </button>
                            <button type="button" id="deselectAll" class="text-sm text-gray-600 hover:text-gray-800">
                                Deselect All
                            </button>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-4">
                        <?php while ($order = mysqli_fetch_assoc($orders_result)): ?>
                        <div class="border rounded-lg p-4">
                            <label class="flex items-start space-x-3">
                                <input type="checkbox" name="orders[]" value="<?php echo $order['id']; ?>"
                                       class="order-checkbox mt-1 h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                                <div class="flex-1">
                                    <div class="flex justify-between">
                                        <span class="text-sm font-medium text-gray-900">
                                            Order #<?php echo $order['order_number']; ?>
                                        </span>
                                        <span class="text-sm text-gray-500">
                                            <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($order['customer_name']); ?> -
                                        <?php echo htmlspecialchars($order['address_line1']); ?>,
                                        <?php echo htmlspecialchars($order['city']); ?>
                                    </p>
                                </div>
                            </label>
                        </div>
                        <?php endwhile; ?>
                        
                        <?php if (mysqli_num_rows($orders_result) == 0): ?>
                        <div class="text-center py-4 text-gray-500">
                            No unassigned orders available
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                        Create Manifest
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Select/Deselect All functionality
            $('#selectAll').click(function() {
                $('.order-checkbox').prop('checked', true);
            });

            $('#deselectAll').click(function() {
                $('.order-checkbox').prop('checked', false);
            });

            // Form validation
            $('#manifestForm').submit(function(e) {
                const selectedOrders = $('.order-checkbox:checked').length;
                if (selectedOrders === 0) {
                    e.preventDefault();
                    alert('Please select at least one order');
                    return false;
                }
                return true;
            });
        });
    </script>
</body>
</html>