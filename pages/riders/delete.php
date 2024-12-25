<?php
require_once "../../includes/config.php";
requireLogin();

// Only super admin and admin can delete riders
if (!isSuperAdmin() && !isAdmin()) {
    header("Location: ../dashboard.php");
    exit();
}

$error = "";
$rider = null;

if (isset($_GET["id"])) {
    $id = cleanInput($_GET["id"]);

    // Check access rights
    $company_condition = !isSuperAdmin()
        ? "AND rc.company_id = " . $_SESSION["company_id"]
        : "";

    // Fetch rider details with company specific stats
    $query =
        "SELECT u.*, rc.company_id, rc.is_active as rider_company_active, c.name as company_name,
              (SELECT COUNT(*) FROM Manifests WHERE rider_id = u.id AND status != 'delivered' " .
        (!isSuperAdmin() ? "AND company_id = " . $_SESSION["company_id"] : "") .
        ") as active_manifests,
              (SELECT COUNT(*) FROM Manifests WHERE rider_id = u.id " .
        (!isSuperAdmin() ? "AND company_id = " . $_SESSION["company_id"] : "") .
        ") as total_manifests,
              (SELECT COUNT(*) FROM Orders o 
               JOIN ManifestOrders mo ON o.id = mo.order_id 
               JOIN Manifests m ON mo.manifest_id = m.id 
               WHERE m.rider_id = u.id AND o.status = 'delivered' " .
        (!isSuperAdmin()
            ? "AND m.company_id = " . $_SESSION["company_id"]
            : "") .
        ") as delivered_orders
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
        header("Location: index.php");
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["confirm_delete"])) {
    $id = cleanInput($_POST["id"]);

    // Check if rider has active manifests for this company
    if ($rider["active_manifests"] > 0) {
        $error =
            "Cannot remove rider: Has active delivery manifests with the company";
    } else {
        mysqli_begin_transaction($conn);
        try {
            // Delete only from RiderCompanies for specific company
            $delete_rider_company = "DELETE FROM RiderCompanies 
                                   WHERE rider_id = ? AND company_id = ?";
            $stmt = mysqli_prepare($conn, $delete_rider_company);
            $company_id = !isSuperAdmin()
                ? $_SESSION["company_id"]
                : $rider["company_id"];
            mysqli_stmt_bind_param($stmt, "ii", $id, $company_id);
            mysqli_stmt_execute($stmt);

            mysqli_commit($conn);
            header("Location: index.php?deleted=1");
            exit();
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error removing rider: " . $e->getMessage();
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Rider - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
    <body class="bg-gray-100">
        <?php include_once '../../includes/navbar.php'; ?>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Remove Rider</h1>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $error; ?></span>
        </div>
        <?php endif; ?>

        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
            <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Warning</h3>
                        <div class="mt-2 text-sm text-red-700">
                            <p>You are about to remove the rider <strong><?php echo htmlspecialchars($rider['name']); ?></strong> from <?php echo htmlspecialchars($rider['company_name']); ?></p>
                            <p class="mt-2">This will:</p>
                            <ul class="list-disc list-inside mt-1">
                                <li>Remove rider's access to this company</li>
                                <li>Preserve delivery history and records</li>
                                <li>Not affect rider's assignments with other companies</li>
                                <li>Prevent assignment to future manifests for this company</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="id" value="<?php echo $rider['id']; ?>">
                
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Rider Details -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Rider Information</h3>
                        <ul class="text-sm text-gray-600 space-y-2">
                            <li><strong>Name:</strong> <?php echo htmlspecialchars($rider['name']); ?></li>
                            <li><strong>Email:</strong> <?php echo htmlspecialchars($rider['email']); ?></li>
                            <li><strong>Phone:</strong> <?php echo htmlspecialchars($rider['phone']); ?></li>
                            <li><strong>Company:</strong> <?php echo htmlspecialchars($rider['company_name']); ?></li>
                            <li><strong>Status:</strong> <?php echo $rider['rider_company_active'] ? 'Active' : 'Inactive'; ?></li>
                            <li><strong>Member Since:</strong> <?php echo date('M d, Y', strtotime($rider['created_at'])); ?></li>
                        </ul>
                    </div>

                    <!-- Company Specific Delivery Stats -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Company Statistics</h3>
                        <ul class="text-sm text-gray-600 space-y-2">
                            <li><strong>Total Manifests:</strong> <?php echo $rider['total_manifests']; ?></li>
                            <li><strong>Active Manifests:</strong> <?php echo $rider['active_manifests']; ?></li>
                            <li><strong>Delivered Orders:</strong> <?php echo $rider['delivered_orders']; ?></li>
                        </ul>
                    </div>
                </div>

                <div class="flex justify-end space-x-3 mt-6">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <button type="submit" name="confirm_delete" 
                            class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700"
                            <?php echo $rider['active_manifests'] > 0 ? 'disabled' : ''; ?>>
                        Confirm Remove
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>