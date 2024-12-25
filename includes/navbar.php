<?php
validUser();
$logo_path = SITE_URL . "assets/images/logo.png";
?>
<nav class="bg-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <!-- Logo and Mobile Menu Toggle -->
            <div class="flex items-center justify-between w-full md:w-auto">
                <div class="flex-shrink-0">
                    <a href="<?php echo SITE_URL; ?>">
                        <img class="h-8 w-auto" src="<?php echo $logo_path; ?>" alt="<?php echo SITE_NAME; ?> Logo">
                    </a>
                </div>
                
                <!-- Mobile menu button -->
                <div class="md:hidden">
                    <button 
                        type="button" 
                        id="mobile-menu-toggle" 
                        class="mobile-menu-toggle inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white"
                        aria-controls="mobile-menu" 
                        aria-expanded="false"
                    >
                        <span class="sr-only">Open main menu</span>
                        <!-- Icon when menu is closed -->
                        <svg class="block h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <!-- Icon when menu is open -->
                        <svg class="hidden h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Desktop Navigation -->
            <div class="hidden md:flex md:items-center md:space-x-4">
                <div class="flex items-baseline space-x-4">
                    <a href="<?php echo SITE_URL; ?>pages/dashboard.php" 
                       class="<?php echo (strpos($_SERVER['PHP_SELF'], 'dashboard.php') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                        Dashboard
                    </a>

                    <?php if (isSuperAdmin()): ?>
                    <a href="<?php echo SITE_URL; ?>pages/companies/index.php" 
                       class="<?php echo (strpos($_SERVER['PHP_SELF'], 'companies') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                        Companies
                    </a>
                    <?php endif; ?>

                    <?php if (isSuperAdmin() || isAdmin()): ?>
                    <a href="<?php echo SITE_URL; ?>pages/products/index.php" 
                       class="<?php echo (strpos($_SERVER['PHP_SELF'], 'products') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                        Products
                    </a>
                    <?php endif; ?>

                    <a href="<?php echo SITE_URL; ?>pages/orders/index.php" 
                       class="<?php echo (strpos($_SERVER['PHP_SELF'], 'orders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                        Orders
                    </a>

                    <a href="<?php echo SITE_URL; ?>pages/manifests/index.php" 
                       class="<?php echo (strpos($_SERVER['PHP_SELF'], 'manifests') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                        Manifests
                    </a>

                    <?php if (isSuperAdmin() || isAdmin()): ?>
                    <a href="<?php echo SITE_URL; ?>pages/riders/index.php" 
                       class="<?php echo (strpos($_SERVER['PHP_SELF'], 'riders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                        Riders
                    </a>
                    <?php endif; ?>

                    <?php if (isSuperAdmin() || isAdmin()): ?>
                    <a href="<?php echo SITE_URL; ?>pages/users/index.php" 
                       class="<?php echo (strpos($_SERVER['PHP_SELF'], 'users') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                        
                       <?php echo isAdmin() ? "Admins" : "Users"; ?>

                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Desktop User Info and Logout -->
            <div class="hidden md:flex md:items-center md:ml-6">
                <div class="ml-3 relative flex items-center">
                    <span class="text-gray-300 text-sm mr-4"><?php echo $_SESSION['user_name']; ?></span>
                    <a href="<?php echo SITE_URL; ?>logout.php" 
                       class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium">
                        Logout
                    </a>
                </div>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div class="md:hidden" id="mobile-menu">
            <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3">
                <a href="<?php echo SITE_URL; ?>pages/dashboard.php" 
                   class="<?php echo (strpos($_SERVER['PHP_SELF'], 'dashboard.php') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> block px-3 py-2 rounded-md text-base font-medium">
                    Dashboard
                </a>

                <?php if (isSuperAdmin()): ?>
                <a href="<?php echo SITE_URL; ?>pages/companies/index.php" 
                   class="<?php echo (strpos($_SERVER['PHP_SELF'], 'companies') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> block px-3 py-2 rounded-md text-base font-medium">
                    Companies
                </a>
                <?php endif; ?>

                <?php if (isSuperAdmin() || isAdmin()): ?>
                <a href="<?php echo SITE_URL; ?>pages/products/index.php" 
                   class="<?php echo (strpos($_SERVER['PHP_SELF'], 'products') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> block px-3 py-2 rounded-md text-base font-medium">
                    Products
                </a>
                <?php endif; ?>

                <a href="<?php echo SITE_URL; ?>pages/orders/index.php" 
                   class="<?php echo (strpos($_SERVER['PHP_SELF'], 'orders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> block px-3 py-2 rounded-md text-base font-medium">
                    Orders
                </a>

                <a href="<?php echo SITE_URL; ?>pages/manifests/index.php" 
                   class="<?php echo (strpos($_SERVER['PHP_SELF'], 'manifests') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> block px-3 py-2 rounded-md text-base font-medium">
                    Manifests
                </a>

                <?php if (isSuperAdmin() || isAdmin()): ?>
                <a href="<?php echo SITE_URL; ?>pages/riders/index.php" 
                   class="<?php echo (strpos($_SERVER['PHP_SELF'], 'riders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> block px-3 py-2 rounded-md text-base font-medium">
                    Riders
                </a>
                <?php endif; ?>

                <?php if (isSuperAdmin()): ?>
                <a href="<?php echo SITE_URL; ?>pages/users/index.php" 
                   class="<?php echo (strpos($_SERVER['PHP_SELF'], 'users') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> block px-3 py-2 rounded-md text-base font-medium">
                    Users
                </a>
                <?php endif; ?>

                <!-- Mobile User Info and Logout -->
                <div class="pt-4 pb-3 border-t border-gray-700">
                    <div class="flex items-center px-5">
                        <div class="ml-3">
                            <div class="text-base font-medium leading-none text-white"><?php echo $_SESSION['user_name']; ?></div>
                        </div>
                    </div>
                    <div class="mt-3 px-2">
                        <a href="<?php echo SITE_URL; ?>logout.php" 
                           class="block px-3 py-2 rounded-md text-base font-medium text-gray-400 hover:text-white hover:bg-gray-700">
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Optional JavaScript for Mobile Menu Toggle -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
    const mobileMenu = document.getElementById('mobile-menu');
    const menuOpenIcon = mobileMenuToggle.querySelector('svg:first-child');
    const menuCloseIcon = mobileMenuToggle.querySelector('svg:last-child');

    mobileMenuToggle.addEventListener('click', function() {
        const isExpanded = this.getAttribute('aria-expanded') === 'true';
        
        this.setAttribute('aria-expanded', !isExpanded);
        mobileMenu.classList.toggle('hidden');
        
        menuOpenIcon.classList.toggle('hidden');
        menuCloseIcon.classList.toggle('hidden');
    });
});
</script>


