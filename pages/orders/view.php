<?php
require_once '../../includes/config.php';
requireLogin();

$order = null;
$status_logs = [];
$manifest = null;
$products = [];

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND o.company_id = " . $_SESSION['company_id'] : "";
    
    // Fetch order details with company name and products
    $query = "SELECT o.*, c.name as company_name,
              GROUP_CONCAT(p.name) as product_names,
              GROUP_CONCAT(po.quantity) as quantities,
              GROUP_CONCAT(po.price) as prices,
              GROUP_CONCAT(p.id) as product_ids
              FROM Orders o
              LEFT JOIN Companies c ON o.company_id = c.id
              LEFT JOIN ProductOrders po ON o.id = po.order_id
              LEFT JOIN Products p ON po.product_id = p.id
              WHERE o.id = ? $company_condition
              GROUP BY o.id";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $order = mysqli_fetch_assoc($result);

    if (!$order) {
        header('Location: index.php');
        exit();
    }

    // Process products data
    if ($order['product_names']) {
        $product_names = explode(',', $order['product_names']);
        $quantities = explode(',', $order['quantities']);
        $prices = explode(',', $order['prices']);
        $product_ids = explode(',', $order['product_ids']);
        
        for ($i = 0; $i < count($product_names); $i++) {
            $products[] = [
                'id' => $product_ids[$i],
                'name' => $product_names[$i],
                'quantity' => $quantities[$i],
                'price' => $prices[$i],
                'subtotal' => $quantities[$i] * $prices[$i]
            ];
        }
    }

    // Fetch status logs with user details
    $logs_query = "SELECT l.*, u.name as changed_by_name 
                   FROM OrderStatusLogs l
                   LEFT JOIN Users u ON l.changed_by = u.id
                   WHERE l.order_id = ?
                   ORDER BY l.changed_at DESC";
    
    $logs_stmt = mysqli_prepare($conn, $logs_query);
    mysqli_stmt_bind_param($logs_stmt, "i", $id);
    mysqli_stmt_execute($logs_stmt);
    $logs_result = mysqli_stmt_get_result($logs_stmt);
    while ($log = mysqli_fetch_assoc($logs_result)) {
        $status_logs[] = $log;
    }

    // Fetch manifest details if assigned
    $manifest_query = "SELECT m.*, u.name as rider_name 
                      FROM Manifests m
                      LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
                      LEFT JOIN Users u ON m.rider_id = u.id
                      WHERE mo.order_id = ?";
    
    $manifest_stmt = mysqli_prepare($conn, $manifest_query);
    mysqli_stmt_bind_param($manifest_stmt, "i", $id);
    mysqli_stmt_execute($manifest_stmt);
    $manifest_result = mysqli_stmt_get_result($manifest_stmt);
    $manifest = mysqli_fetch_assoc($manifest_result);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Order - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900">
                Order #<?php echo htmlspecialchars($order['order_number']); ?>
            </h1>
            <div class="space-x-2">
                <a href="edit.php?id=<?php echo $order['id']; ?>" 
                   class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                    Edit Order
                </a>
                <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">
                    Back to Orders
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Order Details -->
            <div class="space-y-6">
                <div class="bg-white shadow rounded-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">Order Information</h2>
                    
                    <!-- Products Section -->
                    <h3 class="text-lg font-semibold mt-6 mb-4">Products</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($products as $product): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                        <?php echo number_format($product['quantity']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                        $<?php echo number_format($product['price'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                        $<?php echo number_format($product['subtotal'], 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="bg-gray-50">
                                    <td colspan="3" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">
                                        Total Amount:
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">
                                        $<?php echo number_format($order['total_amount'], 2); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="space-y-4 mt-6">
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium">Status</span>
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
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium">Company</span>
                            <span><?php echo htmlspecialchars($order['company_name']); ?></span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium">Created At</span>
                            <span><?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?></span>
                        </div>
                    </div>

                    <h3 class="text-lg font-semibold mt-6 mb-4">Customer Details</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium">Name</span>
                            <span><?php echo htmlspecialchars($order['customer_name']); ?></span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium">Phone</span>
                            <span><?php echo htmlspecialchars($order['phone']); ?></span>
                        </div>
                    </div>

                    <h3 class="text-lg font-semibold mt-6 mb-4">Delivery Address</h3>
                    <div class="space-y-4">
                        <p class="text-gray-700">
                            <?php echo htmlspecialchars($order['address_line1']); ?><br>
                            <?php if ($order['address_line2']) echo htmlspecialchars($order['address_line2']) . '<br>'; ?>
                            <?php echo htmlspecialchars($order['city']); ?>, 
                            <?php if ($order['state']) echo htmlspecialchars($order['state']) . ', '; ?>
                            <?php echo htmlspecialchars($order['postal_code']); ?><br>
                            <?php echo htmlspecialchars($order['country']); ?>
                        </p>
                    </div>

                    <?php if ($order['notes']): ?>
                    <h3 class="text-lg font-semibold mt-6 mb-4">Notes</h3>
                    <div class="bg-gray-50 p-4 rounded-md">
                        <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status History and Manifest Info -->
            <div class="space-y-6">
                <!-- Manifest Information -->
                <?php if ($manifest): ?>
                <div class="bg-white shadow rounded-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">Manifest Information</h2>
                    <div class="space-y-4">
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium">Manifest Status</span>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                <?php echo ucfirst($manifest['status']); ?>
                            </span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium">Assigned Rider</span>
                            <span><?php echo htmlspecialchars($manifest['rider_name']); ?></span>
                        </div>
                        <div class="flex justify-between border-b pb-2">
                            <span class="font-medium">Created At</span>
                            <span><?php echo date('M d, Y H:i', strtotime($manifest['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Status History -->
                <div class="bg-white shadow rounded-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">Status History</h2>
                    <div class="flow-root">
                        <ul class="-mb-8">
                            <?php foreach ($status_logs as $index => $log): ?>
                            <li>
                                <div class="relative pb-8">
                                    <?php if ($index !== count($status_logs) - 1): ?>
                                    <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <div class="relative flex space-x-3">
                                        <div>
                                            <span class="h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white
                                                <?php
                                                switch($log['status']) {
                                                    case 'delivered':
                                                        echo 'bg-green-500';
                                                        break;
                                                    case 'pending':
                                                        echo 'bg-yellow-500';
                                                        break;
                                                    case 'failed':
                                                        echo 'bg-red-500';
                                                        break;
                                                    default:
                                                        echo 'bg-blue-500';
                                                }
                                                ?>">
                                                <!-- Status Icon -->
                                                <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                            </span>
                                        </div>
                                        <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                            <div>
                                            <p class="text-sm text-gray-500">
                                                    Status changed to <span class="font-medium text-gray-900"><?php echo ucfirst($log['status']); ?></span>
                                                    by <?php echo htmlspecialchars($log['changed_by_name']); ?>
                                                </p>
                                                <?php if ($log['reason']): ?>
                                                <p class="mt-1 text-sm text-gray-500"><?php echo htmlspecialchars($log['reason']); ?></p>
                                                <?php endif; ?>
                                                <?php if ($log['photo_url']): ?>
                                                <div class="mt-2">
                                                    <img src="<?php echo htmlspecialchars($log['photo_url']); ?>" 
                                                         alt="Status update photo" 
                                                         class="h-32 w-auto rounded-lg shadow">
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($log['delivered_to']): ?>
                                                <p class="mt-1 text-sm text-gray-500">
                                                    Delivered to: <?php echo htmlspecialchars($log['delivered_to']); ?>
                                                </p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-right text-sm whitespace-nowrap text-gray-500">
                                                <?php echo date('M d, Y H:i', strtotime($log['changed_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Add print functionality if needed
        document.addEventListener('DOMContentLoaded', function() {
            // Add any JavaScript functionality here
            
            // Example: Print button functionality
            const printButton = document.getElementById('printOrder');
            if (printButton) {
                printButton.addEventListener('click', function() {
                    window.print();
                });
            }
        });
    </script>
</body>
</html>