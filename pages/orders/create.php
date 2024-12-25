<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Generate unique order number
function generateOrderNumber($conn) {
    do {
        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $check_query = "SELECT id FROM Orders WHERE order_number = '$order_number'";
        $result = mysqli_query($conn, $check_query);
    } while (mysqli_num_rows($result) > 0);
    
    return $order_number;
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

// Get company ID for product fetching
$current_company_id = isSuperAdmin() ? 
    (isset($_POST['company_id']) ? cleanInput($_POST['company_id']) : 0) : 
    $_SESSION['company_id'];

// Fetch products for the selected company
$products = [];
if ($current_company_id) {
    $products_query = "SELECT id, name, description FROM Products WHERE company_id = ? ORDER BY name";
    $stmt = mysqli_prepare($conn, $products_query);
    mysqli_stmt_bind_param($stmt, "i", $current_company_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $products[] = $row;
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Print POST data
    error_log("POST Data: " . print_r($_POST, true));
    
    // Check if this is just a company change
    if (isset($_POST['just_company_change']) && $_POST['just_company_change'] === '1') {
        // Do nothing, just refresh the page with new company
    } else {
        // Get form data
        $customer_name = cleanInput($_POST['customer_name']);
        $phone = cleanInput($_POST['phone']);
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

        error_log("Processed form data:");
        error_log("Customer Name: " . $customer_name);
        error_log("Products: " . print_r($product_ids, true));

        // Validate required fields
        if (empty($customer_name) || empty($address_line1) || empty($city)) {
            $error = 'Please fill in all required fields';
        } elseif (empty($product_ids)) {
            $error = 'Please select at least one product';
        } else {
            try {
                mysqli_begin_transaction($conn);
                error_log("Transaction started");

                // Generate order number
                $order_number = generateOrderNumber($conn);
                error_log("Generated order number: " . $order_number);

                // Insert order
                $query = "INSERT INTO Orders (order_number, customer_name, phone, status, address_line1, 
                                            address_line2, city, state, postal_code, country, notes, 
                                            total_amount, company_id) 
                          VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = mysqli_prepare($conn, $query);
                if (!$stmt) {
                    throw new Exception('Error preparing order statement: ' . mysqli_error($conn));
                }

                mysqli_stmt_bind_param($stmt, "ssssssssssdi", 
                    $order_number, $customer_name, $phone, $address_line1, $address_line2, 
                    $city, $state, $postal_code, $country, $notes, $total_amount, $company_id
                );
                
                error_log("Executing order insert");
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception('Error executing order statement: ' . mysqli_stmt_error($stmt));
                }

                $order_id = mysqli_insert_id($conn);
                error_log("Order created with ID: " . $order_id);

                // Insert product orders
                $product_query = "INSERT INTO ProductOrders (order_id, product_id, quantity, price) 
                                VALUES (?, ?, ?, ?)";
                $product_stmt = mysqli_prepare($conn, $product_query);
                if (!$product_stmt) {
                    throw new Exception('Error preparing product statement: ' . mysqli_error($conn));
                }

                foreach ($product_ids as $index => $product_id) {
                    if (!empty($product_id)) {
                        error_log("Adding product ID: " . $product_id);
                        mysqli_stmt_bind_param($product_stmt, "iiid", 
                            $order_id, 
                            $product_id, 
                            $product_quantities[$index],
                            $product_prices[$index]
                        );
                        if (!mysqli_stmt_execute($product_stmt)) {
                            throw new Exception('Error executing product statement: ' . mysqli_stmt_error($product_stmt));
                        }
                    }
                }
                
                // Create initial status log
                $user_id = $_SESSION['user_id'];
                $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, 'pending', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                if (!$log_stmt) {
                    throw new Exception('Error preparing log statement: ' . mysqli_error($conn));
                }

                mysqli_stmt_bind_param($log_stmt, "ii", $order_id, $user_id);
                if (!mysqli_stmt_execute($log_stmt)) {
                    throw new Exception('Error executing log statement: ' . mysqli_stmt_error($log_stmt));
                }
                
                mysqli_commit($conn);
                error_log("Transaction committed successfully");
                
                $success = 'Order created successfully';
                header("Location: index.php");
                exit();
            } catch (Exception $e) {
                mysqli_rollback($conn);
                error_log("Error occurred: " . $e->getMessage());
                $error = $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Order - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Create New Order</h1>
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
            <form action="" method="POST" id="orderForm">
                <input type="hidden" name="just_company_change" id="just_company_change" value="0">
                <!-- Rest of your form HTML remains the same -->
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Customer Information -->
                    <div class="space-y-6">
                        <h2 class="text-xl font-semibold">Customer Information</h2>
                        
                        <div>
                            <label for="customer_name" class="block text-sm font-medium text-gray-700">Customer Name *</label>
                            <input type="text" name="customer_name" id="customer_name" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                            <input type="text" name="phone" id="phone"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <?php if (isSuperAdmin()): ?>
                        <div>
                            <label for="company_id" class="block text-sm font-medium text-gray-700">Company *</label>
                            <select name="company_id" id="company_id" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo $company['id']; ?>" 
                                            <?php echo ($current_company_id == $company['id']) ? 'selected' : ''; ?>>
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
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="address_line2" class="block text-sm font-medium text-gray-700">Address Line 2</label>
                            <input type="text" name="address_line2" id="address_line2"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="city" class="block text-sm font-medium text-gray-700">City *</label>
                            <input type="text" name="city" id="city" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="state" class="block text-sm font-medium text-gray-700">State/Province</label>
                            <input type="text" name="state" id="state"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="postal_code" class="block text-sm font-medium text-gray-700">Postal Code</label>
                            <input type="text" name="postal_code" id="postal_code"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="country" class="block text-sm font-medium text-gray-700">Country</label>
                            <input type="text" name="country" id="country"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                    </div>
                </div>

                <!-- Products Section -->
                <div class="mt-6 space-y-6">
                    <h2 class="text-xl font-semibold">Products</h2>
                    
                    <?php if ($current_company_id && !empty($products)): ?>
                        <div id="products-container">
                            <div class="product-row grid grid-cols-4 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Product *</label>
                                    <select name="product_ids[]" required class="product-select mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Quantity *</label>
                                    <input type="number" name="quantities[]" min="1" value="1" required
                                           class="quantity-input mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Price *</label>
                                    <input type="number" name="prices[]" step="0.01" required
                                           class="price-input mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                </div>
                                <div class="flex items-end">
                                    <button type="button" class="remove-product bg-red-500 text-white px-3 py-2 rounded-md hover:bg-red-600">Remove</button>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="add-product" class="bg-green-500 text-white px-4 py-2 rounded-md hover:bg-green-600">
                            Add Another Product
                        </button>
                    <?php elseif ($current_company_id): ?>
                        <div class="text-gray-500 italic">No products available for this company.</div>
                    <?php else: ?>
                        <div class="text-gray-500 italic">Please select a company to view available products.</div>
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
                                   class="pl-7 mt-1 block w-full rounded-md border-gray-300 bg-gray-50 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea name="notes" id="notes" rows="3"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Create Order</button>
                </div>
            </form>
        </div>
    </div>

    <!-- <script>
        $(document).ready(function() {
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
                if ($('#just_company_change').val() === '1') {
                    return true;
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

                // Check if at least one product is selected
                if (selectedProducts.size === 0) {
                    alert('Please select at least one product.');
                    hasError = true;
                }

                if (hasError) {
                    e.preventDefault();
                    return false;
                }
            });

            // Handle company selection change
            $('#company_id').change(function() {
                $('#just_company_change').val('1');
                
                // Store form data in localStorage before submitting
                const formData = {
                    customer_name: $('#customer_name').val(),
                    phone: $('#phone').val(),
                    address_line1: $('#address_line1').val(),
                    address_line2: $('#address_line2').val(),
                    city: $('#city').val(),
                    state: $('#state').val(),
                    postal_code: $('#postal_code').val(),
                    country: $('#country').val(),
                    notes: $('#notes').val()
                };
                localStorage.setItem('orderFormData', JSON.stringify(formData));
                
                // Submit form to refresh products
                this.form.submit();
            });

            // Restore form data after company change
            if (localStorage.getItem('orderFormData')) {
                const formData = JSON.parse(localStorage.getItem('orderFormData'));
                for (const [key, value] of Object.entries(formData)) {
                    $(`#${key}`).val(value);
                }
                localStorage.removeItem('orderFormData');
            }
        });
    </script> -->

    <script>
        $(document).ready(function() {
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
                // Check if this is a company change
                if ($('#just_company_change').val() === '1') {
                    return true;
                }

                e.preventDefault(); // Prevent default submission

                // Validate required fields
                const requiredFields = ['customer_name', 'address_line1', 'city'];
                let hasError = false;

                requiredFields.forEach(field => {
                    if (!$(`#${field}`).val()) {
                        alert(`Please fill in ${field.replace('_', ' ')}`);
                        hasError = true;
                    }
                });

                // Validate products
                const productSelects = $('.product-select');
                const selectedProducts = new Set();

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

                if (selectedProducts.size === 0) {
                    alert('Please select at least one product');
                    hasError = true;
                }

                // Check if all products have prices
                $('.price-input').each(function() {
                    if (!$(this).val() && $(this).closest('.product-row').find('.product-select').val()) {
                        alert('Please enter prices for all selected products');
                        hasError = true;
                        return false;
                    }
                });

                if (!hasError) {
                    // If all validations pass, submit the form
                    console.log('Submitting form...');
                    this.submit();
                }
            });

            // Handle company selection change
            $('#company_id').change(function() {
                $('#just_company_change').val('1');
                
                // Store form data
                const formData = {
                    customer_name: $('#customer_name').val(),
                    phone: $('#phone').val(),
                    address_line1: $('#address_line1').val(),
                    address_line2: $('#address_line2').val(),
                    city: $('#city').val(),
                    state: $('#state').val(),
                    postal_code: $('#postal_code').val(),
                    country: $('#country').val(),
                    notes: $('#notes').val()
                };
                localStorage.setItem('orderFormData', JSON.stringify(formData));
                
                this.form.submit();
            });

            // Restore form data after company change
            if (localStorage.getItem('orderFormData')) {
                const formData = JSON.parse(localStorage.getItem('orderFormData'));
                for (const [key, value] of Object.entries(formData)) {
                    $(`#${key}`).val(value);
                }
                localStorage.removeItem('orderFormData');
            }

            // Initial total calculation
            calculateTotal();
        });
    </script>
</body>
</html>