<?php
require_once '../../includes/config.php';
requireLogin();

// Only super admin can access this page
if (!isSuperAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$success = '';
$company = null;

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    $query = "SELECT * FROM Companies WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $company = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$company) {
        header('Location: index.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = cleanInput($_POST['id']);
    $name = cleanInput($_POST['name']);
    $phone = cleanInput($_POST['phone']);
    $email = cleanInput($_POST['email']);
    $address = cleanInput($_POST['address']);
    $total_riders_allowed = cleanInput($_POST['total_riders_allowed']);

    if (empty($name)) {
        $error = 'Company name is required';
    } else {
        $query = "UPDATE Companies SET 
                  name = ?, 
                  phone = ?, 
                  email = ?, 
                  address = ?, 
                  total_riders_allowed = ?
                  WHERE id = ?";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ssssii", $name, $phone, $email, $address, $total_riders_allowed, $id);
        
        if (mysqli_stmt_execute($stmt)) {
            $success = 'Company updated successfully';
            // Refresh company data
            $company['name'] = $name;
            $company['phone'] = $phone;
            $company['email'] = $email;
            $company['address'] = $address;
            $company['total_riders_allowed'] = $total_riders_allowed;
        } else {
            $error = 'Error updating company: ' . mysqli_error($conn);
        }
        
        mysqli_stmt_close($stmt);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Company - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Edit Company</h1>
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

        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
            <form action="" method="POST">
                <input type="hidden" name="id" value="<?php echo $company['id']; ?>">
                
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium text-gray-700">Company Name</label>
                    <input type="text" name="name" id="name" required 
                           value="<?php echo htmlspecialchars($company['name']); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div class="mb-4">
                    <label for="phone" class="block text-sm font-medium text-gray-700">Phone</label>
                    <input type="text" name="phone" id="phone"
                           value="<?php echo htmlspecialchars($company['phone']); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input type="email" name="email" id="email"
                           value="<?php echo htmlspecialchars($company['email']); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div class="mb-4">
                    <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                    <textarea name="address" id="address" rows="3"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"><?php echo htmlspecialchars($company['address']); ?></textarea>
                </div>

                <div class="mb-4">
                    <label for="total_riders_allowed" class="block text-sm font-medium text-gray-700">Total Riders Allowed</label>
                    <input type="number" name="total_riders_allowed" id="total_riders_allowed"
                           value="<?php echo htmlspecialchars($company['total_riders_allowed']); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Update Company</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>