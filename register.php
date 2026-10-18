<?php
//আমাদের system-এ এই ৩টা role-এর registration allowed।
//Admin এখানে register করতে পারবে না।
$allowedRoles = ['consumer', 'pharmacy', 'manufacturer'];
//role ear value get করা হচ্ছে URL থেকে। যদি role না থাকে বা unknown role থাকে, তাহলে $role empty string হবে।
$role = $_GET['role'] ?? '';
//এখানে check হচ্ছে $role allowed কিনা।
if (!in_array($role, $allowedRoles, true)) {
    $role = ''; // unknown/missing role - show a "pick a role" message instead of a form
}
//এটা registration error message নেওয়ার জন্য।
$errorMessage = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

// Per-role display text (title, subtitle, page <title>, left-panel name)
//just shit talks
$roleText = [
    'consumer' => [
        'title'    => 'Consumer Registration',
        'subtitle' => 'Create an account to verify and track your medicines.',
        'name'     => 'Consumer',
    ],
    'pharmacy' => [
        'title'    => 'Pharmacy Registration',
        'subtitle' => 'Register your pharmacy to receive and sell verified medicine.',
        'name'     => 'Pharmacy',
    ],
    'manufacturer' => [
        'title'    => 'Manufacturer Registration',
        'subtitle' => 'Register your company to produce and track verified medicine.',
        'name'     => 'Manufacturer',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $role ? htmlspecialchars($roleText[$role]['title']) : 'Register'; ?> - MediTrace</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <?php if ($role === ''): ?>

        <!-- ============================================================
             No (or invalid) role in the URL - point them back to login
             so they can pick a role and use the right register link.
             (Simple standalone card - no split island needed here since
             there's no specific role to preview on a left panel.)
        ============================================================ -->
        <div class="register-card">
            <h1 class="register-title">Register</h1>
            <p class="register-subtitle">
                Please choose a role on the sign-in page first, then use its
                "Register here" link.
            </p>
            <a href="index.php" class="signin-btn" style="display:block; text-align:center; text-decoration:none;">
                Back to Sign In
            </a>
        </div>
    <!--যদি $role empty না হয়, তাহলে আসল registration form দেখাও।-->
    <?php else: ?>

        <!-- ============================================================
             SPLIT GLASS ISLAND (same layout as index.php)
        ============================================================ -->
        <div class="login-island">

            <!-- ============================================================
                 LEFT PANEL: brand + tagline + this role's logo/name
                 (fixed, since $role is already known - no CSS switching
                 needed here like on the login page)
            ============================================================ -->
            <div class="island-left">

                <!-- Brand title - the "i" in "Meditrace" is a standing syringe -->
                <h1 class="brand-title">
                    Med<span class="syringe-i" aria-hidden="true">
                        <svg viewBox="0 0 100 40" width="1em" height="0.4em">
                            <rect x="2" y="15" width="6" height="10" fill="#E8846B"/>
                            <line x1="8" y1="20" x2="20" y2="20" stroke="#E8846B" stroke-width="3" stroke-linecap="round"/>
                            <rect x="20" y="12" width="35" height="16" rx="3" fill="none" stroke="#E8846B" stroke-width="3"/>
                            <line x1="30" y1="12" x2="30" y2="18" stroke="#E8846B" stroke-width="2"/>
                            <line x1="38" y1="12" x2="38" y2="18" stroke="#E8846B" stroke-width="2"/>
                            <line x1="46" y1="12" x2="46" y2="18" stroke="#E8846B" stroke-width="2"/>
                            <rect x="55" y="17" width="8" height="6" fill="#E8846B"/>
                            <line x1="63" y1="20" x2="92" y2="20" stroke="#E8846B" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </span>trace
                </h1>

                <!-- Cycling tagline: "Verify" -> "trace" -> "be safe", looping -->
                <div class="tagline-cycle">
                    <span class="tagline-word word-1">Verify</span>
                    <span class="tagline-word word-2">Trace</span>
                    <span class="tagline-word word-3">Be safe</span>
                </div>

                <!-- This role's logo, shown directly (no switching needed -
                     $role is fixed for this whole page) -->
                <div class="role-logo-display">
                    <?php if ($role === 'consumer'): ?>
                        <svg class="role-logo" style="display:block;" viewBox="0 0 48 48">
                            <circle cx="24" cy="16" r="8"/>
                            <path d="M8 42c0-8.8 7.2-16 16-16s16 7.2 16 16"/>
                        </svg>
                    <?php elseif ($role === 'pharmacy'): ?>
                        <svg class="role-logo" style="display:block;" viewBox="0 0 48 48">
                            <rect x="18" y="4" width="12" height="6" rx="1.5"/>
                            <rect x="20" y="9" width="8" height="5"/>
                            <rect x="10" y="13" width="28" height="31" rx="5"/>
                            <rect x="10" y="24" width="28" height="12" fill="#F7FBFD"/>
                            <rect x="21" y="27" width="6" height="6" rx="1"/>
                        </svg>
                    <?php elseif ($role === 'manufacturer'): ?>
                        <svg class="role-logo" style="display:block;" viewBox="0 0 48 48">
                            <path d="M6 44V22l10 7V22l10 7V16l16 10v18z"/>
                            <line x1="6" y1="44" x2="42" y2="44"/>
                            <line x1="34" y1="16" x2="34" y2="8"/>
                        </svg>
                    <?php endif; ?>
                </div>

                <!-- This role's name, shown directly -->
                <p class="selected-role-name">
                    <span style="display:inline;"><?php echo htmlspecialchars($roleText[$role]['name']); ?></span>
                </p>
            </div>

            <!-- ============================================================
                 MIDDLE DIVIDER
            ============================================================ -->
            <div class="island-divider"></div>

            <!-- ============================================================
                 User registration form submit করলে data যাবে register_handler.php-এ। সেখানে data validate করে DB-তে insert করা হবে।
            ============================================================ -->
            <form method="POST" action="register_handler.php" class="island-right">
                    <h2 class="signin-heading"><?php echo htmlspecialchars($roleText[$role]['title']); ?></h2>
                     <!-- এটাও role অনুযায়ী automatically change হয়।-->
                    <p class="register-subtitle"><?php echo htmlspecialchars($roleText[$role]['subtitle']); ?></p>

                    <?php if ($errorMessage !== ''): ?>
                        <div class="register-error"><?php echo $errorMessage; ?></div>
                    <?php endif; ?>

                 <!-- register_handler.php-কে জানতে হবে:এই user কোন ধরনের account তৈরি করতে চাচ্ছে?-->
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($role); ?>">

                    <?php if ($role === 'consumer'): ?>
                        <!-- ==================== CONSUMER FIELDS ==================== -->
                        <label class="field-label" for="name">Full Name</label>
                        <input type="text" id="name" name="name" placeholder="Enter your full name" required>

                        <label class="field-label" for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" placeholder="Enter your phone number" required>

                        <label class="field-label" for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Create a password" required>

                    <?php elseif ($role === 'pharmacy'): ?>
                        <!-- ==================== PHARMACY FIELDS ==================== -->
                        <label class="field-label" for="name">Pharmacy Name</label>
                        <input type="text" id="name" name="name" placeholder="Enter pharmacy name" required>

                        <label class="field-label" for="location">Location</label>
                        <input type="text" id="location" name="location" placeholder="Enter pharmacy address/location" required>

                        <label class="field-label" for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="Enter contact email" required>

                        <label class="field-label" for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" placeholder="Enter phone number" required>

                        <label class="field-label" for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Create a password" required>

                    <?php elseif ($role === 'manufacturer'): ?>
                        <!-- ==================== MANUFACTURER FIELDS ==================== -->
                        <label class="field-label" for="name">Company Name</label>
                        <input type="text" id="name" name="name" placeholder="Enter company name" required>

                        <label class="field-label" for="location">Location</label>
                        <input type="text" id="location" name="location" placeholder="Enter company location" required>

                        <label class="field-label" for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="Enter contact email" required>

                        <label class="field-label" for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Create a password" required>
                    <?php endif; ?>

                    <button type="submit" class="signin-btn">Register</button>

                    <!--User যদি আগে থেকেই account করে থাকে, তাহলে:-->
                    <p class="register-hint">
                        Already have an account? <a href="index.php" class="register-link" style="display:inline;">Sign in</a>
                    </p>
            </form>
        </div>

    <?php endif; ?>

</body>
</html>
