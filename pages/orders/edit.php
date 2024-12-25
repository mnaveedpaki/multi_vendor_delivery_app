<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';
$order = null;
$is_delivered = false;

// Fetch order details
if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND o.company_id = " . $_SESSION['company_id'] : "";
    
    // Modified query to get order with product details
    $query = "SELECT o.*, GROUP_CONCAT(po.id) as product_order_ids, 
                     GROUP_CONCAT(po.product_id) as product_ids,
                     GROUP_CONCAT(po.quantity) as quantities,
                     GROUP_CONCAT(po.price) as prices
              FROM Orders o 
              LEFT JOIN ProductOrders po ON o.id = po.order_id
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

    // Check if order is delivered
    $is_delivered = $order['status'] === 'delivered';

    // Process the concatenated product data
    $order['product_order_ids'] = $order['product_order_ids'] ? explode(',', $order['product_order_ids']) : [];
    $order['product_ids'] = $order['product_ids'] ? explode(',', $order['product_ids']) : [];
    $order['quantities'] = $order['quantities'] ? explode(',', $order['quantities']) : [];
    $order['prices'] = $order['prices'] ? explode(',', $order['prices']) : [];
}

// Only fetch additional data if order is not delivered
if (!$is_delivered) {
    // Fetch companies for super admin
    $companies = [];
    if (isSuperAdmin()) {
        $companies_query = "SELECT id, name FROM Companies ORDER BY name";
        $companies_result = mysqli_query($conn, $companies_query);
        while ($row = mysqli_fetch_assoc($companies_result)) {
            $companies[] = $row;
        }
    }

    // Fetch products for the current company
    $products = [];
    $company_id = isSuperAdmin() ? $order['company_id'] : $_SESSION['company_id'];
    if ($company_id) {
        $products_query = "SELECT id, name, description FROM Products WHERE company_id = ? ORDER BY name";
        $stmt = mysqli_prepare($conn, $products_query);
        mysqli_stmt_bind_param($stmt, "i", $company_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $products[] = $row;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if order is delivered before processing any updates
    $id = cleanInput($_POST['id']);
    $check_status = "SELECT status FROM Orders WHERE id = ?";
    $stmt = mysqli_prepare($conn, $check_status);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $status_result = mysqli_stmt_get_result($stmt);
    $current_status = mysqli_fetch_assoc($status_result)['status'];

    if ($current_status === 'delivered') {
        $error = 'Cannot modify a delivered order';
    } else {
        $customer_name = cleanInput($_POST['customer_name']);
        $phone = cleanInput($_POST['phone']);
        $status = cleanInput($_POST['status']);
        $address_line1 = cleanInput($_POST['address_line1']);
        $address_line2 = cleanInput($_POST['address_line2']);
        $city = cleanInput($_POST['city']);
        $state = cleanInput($_POST['state']);
        $postal_code = cleanInput($_POST['postal_code']);
        $country = cleanInput($_POST['country']);
        $notes = cleanInput($_POST['notes']);
        $total_amount = cleanInput($_POST['total_amount']);
        $company_id = isSuperAdmin() ? cleanInput($_POST['company_id']) : $_SESSION['company_id'];

        // Get product data
        $product_ids = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
        $product_quantities = isset($_POST['quantities']) ? $_POST['quantities'] : [];
        $product_prices = isset($_POST['prices']) ? $_POST['prices'] : [];

        if (empty($customer_name) || empty($address_line1) || empty($city)) {
            $error = 'Please fill in all required fields';
        } elseif (empty($product_ids)) {
            $error = 'Please select at least one product';
        } else {
            mysqli_begin_transaction($conn);
            try {
                // Update order
                $query = "UPDATE Orders SET 
                          customer_name = ?, 
                          phone = ?,
                          status = ?,
                          address_line1 = ?,
                          address_line2 = ?,
                          city = ?,
                          state = ?,
                          postal_code = ?,
                          country = ?,
                          notes = ?,
                          total_amount = ?,
                          company_id = ?
                          WHERE id = ?";
                
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "ssssssssssdii", 
                    $customer_name, $phone, $status, $address_line1, $address_line2,
                    $city, $state, $postal_code, $country, $notes, $total_amount, $company_id, $id
                );
                
                if (mysqli_stmt_execute($stmt)) {
                    // Delete existing product orders
                    $delete_query = "DELETE FROM ProductOrders WHERE order_id = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    mysqli_stmt_bind_param($delete_stmt, "i", $id);
                    mysqli_stmt_execute($delete_stmt);

                    // Insert updated product orders
                    $product_query = "INSERT INTO ProductOrders (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
                    $product_stmt = mysqli_prepare($conn, $product_query);
                    
                    foreach ($product_ids as $index => $product_id) {
                        if (!empty($product_id)) {
                            mysqli_stmt_bind_param($product_stmt, "iiid", 
                                $id, 
                                $product_id, 
                                $product_quantities[$index],
                                $product_prices[$index]
                            );
                            mysqli_stmt_execute($product_stmt);
                        }
                    }

                    // Add status log if status changed
                    if ($status !== $order['status']) {
                        $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, ?, ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        mysqli_stmt_bind_param($log_stmt, "isi", $id, $status, $_SESSION['user_id']);
                        mysqli_stmt_execute($log_stmt);
                    }
                    
                    mysqli_commit($conn);
                    $success = 'Order updated successfully';
                    
                    // Refresh order data
                    header("Location: edit.php?id=" . $id . "&success=" . urlencode($success));
                    exit();
                } else {
                    throw new Exception('Error updating order: ' . mysqli_error($conn));
                }
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
            }
        }
    }
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
    <title>Edit Order - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Edit Order #<?php echo htmlspecialchars($order['order_number']); ?></h1>
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

        <?php if ($is_delivered): ?>
        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline">This order is delivered and cannot be modified.</span>
        </div>
        <?php endif; ?>

        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
            <form action="" method="POST" id="orderForm">
                <input type="hidden" name="id" value="<?php echo $order['id']; ?>">
                
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Customer Information -->
                    <div class="space-y-6">
                        <h2 class="text-xl font-semibold">Customer Information</h2>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Status</label>
                            <select name="status" <?php echo $is_delivered ? 'disabled' : ''; ?> 
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                                <?php
                                $statuses = ['pending', 'assigned', 'delivering', 'delivered', 'failed'];
                                foreach ($statuses as $status) {
                                    $selected = $status === $order['status'] ? 'selected' : '';
                                    echo "<option value='$status' $selected>" . ucfirst($status) . "</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div>
                            <label for="customer_name" class="block text-sm font-medium text-gray-700">Customer Name *</label>
                            <input type="text" name="customer_name" id="customer_name" required
                                   value="<?php echo htmlspecialchars($order['customer_name']); ?>"
                                   <?php echo $is_delivered ? 'readonly' : ''; ?>
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                            <input type="text" name="phone" id="phone"
                                   value="<?php echo htmlspecialchars($order['phone']); ?>"
                                   <?php echo $is_delivered ? 'readonly' : ''; ?>
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                        </div>

                        <?php if (isSuperAdmin()): ?>
                        <div>
                            <label for="company_id" class="block text-sm font-medium text-gray-700">Company *</label>
                            <select name="company_id" id="company_id" required <?php echo $is_delivered ? 'disabled' : ''; ?>
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo $company['id']; ?>" 
                                            <?php echo $company['id'] == $order['company_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($company['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Delivery Address -->
                    <div class="space-y-6">
                        <h2 class="text-xl font-semibold">Delivery Address</h2>
                        
                        <div>
                            <label for="address_line1" class="block text-sm font-medium text-gray-700">Address Line 1 *</label>
                            <input type="text" name="address_line1" id="address_line1" required
                                   value="<?php echo htmlspecialchars($order['address_line1']); ?>"
                                   <?php echo $is_delivered ? 'readonly' : ''; ?>
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                        </div>

                        <div>
                            <label for="address_line2" class="block text-sm font-medium text-gray-700">Address Line 2</label>
                            <input type="text" name="address_line2" id="address_line2"
                                   value="<?php echo htmlspecialchars($order['address_line2']); ?>"
                                   <?php echo $is_delivered ? 'readonly' : ''; ?>
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                        </div>

                        <div>
                            <label for="city" class="block text-sm font-medium text-gray-700">City *</label>
                            <input type="text" name="city" id="city" required
                                   value="<?php echo htmlspecialchars($order['city']); ?>"
                                   <?php echo $is_delivered ? 'readonly' : ''; ?>
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                        </div>

                        <div>
                            <label for="state" class="block text-sm font-medium text-gray-700">State/Province</label>
                            <input type="text" name="state" id="state"
                                   value="<?php echo htmlspecialchars($order['state']); ?>"
                                   <?php echo $is_delivered ? 'readonly' : ''; ?>
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                        </div>

                        <div>
                            <label for="postal_code" class="block text-sm font-medium text-gray-700">Postal Code</label>
                            <input type="text" name="postal_code" id="postal_code"
                                   value="<?php echo htmlspecialchars($order['postal_code']); ?>"
                                   <?php echo $is_delivered ? 'readonly' : ''; ?>
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                        </div>

                        <div>
                            <label for="country" class="block text-sm font-medium text-gray-700">Country</label>
                            <input type="text" name="country" id="country"
                                   value="<?php echo htmlspecialchars($order['country']); ?>"
                                   <?php echo $is_delivered ? 'readonly' : ''; ?>
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                        </div>
                    </div>
                </div>

                <!-- Products Section -->
                <div class="mt-6 space-y-6">
                    <h2 class="text-xl font-semibold">Products</h2>
                    
                    <?php if (!empty($products) && !$is_delivered): ?>
                        <div id="products-container">
                            <?php for ($i = 0; $i < max(1, count($order['product_ids'])); $i++): ?>
                            <div class="product-row grid grid-cols-4 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Product *</label>
                                    <select name="product_ids[]" required <?php echo $is_delivered ? 'disabled' : ''; ?> 
                                            class="product-select mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>"
                                                    <?php echo (isset($order['product_ids'][$i]) && $order['product_ids'][$i] == $product['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Quantity *</label>
                                    <input type="number" name="quantities[]" min="1" required
                                           value="<?php echo isset($order['quantities'][$i]) ? htmlspecialchars($order['quantities'][$i]) : '1'; ?>"
                                           <?php echo $is_delivered ? 'readonly' : ''; ?>
                                           class="quantity-input mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Price *</label>
                                    <input type="number" name="prices[]" step="0.01" required
                                           value="<?php echo isset($order['prices'][$i]) ? htmlspecialchars($order['prices'][$i]) : ''; ?>"
                                           <?php echo $is_delivered ? 'readonly' : ''; ?>
                                           class="price-input mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                                </div>
                                <?php if (!$is_delivered): ?>
                                <div class="flex items-end">
                                    <button type="button" class="remove-product bg-red-500 text-white px-3 py-2 rounded-md hover:bg-red-600">Remove</button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <?php if (!$is_delivered): ?>
                        <button type="button" id="add-product" class="bg-green-500 text-white px-4 py-2 rounded-md hover:bg-green-600">
                            Add Another Product
                        </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-gray-500 italic">
                            <?php echo $is_delivered ? 'Order is delivered. Products cannot be modified.' : 'No products available for this company.'; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Order Details -->
                <div class="mt-6 space-y-6">
                    <h2 class="text-xl font-semibold">Order Details</h2>
                    
                    <div>
                        <label for="total_amount" class="block text-sm font-medium text-gray-700">Total Amount</label>
                        <div class="mt-1 relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 sm:text-sm">$</span>
                            </div>
                            <input type="number" name="total_amount" id="total_amount" step="0.01" readonly
                                   value="<?php echo htmlspecialchars($order['total_amount']); ?>"
                                   class="pl-7 mt-1 block w-full rounded-md border-gray-300 bg-gray-50 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea name="notes" id="notes" rows="3" <?php echo $is_delivered ? 'readonly' : ''; ?>
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>"><?php echo htmlspecialchars($order['notes']); ?></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <?php if (!$is_delivered): ?>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Update Order</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            <?php if (!$is_delivered): ?>
            // Function to calculate total amount
            function calculateTotal() {
                let total = 0;
                $('.product-row').each(function() {
                    const quantity = parseFloat($(this).find('.quantity-input').val()) || 0;
                    const price = parseFloat($(this).find('.price-input').val()) || 0;
                    total += quantity * price;
                });
                $('#total_amount').val(total.toFixed(2));
            }

            // Add product row
            $('#add-product').click(function() {
                const newRow = $('.product-row').first().clone();
                newRow.find('input').val('');
                newRow.find('select').val('');
                newRow.find('.quantity-input').val(1);
                $('#products-container').append(newRow);
            });

            // Remove product row
            $(document).on('click', '.remove-product', function() {
                if ($('.product-row').length > 1) {
                    $(this).closest('.product-row').remove();
                    calculateTotal();
                }
            });

            // Calculate total on input change
            $(document).on('input', '.quantity-input, .price-input', calculateTotal);

            // Handle form submission
            $('#orderForm').on('submit', function(e) {
                const status = $('select[name="status"]').val();
                
                if (status === 'delivered') {
                    return confirm('Are you sure you want to mark this order as delivered? This action cannot be undone.');
                }
                
                const productSelects = $('.product-select');
                const selectedProducts = new Set();
                let hasError = false;

                // Check for duplicate products
                productSelects.each(function() {
                    const value = $(this).val();
                    if (value && selectedProducts.has(value)) {
                        alert('Please avoid selecting the same product multiple times. Instead, adjust the quantity as needed.');
                        hasError = true;
                        return false;
                    }
                    if (value) {
                        selectedProducts.add(value);
                    }
                });

                if (hasError) {
                    e.preventDefault();
                    return false;
                }

                return true;
            });

            // Validate price and quantity inputs
            $('.price-input').on('input', function() {
                const value = parseFloat($(this).val());
                if (value < 0) {
                    alert('Price cannot be negative');
                    $(this).val('');
                    calculateTotal();
                }
            });

            $('.quantity-input').on('input', function() {
                const value = parseInt($(this).val());
                if (value < 1) {
                    alert('Quantity must be at least 1');
                    $(this).val(1);
                    calculateTotal();
                }
            });

            // Calculate initial total
            calculateTotal();
            <?php endif; ?>
        });
    </script>
</body>
</html>