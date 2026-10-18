<?php
// Determine if we should show the Login or Register view on initial page load
$allowedRoles = ['consumer', 'pharmacy', 'manufacturer'];
$role = $_GET['role'] ?? '';
$isRegisterView = in_array($role, $allowedRoles, true);

// Fetch potential messages from the URL
$justRegistered = isset($_GET['registered']);
$loginError = isset($_GET['login_error']) ? htmlspecialchars($_GET['login_error']) : '';
$regError = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediTrace Authentication</title>
    
    <style>
        /* ================= Base + blurred bamboo background ================= */
        *, *::before, *::after { box-sizing: border-box; }
        html { background: #0a2a1c; }
        body {
            margin: 0; min-height: 100vh; padding: 28px 18px;
            display: grid; place-items: center;
            color: #f3f9ec;
            font-family: "Segoe UI", system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif;
            overflow-x: hidden;
        }
        body::before {
            content: ""; position: fixed; inset: -30px; z-index: -1;
            background:
                linear-gradient(rgba(4, 30, 18, .30), rgba(4, 30, 18, .45)),
                url("bambo_bush.png") center / cover no-repeat;
            filter: blur(9px);
        }

        /* ================= Green glass panel (Fixed & Stable) ================= */
        .glass {
            position: relative; width: min(1020px, 100%); min-height: 640px;
            border-radius: 36px;
            background: linear-gradient(135deg, rgba(120, 205, 140, .15), rgba(28, 104, 62, .18));
            -webkit-backdrop-filter: blur(20px) saturate(140%);
            backdrop-filter: blur(20px) saturate(140%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 34px 90px rgba(1, 20, 10, .55), inset 0 0 60px rgba(255, 255, 255, .05);
            overflow: hidden;
        }

        /* ================= Layout Sections (The Physical Slide) ================= */
        .form-section {
            position: absolute;
            top: 0; bottom: 0;
            left: 0; right: 35%; /* Takes up left 65% by default */
            transition: left 0.7s cubic-bezier(0.65, 0, 0.25, 1), right 0.7s cubic-bezier(0.65, 0, 0.25, 1);
        }
        .art-section {
            position: absolute;
            top: 0; bottom: 0;
            left: 65%; right: 0; /* Takes up right 35% by default */
            display: flex; align-items: center; justify-content: center;
            transition: left 0.7s cubic-bezier(0.65, 0, 0.25, 1), right 0.7s cubic-bezier(0.65, 0, 0.25, 1);
        }

        /* When switched to register mode, the coordinates flip */
        .glass.is-register .form-section { left: 35%; right: 0; }
        .glass.is-register .art-section { left: 0; right: 65%; }

        /* ================= View Panels (Forms Crossfade) ================= */
        .view-panel {
            position: absolute; inset: 0;
            padding: 32px 54px;
            display: flex; flex-direction: column; justify-content: center;
            opacity: 0; visibility: hidden; pointer-events: none;
            transition: opacity 0.4s ease, visibility 0.4s ease;
            overflow-y: auto;
        }
        .view-panel.active {
            opacity: 1; visibility: visible; pointer-events: auto; z-index: 2;
        }
        .view-panel > * { width: 100%; max-width: 420px; margin-inline: auto; }

        /* ================= Form Elements & Typography ================= */
        h1 {
            margin-block: 0 20px; text-align: left;
            font-family: "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif;
            font-weight: 600; font-size: 1.95rem; line-height: 1.15; color: #fff;
            text-shadow: 0 2px 20px rgba(0, 30, 15, .4);
        }
        .field { margin-bottom: 12px; }
        label { display: block; margin-bottom: 6px; font-size: .84rem; font-weight: 600; color: #e0f1cf; }
        input, select {
            width: 100%; height: 44px; padding: 0 14px; border-radius: 13px;
            font: inherit; font-size: .96rem; color: #fff; outline: none;
            background: rgba(255, 255, 255, .14); border: 1px solid rgba(255, 255, 255, .34);
            transition: background .25s, border-color .25s, box-shadow .25s;
        }
        input:focus, select:focus {
            background: rgba(255, 255, 255, .22); border-color: #dff3a0;
            box-shadow: 0 0 0 4px rgba(223, 243, 160, .22);
        }
        input:-webkit-autofill { -webkit-text-fill-color: #fff; transition: background-color 99999s; }
        select {
            appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 42px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' fill='none' stroke='%23e9fbc4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 1.5l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 16px center;
        }
        select option { color: #12321f; background: #eef6e6; }
        button {
            width: 100%; height: 48px; margin-top: 8px; border: 0; border-radius: 14px; cursor: pointer;
            font: inherit; font-size: 1rem; font-weight: 700; letter-spacing: .02em; color: #0d3a23;
            background: linear-gradient(135deg, #eaf9ae, #a6d96b);
            box-shadow: 0 12px 26px rgba(15, 60, 28, .4), inset 0 1px 0 rgba(255, 255, 255, .7);
            transition: transform .2s, filter .2s, box-shadow .2s;
        }
        button:hover { transform: translateY(-2px); filter: brightness(1.06); box-shadow: 0 16px 30px rgba(15, 60, 28, .5), inset 0 1px 0 rgba(255, 255, 255, .7); }
        button:active { transform: none; }
        button:focus-visible, a:focus-visible { outline: 3px solid #fff; outline-offset: 3px; }

        .error, .success { margin-bottom: 16px; padding: 11px 14px; border-radius: 13px; font-size: .9rem; font-weight: 600; border: 1px solid; }
        .error   { background: rgba(255, 110, 100, .2); border-color: rgba(255, 170, 160, .55); color: #ffe4df; }
        .success { background: rgba(150, 225, 120, .22); border-color: rgba(200, 255, 170, .55); color: #eaffd8; }

        .links { margin-top: 20px; font-size: .9rem; line-height: 1.9; text-align: center; color: rgba(240, 250, 230, .72); }
        .links strong { color: #fff; }
        .links a { color: #eaf9ae; font-weight: 600; text-decoration: none; border-bottom: 1px solid rgba(234, 249, 174, .4); cursor: pointer; }
        .links a:hover { border-bottom-color: #eaf9ae; }

        /* ================= Visuals: Divider and Leaf ================= */
        .divider {
            position: absolute;
            top: 44px; bottom: 44px; width: 4px;
            left: 0; transform: translateX(-50%); /* Attached to left edge of art section */
            background: linear-gradient(to bottom, transparent, #fff 18%, #fff 82%, transparent);
            clip-path: ellipse(50% 50% at 50% 50%);
            transition: left 0.7s cubic-bezier(0.65, 0, 0.25, 1);
        }
        .glass.is-register .divider {
            left: 100%; /* Moves to right edge of art section */
        }
        
        .leaf-side { position: relative; width: 100%; height: 100%; }
        .leaf-img {
            position: absolute; inset: 0; width: 100%; height: 100%;
            object-fit: cover; object-position: 52% 50%; pointer-events: none;
            transform-origin: 50% 88%;
            filter: drop-shadow(0 26px 16px rgba(0, 22, 10, .45));
            animation: leafFloat 6s ease-in-out infinite;
        }
        @keyframes leafFloat {
            0%, 100% { transform: translateY(0) rotate(-2.5deg); }
            50%      { transform: translateY(-11px) rotate(2.5deg); }
        }

        /* Freezes the leaf during the slide */
        .glass.is-traveling .leaf-img {
            animation-play-state: paused;
        }

        /* ================= Mobile Layout ================= */
        @media (max-width: 820px) {
            .glass {
                display: flex; flex-direction: column; min-height: 0; border-radius: 28px;
            }
            .form-section, .art-section {
                position: static; width: 100%; transition: none;
            }
            .art-section { order: 1; height: 160px; }
            .form-section { order: 2; height: auto; min-height: 500px; position: relative; }
            
            .leaf-side { width: 140px; height: 140px; margin: 18px auto 12px; }
            .leaf-img { object-fit: contain; }
            
            .divider {
                position: static; height: 2px; width: auto; margin: 0 28px; transform: none; 
                background: linear-gradient(to right, transparent, #fff 18%, #fff 82%, transparent);
            }
            .view-panel { padding: 24px 24px 32px; }
        }
    </style>
</head>
<body>

    <div class="glass <?php echo $isRegisterView ? 'is-register' : ''; ?>" id="main-glass">

        <!-- ==================== FORM SECTION ==================== -->
        <div class="form-section">
            
            <!-- 1. LOGIN VIEW -->
            <div id="view-login" class="view-panel <?php echo !$isRegisterView ? 'active' : ''; ?>">
                <h1>Sign In - MediTrace</h1>
                <?php if ($justRegistered): ?>
                    <div class="success">Account created! Please sign in.</div>
                <?php endif; ?>
                <?php if ($loginError !== ''): ?>
                    <div class="error"><?php echo $loginError; ?></div>
                <?php endif; ?>

                <form method="POST" action="login_handler.php">
                    <div class="field">
                        <label for="login_id">Email or Phone</label>
                        <input type="text" id="login_id" name="login_id" required>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="field">
                        <label for="roleSelect">Role</label>
                        <select id="roleSelect" name="role" required>
                            <option value="" disabled selected>Select your role</option>
                            <option value="consumer">Consumer</option>
                            <option value="pharmacy">Pharmacy</option>
                            <option value="manufacturer">Manufacturer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit">Sign In</button>
                </form>

                <div class="links">
                    <strong>Create an Account:</strong><br>
                    <a href="?role=consumer" data-target="view-reg-consumer" data-title="Consumer Registration - MediTrace">New Consumer</a> | 
                    <a href="?role=pharmacy" data-target="view-reg-pharmacy" data-title="Pharmacy Registration - MediTrace">New Pharmacy</a> | 
                    <a href="?role=manufacturer" data-target="view-reg-manufacturer" data-title="Manufacturer Registration - MediTrace">New Manufacturer</a>
                </div>
            </div>

            <!-- 2. CONSUMER REGISTRATION VIEW -->
            <div id="view-reg-consumer" class="view-panel <?php echo ($role === 'consumer') ? 'active' : ''; ?>">
                <h1>Consumer Registration</h1>
                <?php if ($role === 'consumer' && $regError !== ''): ?>
                    <div class="error"><?php echo $regError; ?></div>
                <?php endif; ?>

                <form method="POST" action="register_handler.php">
                    <input type="hidden" name="role" value="consumer">
                    <div class="field">
                        <label for="c_name">Full Name</label>
                        <input type="text" id="c_name" name="name" required>
                    </div>
                    <div class="field">
                        <label for="c_phone">Phone Number</label>
                        <input type="text" id="c_phone" name="phone" required>
                    </div>
                    <div class="field">
                        <label for="c_password">Password</label>
                        <input type="password" id="c_password" name="password" required>
                    </div>
                    <button type="submit">Register</button>
                </form>

                <div class="links">
                    Already have an account? <a href="?" data-target="view-login" data-title="MediTrace Authentication">Sign in here</a>
                </div>
            </div>

            <!-- 3. PHARMACY REGISTRATION VIEW -->
            <div id="view-reg-pharmacy" class="view-panel <?php echo ($role === 'pharmacy') ? 'active' : ''; ?>">
                <h1>Pharmacy Registration</h1>
                <?php if ($role === 'pharmacy' && $regError !== ''): ?>
                    <div class="error"><?php echo $regError; ?></div>
                <?php endif; ?>

                <form method="POST" action="register_handler.php">
                    <input type="hidden" name="role" value="pharmacy">
                    <div class="field">
                        <label for="p_name">Pharmacy Name</label>
                        <input type="text" id="p_name" name="name" required>
                    </div>
                    <div class="field">
                        <label for="p_location">Location</label>
                        <input type="text" id="p_location" name="location" required>
                    </div>
                    <div class="field">
                        <label for="p_email">Email</label>
                        <input type="email" id="p_email" name="email" required>
                    </div>
                    <div class="field">
                        <label for="p_phone">Phone Number</label>
                        <input type="text" id="p_phone" name="phone" required>
                    </div>
                    <div class="field">
                        <label for="p_password">Password</label>
                        <input type="password" id="p_password" name="password" required>
                    </div>
                    <button type="submit">Register</button>
                </form>

                <div class="links">
                    Already have an account? <a href="?" data-target="view-login" data-title="MediTrace Authentication">Sign in here</a>
                </div>
            </div>

            <!-- 4. MANUFACTURER REGISTRATION VIEW -->
            <div id="view-reg-manufacturer" class="view-panel <?php echo ($role === 'manufacturer') ? 'active' : ''; ?>">
                <h1>Manufacturer Registration</h1>
                <?php if ($role === 'manufacturer' && $regError !== ''): ?>
                    <div class="error"><?php echo $regError; ?></div>
                <?php endif; ?>

                <form method="POST" action="register_handler.php">
                    <input type="hidden" name="role" value="manufacturer">
                    <div class="field">
                        <label for="m_name">Company Name</label>
                        <input type="text" id="m_name" name="name" required>
                    </div>
                    <div class="field">
                        <label for="m_location">Location</label>
                        <input type="text" id="m_location" name="location" required>
                    </div>
                    <div class="field">
                        <label for="m_email">Email</label>
                        <input type="email" id="m_email" name="email" required>
                    </div>
                    <div class="field">
                        <label for="m_password">Password</label>
                        <input type="password" id="m_password" name="password" required>
                    </div>
                    <button type="submit">Register</button>
                </form>

                <div class="links">
                    Already have an account? <a href="?" data-target="view-login" data-title="MediTrace Authentication">Sign in here</a>
                </div>
            </div>

        </div>

        <!-- ==================== ART SECTION ==================== -->
        <div class="art-section">
            <div class="divider" aria-hidden="true"></div>
            <div class="leaf-side" aria-hidden="true">
                <img class="leaf-img" src="leaf_no_background.png" alt="">
            </div>
        </div>

    </div>

    <script>
        // DOM Elements
        const glass = document.getElementById('main-glass');
        const panels = document.querySelectorAll('.view-panel');
        let travelTimer;

        document.addEventListener('click', function(e) {
            // Intercept clicks on links that have a data-target attribute
            const link = e.target.closest('a[data-target]');
            
            if (link) {
                e.preventDefault(); 
                const targetId = link.getAttribute('data-target');
                const targetUrl = link.getAttribute('href');
                const targetTitle = link.getAttribute('data-title');

                // 1. Crossfade the forms
                panels.forEach(panel => panel.classList.remove('active'));
                document.getElementById(targetId).classList.add('active');

                // 2. Trigger the physical slide & pause the leaf
                const currentlyRegistering = glass.classList.contains('is-register');
                const goingToRegister = (targetId !== 'view-login');

                // Only animate the slider and pause leaf if we are switching SIDES
                if (currentlyRegistering !== goingToRegister) {
                    glass.classList.toggle('is-register', goingToRegister);
                    
                    // Pause leaf for exactly 700ms (matches CSS cubic-bezier duration)
                    glass.classList.add('is-traveling');
                    clearTimeout(travelTimer);
                    travelTimer = setTimeout(() => {
                        glass.classList.remove('is-traveling');
                    }, 700);
                }

                // 3. Update Browser URL and Title (makes browser back button work naturally)
                if (targetTitle) document.title = targetTitle;
                history.pushState({}, '', targetUrl);
            }
        });
    </script>
</body>
</html>