<!-- 
<nav class="bg-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <div class="flex items-center">

                <div class="flex-shrink-0">
                    <a href="<?php echo SITE_URL; ?>">
                    <img class="h-7 w-auto" src="<?php echo $logo_path; ?>" alt="<?php echo SITE_NAME; ?> Logo">
                    </a>
                </div>

                <div class="hidden md:block">
                    <div class="ml-10 flex items-baseline space-x-4">
                        <a href="<?php echo SITE_URL; ?>pages/dashboard.php" 
                           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'dashboard.php') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                            Dashboard
                        </a>

                        <?php if (isSuperAdmin()): ?>
                        <a href="<?php echo SITE_URL; ?>pages/companies/index.php" 
                           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'companies') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                            Companies
                        </a>
                        <?php endif; ?>

                        <?php if (isSuperAdmin() || isAdmin()): ?>
                        <a href="<?php echo SITE_URL; ?>pages/products/index.php" 
                           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'products') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                            Products
                        </a>
                        <?php endif; ?>

                        <a href="<?php echo SITE_URL; ?>pages/orders/index.php" 
                           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'orders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                            Orders
                        </a>

                        <a href="<?php echo SITE_URL; ?>pages/manifests/index.php" 
                           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'manifests') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                            Manifests
                        </a>

                        <?php if (isSuperAdmin() || isAdmin()): ?>
                        <a href="<?php echo SITE_URL; ?>pages/riders/index.php" 
                           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'riders') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                            Riders
                        </a>
                        <?php endif; ?>

                        <?php if (isSuperAdmin()): ?>
                        <a href="<?php echo SITE_URL; ?>pages/users/index.php" 
                           class="<?php echo (strpos($_SERVER['PHP_SELF'], 'users') !== false) ? 'bg-gray-900 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?> px-3 py-2 rounded-md text-sm font-medium">
                            Users
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="hidden md:block">
                <div class="ml-4 flex items-center md:ml-6">
                    <div class="ml-3 relative">
                        <div class="flex items-center">
                            <span class="text-gray-300 text-sm mr-4"><?php echo $_SESSION['user_name']; ?></span>
                            <a href="<?php echo SITE_URL; ?>logout.php" 
                               class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium">
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav> -->