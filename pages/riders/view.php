<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';
$rider = null;
$current_manifest = null;
$recent_manifests = [];
$delivery_stats = [];
$manifest_products = [];
$order_products = [];

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights and add company condition
    $company_condition = !isSuperAdmin() ? "AND rc.company_id = " . $_SESSION['company_id'] : "";
    
    // Fetch rider details
    $query = "SELECT u.*, rc.is_active as rider_company_active, c.name as company_name
              FROM Users u
              LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
              LEFT JOIN Companies c ON rc.company_id = c.id
              WHERE u.id = ? AND u.user_type = 'Rider' $company_condition";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rider = mysqli_fetch_assoc($result);

    if (!$rider) {
        header('Location: index.php');
        exit();
    }

    // Handle product status updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        $tracking_id = cleanInput($_POST['tracking_id']);

        mysqli_begin_transaction($conn);
        try {
            if ($action === 'mark_picked') {
                $update = "UPDATE RiderProductTracking 
                          SET is_picked = 1, picked_at = CURRENT_TIMESTAMP 
                          WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "i", $tracking_id);
                mysqli_stmt_execute($stmt);
            } elseif ($action === 'mark_delivered') {
                $update = "UPDATE RiderProductTracking 
                          SET is_delivered = 1, delivered_at = CURRENT_TIMESTAMP 
                          WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "i", $tracking_id);
                mysqli_stmt_execute($stmt);
            }
            mysqli_commit($conn);
            $success = 'Status updated successfully';

            // Refresh page to show updated status
            header("Location: view.php?id=" . $id . "&success=" . urlencode($success));
            exit();
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }

    // Company condition for manifests and orders
    $manifest_company_condition = !isSuperAdmin() ? "AND m.company_id = " . $_SESSION['company_id'] : "";

    // Get current active manifest for company
    $current_manifest_query = "SELECT m.*, 
                             COUNT(mo.order_id) as total_orders,
                             COUNT(CASE WHEN o.status = 'delivered' THEN 1 END) as delivered_orders
                             FROM Manifests m
                             LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
                             LEFT JOIN Orders o ON mo.order_id = o.id
                             WHERE m.rider_id = ? AND m.status != 'delivered'
                             $manifest_company_condition
                             GROUP BY m.id";
    $stmt = mysqli_prepare($conn, $current_manifest_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $manifest_result = mysqli_stmt_get_result($stmt);
    $current_manifest = mysqli_fetch_assoc($manifest_result);

    // If there's a current manifest, fetch its products
    if ($current_manifest) {
        $products_query = "SELECT 
            p.id as product_id,
            p.name as product_name,
            p.qrcode_number,
            o.id as order_id,
            o.order_number,
            po.quantity,
            rpt.id as tracking_id,
            rpt.is_picked,
            rpt.is_delivered,
            rpt.picked_at,
            rpt.delivered_at
        FROM ManifestOrders mo
        JOIN Orders o ON mo.order_id = o.id
        JOIN ProductOrders po ON o.id = po.order_id
        JOIN Products p ON po.product_id = p.id
        LEFT JOIN RiderProductTracking rpt ON (
            mo.manifest_id = rpt.manifest_id AND 
            o.id = rpt.order_id AND 
            p.id = rpt.product_id
        )
        WHERE mo.manifest_id = ?
        ORDER BY o.order_number, p.name";

        $stmt = mysqli_prepare($conn, $products_query);
        mysqli_stmt_bind_param($stmt, "i", $current_manifest['id']);
        mysqli_stmt_execute($stmt);
        $products_result = mysqli_stmt_get_result($stmt);
        
        while ($product = mysqli_fetch_assoc($products_result)) {
            $manifest_products[] = $product;
            
            // Group products by order
            if (!isset($order_products[$product['order_id']])) {
                $order_products[$product['order_id']] = [
                    'order_number' => $product['order_number'],
                    'products' => []
                ];
            }
            $order_products[$product['order_id']]['products'][] = $product;
            
            // Create tracking record if it doesn't exist
            if (!$product['tracking_id']) {
                $insert_tracking = "INSERT INTO RiderProductTracking 
                                  (manifest_id, order_id, product_id, rider_id, company_id, quantity)
                                  VALUES (?, ?, ?, ?, ?, ?)";
                $tracking_stmt = mysqli_prepare($conn, $insert_tracking);
                mysqli_stmt_bind_param($tracking_stmt, "iiiiii", 
                    $current_manifest['id'],
                    $product['order_id'],
                    $product['product_id'],
                    $id,
                    $current_manifest['company_id'],
                    $product['quantity']
                );
                mysqli_stmt_execute($tracking_stmt);
            }
        }
    }

    // Get recent manifests for company
    $recent_manifests_query = "SELECT m.*, 
                              COUNT(mo.order_id) as total_orders,
                              COUNT(CASE WHEN o.status = 'delivered' THEN 1 END) as delivered_orders,
                              COUNT(CASE WHEN o.status = 'failed' THEN 1 END) as failed_orders
                              FROM Manifests m
                              LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
                              LEFT JOIN Orders o ON mo.order_id = o.id
                              WHERE m.rider_id = ? $manifest_company_condition
                              GROUP BY m.id
                              ORDER BY m.created_at DESC
                              LIMIT 5";
    $stmt = mysqli_prepare($conn, $recent_manifests_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $manifests_result = mysqli_stmt_get_result($stmt);
    while ($manifest = mysqli_fetch_assoc($manifests_result)) {
        $recent_manifests[] = $manifest;
    }

    // Calculate delivery stats for company
    $stats_query = "SELECT 
                    COUNT(DISTINCT m.id) as total_manifests,
                    COUNT(DISTINCT CASE WHEN m.status = 'delivered' THEN m.id END) as completed_manifests,
                    COUNT(DISTINCT o.id) as total_orders,
                    COUNT(DISTINCT CASE WHEN o.status = 'delivered' THEN o.id END) as delivered_orders,
                    COUNT(DISTINCT CASE WHEN o.status = 'failed' THEN o.id END) as failed_orders,
                    AVG(CASE WHEN o.status = 'delivered' 
                        THEN TIMESTAMPDIFF(HOUR, m.created_at, o.updated_at) 
                        END) as avg_delivery_time
                    FROM Users u
                    LEFT JOIN Manifests m ON u.id = m.rider_id
                    LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
                    LEFT JOIN Orders o ON mo.order_id = o.id
                    WHERE u.id = ? $manifest_company_condition";
    $stmt = mysqli_prepare($conn, $stats_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $stats_result = mysqli_stmt_get_result($stmt);
    $delivery_stats = mysqli_fetch_assoc($stats_result);

    // Calculate success rate
    $delivery_stats['success_rate'] = $delivery_stats['total_orders'] > 0 
        ? ($delivery_stats['delivered_orders'] / $delivery_stats['total_orders']) * 100 
        : 0;
}

// Get success message from URL if it exists
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Rider - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
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

        <div class="mb-6 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($rider['name']); ?></h1>
            <div class="space-x-2">
                <a href="edit.php?id=<?php echo $rider['id']; ?>" 
                   class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                    Edit Rider
                </a>
                <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">
                    Back to List
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Rider Information -->
            <div class="lg:col-span-1">
                <div class="bg-white shadow rounded-lg divide-y divide-gray-200">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Rider Information</h2>
                        <dl class="mt-4 space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Status</dt>
                                <dd class="mt-1">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php echo $rider['rider_company_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $rider['rider_company_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <?php if ($current_manifest): ?>
                                    <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                        On Delivery
                                    </span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Company</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($rider['company_name']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Contact</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($rider['email']); ?><br>
                                    <?php echo htmlspecialchars($rider['phone']); ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Member Since</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo date('M d, Y', strtotime($rider['created_at'])); ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Last Active</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo date('M d, Y H:i', strtotime($rider['updated_at'])); ?>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Delivery Stats for Company -->
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Statistics
                            <?php if (!isSuperAdmin()): ?>
                                <span class="text-sm font-normal text-gray-500">
                                    (For <?php echo htmlspecialchars($rider['company_name']); ?>)
                                </span>
                            <?php endif; ?>
                        </h2>
                        <dl class="mt-4 space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Success Rate</dt>
                                <dd class="mt-1 text-3xl font-semibold text-indigo-600">
                                    <?php echo number_format($delivery_stats['success_rate'], 1); ?>%
                                </dd>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Total Manifests</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-gray-900">
                                        <?php echo $delivery_stats['total_manifests']; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Completed</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-gray-900">
                                        <?php echo $delivery_stats['completed_manifests']; ?>
                                    </dd>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Delivered</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-green-600">
                                        <?php echo $delivery_stats['delivered_orders']; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Failed</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-red-600">
                                        <?php echo $delivery_stats['failed_orders']; ?>
                                    </dd>
                                </div>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Current Manifest -->
                <?php if ($current_manifest): ?>
                <div class="bg-white shadow rounded-lg">
                    <div class="p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-lg font-medium text-gray-900">Current Manifest</h2>
                                <p class="mt-1 text-sm text-gray-500">
                                    Started <?php echo date('M d, Y H:i', strtotime($current_manifest['created_at'])); ?>
                                </p>
                            </div>
                            <a href="../manifests/view.php?id=<?php echo $current_manifest['id']; ?>" 
                               class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                                View Details
                            </a>
                        </div>
                        <div class="mt-6">
                            <div class="relative pt-1">
                                <div class="flex mb-2 items-center justify-between">
                                    <div>
                                        <span class="text-xs font-semibold inline-block text-indigo-600">
                                            Progress
                                        </span>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-xs font-semibold inline-block text-indigo-600">
                                            <?php echo $current_manifest['delivered_orders']; ?>/<?php echo $current_manifest['total_orders']; ?> Orders
                                        </span>
                                    </div>
                                </div>
                                <div class="overflow-hidden h-2 mb-4 text-xs flex rounded bg-indigo-200">
                                    <div style="width:<?php echo ($current_manifest['delivered_orders'] / $current_manifest['total_orders']) * 100; ?>%" 
                                         class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-indigo-500">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Products List Section -->
                        <?php if (!empty($order_products)): ?>
                        <div class="mt-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Products to Deliver</h3>
                            <div class="space-y-4">
                                <?php foreach ($order_products as $order_id => $order_data): ?>
                                <div class="border rounded-lg p-4">
                                    <h4 class="font-medium text-gray-900 mb-2">
                                        Order #<?php echo htmlspecialchars($order_data['order_number']); ?>
                                    </h4>
                                    <div class="space-y-3">
                                        <?php foreach ($order_data['products'] as $product): ?>
                                        <div class="flex items-center justify-between bg-gray-50 p-3 rounded">
                                            <div>
                                                <p class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($product['product_name']); ?>
                                                </p>
                                                <p class="text-sm text-gray-500">
                                                    QR: <?php echo htmlspecialchars($product['qrcode_number']); ?> | 
                                                    Quantity: <?php echo $product['quantity']; ?>
                                                </p>
                                                <?php if ($product['is_picked'] && $product['picked_at']): ?>
                                                <p class="text-sm text-gray-500">
                                                    Picked at: <?php echo date('M d, Y H:i', strtotime($product['picked_at'])); ?>
                                                </p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <?php if ($product['tracking_id']): ?>
                                                    <?php if (!$product['is_picked']): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="mark_picked">
                                                        <input type="hidden" name="tracking_id" value="<?php echo $product['tracking_id']; ?>">
                                                        <button type="submit" 
                                                                class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium">
                                                            Mark as Picked
                                                        </button>
                                                    </form>
                                                    <?php elseif (!$product['is_delivered']): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="mark_delivered">
                                                        <input type="hidden" name="tracking_id" value="<?php echo $product['tracking_id']; ?>">
                                                        <button type="submit"
                                                                class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
                                                            Mark as Delivered
                                                        </button>
                                                    </form>
                                                    <?php else: ?>
                                                    <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
                                                        Delivered
                                                        <?php if ($product['delivered_at']): ?>
                                                            at <?php echo date('H:i', strtotime($product['delivered_at'])); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Manifests -->
                <div class="bg-white shadow rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Recent Manifests</h2>
                        <div class="mt-6 space-y-4">
                            <?php foreach ($recent_manifests as $manifest): ?>
                            <div class="border rounded-lg p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <a href="../manifests/view.php?id=<?php echo $manifest['id']; ?>" 
                                           class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                                            Manifest #<?php echo $manifest['id']; ?>
                                        </a>
                                        <p class="mt-1 text-sm text-gray-500">
                                            <?php echo date('M d, Y H:i', strtotime($manifest['created_at'])); ?>
                                        </p>
                                    </div>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php
                                        switch($manifest['status']) {
                                            case 'delivered':
                                                echo 'bg-green-100 text-green-800';
                                                break;
                                            case 'delivering':
                                                echo 'bg-blue-100 text-blue-800';
                                                break;
                                            default:
                                                echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php echo ucfirst($manifest['status']); ?>
                                    </span>
                                </div>
                                <div class="mt-4">
                                    <div class="grid grid-cols-3 gap-4 text-sm text-gray-500">
                                        <div>
                                            <span class="text-gray-900 font-medium"><?php echo $manifest['total_orders']; ?></span> Orders
                                        </div>
                                        <div>
                                            <span class="text-green-600 font-medium"><?php echo $manifest['delivered_orders']; ?></span> Delivered
                                        </div>
                                        <div>
                                            <span class="text-red-600 font-medium"><?php echo $manifest['failed_orders']; ?></span> Failed
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($recent_manifests)): ?>
                            <div class="text-center py-4 text-gray-500">
                                No manifests found
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>