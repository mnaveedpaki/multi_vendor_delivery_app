<?php
require_once '../../includes/config.php';
requireLogin();

// Only super admin and admin can edit users
if (!isSuperAdmin() && !isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$success = '';
$user = null;

// Fetch user details
if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND company_id = " . $_SESSION['company_id'] : "";
    
    $query = "SELECT * FROM Users WHERE id = ? $company_condition";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    
    if (!$user) {
        header('Location: index.php');
        exit();
    }
}

// Fetch companies for super admin
$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = cleanInput($_POST['id']);
    $name = cleanInput($_POST['name']);
    $email = cleanInput($_POST['email']);
    $phone = cleanInput($_POST['phone']);
    $user_type = cleanInput($_POST['user_type']);
    $company_id = isSuperAdmin() ? cleanInput($_POST['company_id']) : $_SESSION['company_id'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($name) || empty($email)) {
        $error = 'Please fill in all required fields';
    } elseif (!empty($password) && $password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        // Build update query based on whether password is being changed
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $query = "UPDATE Users SET 
                      name = ?, 
                      email = ?,
                      phone = ?,
                      password = ?,
                      user_type = ?,
                      company_id = ?
                      WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "sssssii", 
                $name, $email, $phone, $hashed_password, $user_type, $company_id, $id
            );
        } else {
            $query = "UPDATE Users SET 
                      name = ?, 
                      email = ?,
                      phone = ?,
                      user_type = ?,
                      company_id = ?
                      WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ssssii", 
                $name, $email, $phone, $user_type, $company_id, $id
            );
        }
        
        if (mysqli_stmt_execute($stmt)) {
            $success = 'User updated successfully';
            // Refresh user data
            $user = array_merge($user, $_POST);
        } else {
            $error = 'Error updating user: ' . mysqli_error($conn);
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
    <title>Edit User - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Edit User</h1>
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
            <form action="" method="POST" class="space-y-6">
                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                        <input type="text" name="name" id="name" required
                               value="<?php echo htmlspecialchars($user['name']); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email *</label>
                        <input type="email" name="email" id="email" required
                               value="<?php echo htmlspecialchars($user['email']); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                        <input type="text" name="phone" id="phone"
                               value="<?php echo htmlspecialchars($user['phone']); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="user_type" class="block text-sm font-medium text-gray-700">User Type *</label>
                        <select name="user_type" id="user_type" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            <?php if (isSuperAdmin()): ?>
                            <option value="Super Admin" <?php echo $user['user_type'] === 'Super Admin' ? 'selected' : ''; ?>>Super Admin</option>
                            <?php endif; ?>
                            <option value="Admin" <?php echo $user['user_type'] === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="Rider" <?php echo $user['user_type'] === 'Rider' ? 'selected' : ''; ?>>Rider</option>
                        </select>
                    </div>

                    <?php if (isSuperAdmin()): ?>
                    <div>
                        <label for="company_id" class="block text-sm font-medium text-gray-700">Company</label>
                        <select name="company_id" id="company_id"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            <option value="">None</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>" 
                                        <?php echo $company['id'] == $user['company_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-span-2">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Change Password</h3>
                        <p class="text-sm text-gray-500 mb-4">Leave password fields empty to keep current password</p>
                        
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700">New Password</label>
                                <input type="password" name="password" id="password"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            </div>

                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confirm_password"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Update User</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>