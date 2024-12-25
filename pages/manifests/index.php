<?php
require_once '../../includes/config.php';
requireLogin();

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filtering
$status_filter = isset($_GET['status']) ? cleanInput($_GET['status']) : '';
$rider_filter = isset($_GET['rider_id']) ? cleanInput($_GET['rider_id']) : '';

// Build WHERE clause
$where_conditions = [];
if (!isSuperAdmin()) {
    $where_conditions[] = "m.company_id = " . $_SESSION['company_id'];
}
if ($status_filter) {
    $where_conditions[] = "m.status = '$status_filter'";
}
if ($rider_filter) {
    $where_conditions[] = "m.rider_id = '$rider_filter'";
}

$where_clause = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total records
$count_query = "SELECT COUNT(*) as total FROM Manifests m $where_clause";
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $limit);

// Fetch riders for filter
$rider_query = "SELECT id, name FROM Users WHERE user_type = 'Rider'";
if (!isSuperAdmin()) {
    $rider_query .= " AND company_id = " . $_SESSION['company_id'];
}
$riders_result = mysqli_query($conn, $rider_query);

// Fetch manifests with related data
$query = "SELECT m.*, 
          u.name as rider_name,
          c.name as company_name,
          COUNT(mo.order_id) as order_count,
          SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count
          FROM Manifests m 
          LEFT JOIN Users u ON m.rider_id = u.id
          LEFT JOIN Companies c ON m.company_id = c.id
          LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
          LEFT JOIN Orders o ON mo.order_id = o.id
          $where_clause
          GROUP BY m.id
          ORDER BY m.created_at DESC
          LIMIT $offset, $limit";

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manifests - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Delivery Manifests</h1>
            <a href="create.php" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Create Manifest</a>
        </div>

        <!-- Filters -->
        <div class="bg-white p-4 rounded-lg shadow mb-6">
            <form method="GET" class="flex gap-4">
                <div>
                    <select name="status" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                        <option value="delivering" <?php echo $status_filter === 'delivering' ? 'selected' : ''; ?>>Delivering</option>
                        <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    </select>
                </div>
                <div>
                    <select name="rider_id" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All Riders</option>
                        <?php while ($rider = mysqli_fetch_assoc($riders_result)): ?>
                            <option value="<?php echo $rider['id']; ?>" 
                                    <?php echo $rider_filter == $rider['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($rider['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <button type="submit" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Filter</button>
                <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Reset</a>
            </form>
        </div>

        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID/Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rider</th>
                        <?php if (isSuperAdmin()): ?>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                        <?php endif; ?>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Orders</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progress</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php while ($manifest = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">#<?php echo $manifest['id']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo date('M d, Y H:i', strtotime($manifest['created_at'])); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($manifest['rider_name'] ?? 'Not Assigned'); ?></div>
                        </td>
                        <?php if (isSuperAdmin()): ?>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($manifest['company_name']); ?></div>
                        </td>
                        <?php endif; ?>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php
                                switch($manifest['status']) {
                                    case 'delivered':
                                        echo 'bg-green-100 text-green-800';
                                        break;
                                    case 'pending':
                                        echo 'bg-yellow-100 text-yellow-800';
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
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo $manifest['order_count']; ?> Orders
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $progress = $manifest['order_count'] > 0 
                                ? ($manifest['delivered_count'] / $manifest['order_count']) * 100 
                                : 0;
                            ?>
                            <div class="flex items-center">
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-green-600 h-2.5 rounded-full" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                                <span class="ml-2 text-sm text-gray-600"><?php echo round($progress); ?>%</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="view.php?id=<?php echo $manifest['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">View</a>
                            <a href="edit.php?id=<?php echo $manifest['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                            <a href="delete.php?id=<?php echo $manifest['id']; ?>" class="text-red-600 hover:text-red-900">Delete</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6 mt-4">
            <div class="flex-1 flex justify-between sm:hidden">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo ($page - 1); ?>&status=<?php echo $status_filter; ?>&rider_id=<?php echo $rider_filter; ?>" 
                       class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        Previous
                    </a>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo ($page + 1); ?>&status=<?php echo $status_filter; ?>&rider_id=<?php echo $rider_filter; ?>" 
                       class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        Next
                    </a>
                <?php endif; ?>
            </div>
            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-gray-700">
                        Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to 
                        <span class="font-medium"><?php echo min($offset + $limit, $total_records); ?></span> of 
                        <span class="font-medium"><?php echo $total_records; ?></span> results
                    </p>
                </div>
                <div>
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&rider_id=<?php echo $rider_filter; ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 
                                      <?php echo $i === $page ? 'bg-gray-100' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </nav>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>