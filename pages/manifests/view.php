<?php
require_once '../../includes/config.php';
requireLogin();

$manifest = null;
$manifest_orders = [];

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND m.company_id = " . $_SESSION['company_id'] : "";
    
    // Fetch manifest details
    $query = "SELECT m.*, 
              u.name as rider_name, u.phone as rider_phone, u.email as rider_email,
              c.name as company_name 
              FROM Manifests m
              LEFT JOIN Users u ON m.rider_id = u.id
              LEFT JOIN Companies c ON m.company_id = c.id
              WHERE m.id = ? $company_condition";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $manifest = mysqli_fetch_assoc($result);

    if (!$manifest) {
        header('Location: index.php');
        exit();
    }

    // Fetch orders in manifest with their statuses
    $orders_query = "SELECT o.*, 
                    (SELECT COUNT(*) FROM OrderStatusLogs WHERE order_id = o.id) as status_count
                    FROM Orders o
                    JOIN ManifestOrders mo ON o.id = mo.order_id
                    WHERE mo.manifest_id = ?
                    ORDER BY o.created_at DESC";
    $stmt = mysqli_prepare($conn, $orders_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $orders_result = mysqli_stmt_get_result($stmt);
    while ($order = mysqli_fetch_assoc($orders_result)) {
        $manifest_orders[] = $order;
    }

    // Calculate statistics
    $total_orders = count($manifest_orders);
    $delivered_orders = array_filter($manifest_orders, function($order) {
        return $order['status'] === 'delivered';
    });
    $delivery_progress = $total_orders > 0 ? (count($delivered_orders) / $total_orders) * 100 : 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Manifest - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900">Manifest #<?php echo $manifest['id']; ?></h1>
            <div class="space-x-2">
                <a href="edit.php?id=<?php echo $manifest['id']; ?>" 
                   class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                    Edit Manifest
                </a>
                <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">
                    Back to List
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Manifest Details -->
            <div class="lg:col-span-1">
                <div class="bg-white shadow rounded-lg divide-y divide-gray-200">
                    <div class="px-6 py-5">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Manifest Information</h3>
                    </div>
                    <div class="px-6 py-5">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Status</dt>
                                <dd class="mt-1">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php
                                        switch($manifest['status']) {
                                            case 'delivered':
                                                echo 'bg-green-100 text-green-800';
                                                break;
                                            case 'delivering':
                                                echo 'bg-blue-100 text-blue-800';
                                                break;
                                            case 'pending':
                                                echo 'bg-yellow-100 text-yellow-800';
                                                break;
                                            default:
                                                echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php echo ucfirst($manifest['status']); ?>
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Created</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo date('M d, Y H:i', strtotime($manifest['created_at'])); ?>
                                </dd>
                            </div>
                            <?php if (isSuperAdmin()): ?>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Company</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($manifest['company_name']); ?>
                                </dd>
                            </div>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>

                <!-- Rider Information -->
                <?php if ($manifest['rider_id']): ?>
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="px-6 py-5">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Assigned Rider</h3>
                        <dl class="mt-4 space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Name</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($manifest['rider_name']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Contact</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($manifest['rider_phone']); ?><br>
                                    <?php echo htmlspecialchars($manifest['rider_email']); ?>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Delivery Progress -->
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="px-6 py-5">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Delivery Progress</h3>
                        <div class="mt-4">
                            <div class="relative pt-1">
                                <div class="flex mb-2 items-center justify-between">
                                    <div>
                                        <span class="text-xs font-semibold inline-block text-indigo-600">
                                            <?php echo number_format($delivery_progress, 1); ?>% Complete
                                        </span>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-xs font-semibold inline-block text-indigo-600">
                                            <?php echo count($delivered_orders); ?>/<?php echo $total_orders; ?> Orders
                                        </span>
                                    </div>
                                </div>
                                <div class="overflow-hidden h-2 mb-4 text-xs flex rounded bg-indigo-200">
                                    <div style="width:<?php echo $delivery_progress; ?>%" 
                                         class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-indigo-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Orders List -->
            <div class="lg:col-span-2">
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Orders (<?php echo count($manifest_orders); ?>)</h3>
                    </div>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($manifest_orders as $order): ?>
                        <div class="px-6 py-5">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="flex items-center space-x-3">
                                        <span class="text-lg font-medium text-gray-900">
                                            #<?php echo $order['order_number']; ?>
                                        </span>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?php
                                            switch($order['status']) {
                                                case 'delivered':
                                                    echo 'bg-green-100 text-green-800';
                                                    break;
                                                case 'delivering':
                                                    echo 'bg-blue-100 text-blue-800';
                                                    break;
                                                case 'pending':
                                                    echo 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                default:
                                                    echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </div>
                                    <div class="mt-1">
                                        <p class="text-sm text-gray-900"><?php echo htmlspecialchars($order['customer_name']); ?></p>
                                        <p class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($order['address_line1']); ?>,
                                            <?php if ($order['address_line2']) echo htmlspecialchars($order['address_line2']) . ', '; ?>
                                            <?php echo htmlspecialchars($order['city']); ?>
                                        </p>
                                    </div>
                                    <?php if ($order['notes']): ?>
                                    <div class="mt-2">
                                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($order['notes']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-shrink-0">
                                    <a href="../orders/view.php?id=<?php echo $order['id']; ?>" 
                                       class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                                        View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($manifest_orders)): ?>
                        <div class="px-6 py-5">
                            <p class="text-gray-500">No orders in this manifest.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>