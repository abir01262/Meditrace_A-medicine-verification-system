<?php
session_start();

// Verify user is logged in as consumer
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'consumer' || !isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'meditrace';
$username = 'root'; 
$db_password = '';  

$conn = mysqli_connect($host, $username, $db_password, $dbname);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$consumer_id = $_SESSION['user_id'];
$consumer_name = "Consumer";
$scan_message = "";
$popup_script = "";

// 1. Fetch the consumer's name
$name_stmt = mysqli_prepare($conn, "SELECT name FROM Consumer WHERE consumer_id = ?");
mysqli_stmt_bind_param($name_stmt, "i", $consumer_id);
mysqli_stmt_execute($name_stmt);
mysqli_stmt_bind_result($name_stmt, $fetched_name);
if (mysqli_stmt_fetch($name_stmt)) {
    $consumer_name = $fetched_name;
}
mysqli_stmt_close($name_stmt);

// 2. Fetch all registered pharmacies for the dropdown
$pharmacies = [];
$pharm_result = mysqli_query($conn, "SELECT pharmacy_id, name, location FROM Pharmacy ORDER BY name ASC");
if ($pharm_result) {
    while ($row = mysqli_fetch_assoc($pharm_result)) {
        $pharmacies[] = $row;
    }
}

// --- PROCESS: VERIFY MEDICINE CODE ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verify_code'])) {
    $scanned_code = trim($_POST['scanned_code'] ?? '');
    $pharmacy_id = (int)($_POST['pharmacy_id'] ?? 0);

    if ($scanned_code !== '' && $pharmacy_id > 0) {
        // Check if code exists and fetch medicine details
        $check_query = "SELECT p.package_id, m.medicine_name, m.generic_name, m.mrp, m.expiry_date, m.is_recalled 
                        FROM Package p 
                        JOIN Medicine m ON p.medicine_id = m.medicine_id 
                        WHERE p.medicine_code = ?";
        
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "s", $scanned_code);
        mysqli_stmt_execute($check_stmt);
        $result = mysqli_stmt_get_result($check_stmt);
        
        $medicine_data = mysqli_fetch_assoc($result);
        mysqli_stmt_close($check_stmt);

        $status = 'Fake';
        $is_authentic = false;
        
        if ($medicine_data) {
    $is_authentic = true;
    $current_date = date('Y-m-d');
    if ($medicine_data['is_recalled'] == 1) {
        $status = 'Recalled';
    } elseif ($medicine_data['expiry_date'] < $current_date) {
        $status = 'Expired';
    } else {
        $status = 'Authentic';
    }

}

        // Log the scan into Medicine_Scan with the selected pharmacy_id
        $scan_stmt = mysqli_prepare($conn, "INSERT INTO Medicine_Scan (consumer_id, pharmacy_id, medicine_code, scan_date, scan_status) VALUES (?, ?, ?, NOW(), ?)");
        mysqli_stmt_bind_param($scan_stmt, "iiss", $consumer_id, $pharmacy_id, $scanned_code, $status);
        $insert_success = mysqli_stmt_execute($scan_stmt);
        
        if ($insert_success) {
            $new_scan_id = mysqli_insert_id($conn);
            
            // Update Scan_Verification 
            $total = 1;
            $success_amt = ($status === 'Authentic' || $status === 'Expired') ? 1 : 0;
            $failed_amt = ($status === 'Fake') ? 1 : 0;
            
            $verify_stmt = mysqli_prepare($conn, "INSERT INTO Scan_Verification (scan_id, total_scan_amount, successful_scan_amount, failed_scan_amount) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($verify_stmt, "iiii", $new_scan_id, $total, $success_amt, $failed_amt);
            mysqli_stmt_execute($verify_stmt);
            mysqli_stmt_close($verify_stmt);
        }
        mysqli_stmt_close($scan_stmt);

        // Prepare Detailed UI feedback
        if ($status === 'Authentic') {
            $scan_message = "
                <div class='alert success'>
                    <h3 style='margin-top:0; color: #1b5e20;'>✅ Authentic Medicine Verified</h3>
                    <p><strong>Brand:</strong> " . htmlspecialchars($medicine_data['medicine_name']) . "<br>
                    <strong>Generic:</strong> " . htmlspecialchars($medicine_data['generic_name']) . "<br>
                    <strong>Maximum Retail Price (MRP):</strong> ৳" . number_format($medicine_data['mrp'], 2) . "<br>
                    <strong>Expiry Date:</strong> " . htmlspecialchars($medicine_data['expiry_date']) . "</p>
                    <hr style='border: 1px solid #a5d6a7;'>
                    <small>Ensure the retail price you paid does not exceed the MRP shown above.</small>
                </div>";
        } elseif ($status === 'Expired') {
            $scan_message = "
                <div class='alert warning'>
                    <h3 style='margin-top:0; color: #e65100;'>⚠️ DO NOT CONSUME - EXPIRED</h3>
                    <p>This product is a genuine Meditrace registered item, but it has passed its expiration date.</p>
                    <p><strong>Brand:</strong> " . htmlspecialchars($medicine_data['medicine_name']) . "<br>
                    <strong>Expired On:</strong> <span style='color: red; font-weight: bold;'>" . htmlspecialchars($medicine_data['expiry_date']) . "</span></p>
                    <hr style='border: 1px solid #ffcc80;'>
                    <small>Please return this immediately to the pharmacy.</small>
                </div>";
        } elseif ($status === 'Recalled') {
    $scan_message = "
        <div class='alert error'>
            <h3 style='margin-top:0; color: #b71c1c;'>🚨 CRITICAL: RECALLED BATCH</h3>
            <p>This product belongs to a batch that has been officially recalled by the manufacturer.</p>
            <hr style='border: 1px solid #ef9a9a;'>
            <small>DO NOT CONSUME. Return to the pharmacy immediately.</small>
        </div>";}
        
        else {
            $scan_message = "
                <div class='alert error'>
                    <h3 style='margin-top:0; color: #b71c1c;'>❌ FAKE WARNING</h3>
                    <p>The code <strong>" . htmlspecialchars($scanned_code) . "</strong> does not exist in our database.</p>
                    <hr style='border: 1px solid #ef9a9a;'>
                    <small>This product is counterfeit. Do not consume it and report the pharmacy immediately.</small>
                </div>";
        }
    }
}

// --- FETCH SCAN HISTORY ---
$scan_history = [];
// Join with Package and Medicine to get the real medicine name if it was authentic
$hist_query = "SELECT ms.medicine_code, ms.scan_date, ms.scan_status, m.medicine_name 
               FROM Medicine_Scan ms
               LEFT JOIN Package p ON ms.medicine_code = p.medicine_code
               LEFT JOIN Medicine m ON p.medicine_id = m.medicine_id
               WHERE ms.consumer_id = ? 
               ORDER BY ms.scan_date DESC";
$hist_stmt = mysqli_prepare($conn, $hist_query);
mysqli_stmt_bind_param($hist_stmt, "i", $consumer_id);
mysqli_stmt_execute($hist_stmt);
$result = mysqli_stmt_get_result($hist_stmt);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $scan_history[] = $row;
    }
}
mysqli_stmt_close($hist_stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Consumer Dashboard</title>
    <style>
        /* ================= Base + blurred bamboo background ================= */
        *, *::before, *::after { box-sizing: border-box; }
        html { background: #0a2a1c; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0; min-height: 100vh; padding: 40px 20px;
            color: #f3f9ec;
        }
        body::before {
            content: ""; position: fixed; inset: -30px; z-index: -1;
            background:
                linear-gradient(rgba(4, 30, 18, .30), rgba(4, 30, 18, .45)),
                url("bambo_bush.png") center / cover no-repeat;
            filter: blur(9px);
        }

        .dashboard-container { max-width: 860px; margin: 0 auto; }

        /* ================= Glass Cards ================= */
        .card {
            background: linear-gradient(135deg, rgba(120, 205, 140, .15), rgba(28, 104, 62, .18));
            -webkit-backdrop-filter: blur(20px) saturate(140%);
            backdrop-filter: blur(20px) saturate(140%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 34px 90px rgba(1, 20, 10, .55), inset 0 0 60px rgba(255, 255, 255, .05);
            border-radius: 24px;
            padding: 35px 40px;
            margin-bottom: 30px;
            text-align: center;
        }
        .card.history-card { text-align: left; }

        /* ================= Typography & Headers ================= */
        .header { 
            display: flex; justify-content: space-between; align-items: center; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.2); 
            padding-bottom: 20px; margin-bottom: 20px; text-align: left;
        }
        .header h1 { 
            margin: 0; color: #fff; font-size: 26px; 
            font-family: "Iowan Old Style", "Palatino Linotype", serif; 
            text-shadow: 0 2px 10px rgba(0, 30, 15, .4);
        }
        h2 { 
            color: #eaf9ae; font-size: 22px; margin-top: 0; margin-bottom: 15px; 
            font-family: "Iowan Old Style", "Palatino Linotype", serif; 
        }
        p { color: #e0f1cf; line-height: 1.6; }

        /* ================= Buttons ================= */
        .logout-btn { 
            background: rgba(255, 83, 80, 0.2); border: 1px solid rgba(255, 83, 80, 0.4);
            color: #ffebee; padding: 10px 18px; text-decoration: none; border-radius: 12px; 
            font-weight: 600; font-size: 14px; transition: 0.3s;
        }
        .logout-btn:hover { background: rgba(255, 83, 80, 0.4); box-shadow: 0 4px 12px rgba(255,83,80,0.2); }
        
        .btn-submit { 
            background: linear-gradient(135deg, #eaf9ae, #a6d96b); 
            color: #0d3a23; padding: 15px 30px; border: none; border-radius: 14px; 
            cursor: pointer; font-size: 17px; font-weight: 700; transition: 0.3s; 
            width: 100%; max-width: 450px; margin-top: 10px;
            box-shadow: 0 12px 26px rgba(15, 60, 28, .4), inset 0 1px 0 rgba(255, 255, 255, .7);
        }
        .btn-submit:hover { 
            transform: translateY(-2px); filter: brightness(1.06); 
            box-shadow: 0 16px 30px rgba(15, 60, 28, .5), inset 0 1px 0 rgba(255, 255, 255, .7); 
        }

        /* ================= Form Fields ================= */
        .form-group { margin-bottom: 20px; }
        .form-group input, .form-group select { 
            width: 100%; max-width: 450px; height: 50px; padding: 0 20px; box-sizing: border-box; 
            border-radius: 14px; background: rgba(255, 255, 255, .14); 
            border: 1px solid rgba(255, 255, 255, .34); color: #fff;
            font-size: 16px; margin-bottom: 15px; outline: none;
            transition: background .25s, border-color .25s, box-shadow .25s;
        }
        .form-group input { text-align: center; letter-spacing: 2px; font-weight: 600; font-size: 18px; }
        .form-group input::placeholder { color: rgba(255,255,255,0.6); font-weight: normal; letter-spacing: normal; }
        
        .form-group input:focus, .form-group select:focus { 
            background: rgba(255, 255, 255, .22); border-color: #dff3a0; 
            box-shadow: 0 0 0 4px rgba(223, 243, 160, .22); 
        }
        
        .form-group select {
            appearance: none; cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' fill='none' stroke='%23e9fbc4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 1.5l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 20px center;
        }
        .form-group select option { color: #12321f; background: #eef6e6; }

        /* ================= Alerts (Overriding PHP inline styles securely) ================= */
        .alert { 
            padding: 20px 25px; border-radius: 16px; margin-bottom: 25px; 
            font-size: 15px; text-align: left; line-height: 1.6; 
            backdrop-filter: blur(10px);
        }
        .success { background: rgba(76, 175, 80, 0.15); border: 1px solid rgba(76, 175, 80, 0.4); color: #dcedc8; }
        .warning { background: rgba(255, 152, 0, 0.15); border: 1px solid rgba(255, 152, 0, 0.4); color: #ffe0b2; }
        .error   { background: rgba(244, 67, 54, 0.15); border: 1px solid rgba(244, 67, 54, 0.4); color: #ffcdd2; }
        
        /* Force override the inline colors generated by PHP so they match the dark glass */
        .alert h3 { color: #fff !important; font-family: "Iowan Old Style", serif; font-size: 20px; }
        .alert hr { border: 0 !important; border-top: 1px solid rgba(255, 255, 255, 0.2) !important; margin: 15px 0; }
        .alert strong { color: #fff; }
        .alert span[style] { color: #ff8a80 !important; } /* Overrides the red text in expired */

        /* ================= Table (No Side Borders) ================= */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 15px; }
        th, td { 
            padding: 16px 12px; text-align: left; 
            border: none; /* Strip all default borders */
        }
        th { 
            color: #eaf9ae; font-weight: 600; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.3); /* Only white inner horizontal bars */
        }
        td { 
            border-bottom: 1px solid rgba(255, 255, 255, 0.15); /* Only white inner horizontal bars */
            color: #f3f9ec;
        }
        tr:last-child td { border-bottom: none; } /* Remove border from very bottom row */
        tr:hover td { background-color: rgba(255, 255, 255, 0.06); }
        
        /* Table inline style overrides */
        td[style*="color: #546e7a;"] { color: rgba(255, 255, 255, 0.6) !important; }
        td span[style*="color:#9e9e9e;"] { color: #ffcdd2 !important; }

        /* ================= Badges ================= */
        .badge { 
            padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; 
            color: white; display: inline-block; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .badge-auth { background: rgba(76, 175, 80, 0.3); border: 1px solid rgba(76, 175, 80, 0.6); color: #e8f5e9; }
        .badge-fake { background: rgba(244, 67, 54, 0.3); border: 1px solid rgba(244, 67, 54, 0.6); color: #ffebee; }
        .badge-expired { background: rgba(255, 152, 0, 0.3); border: 1px solid rgba(255, 152, 0, 0.6); color: #fff3e0; }
        .badge-recalled { background-color: rgba(194, 13, 0, 0.86); border: 1px solid rgba(244, 67, 54, 0.6); color: #ffebee; }
    </style>
</head>
<body>

<div class="dashboard-container">
    
    <div class="card history-card">
        <div class="header">
            <h1>Welcome, <?php echo htmlspecialchars($consumer_name); ?>!</h1>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
        <?php echo $scan_message; ?>
    </div>

    <!-- Section: Verify Medicine -->
    <div class="card">
        <h2>Verify Medicine Authenticity</h2>
        <p style="margin-bottom: 25px;">Enter the code from your packaging and select the pharmacy where you purchased it.</p>
        <form method="POST" action="">
            <div class="form-group">
                <!-- Dropdown populated dynamically -->
                <select name="pharmacy_id" required>
                    <option value="" disabled selected>-- Select Pharmacy Location --</option>
                    <?php foreach ($pharmacies as $pharm): ?>
                        <option value="<?php echo $pharm['pharmacy_id']; ?>">
                            <?php echo htmlspecialchars($pharm['name'] . " - " . $pharm['location']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <input type="text" name="scanned_code" placeholder="e.g. 88120022" required autocomplete="off">
            </div>
            <button type="submit" name="verify_code" class="btn-submit">Verify Authenticity</button>
        </form>
    </div>

    <!-- Section: Scan History -->
    <div class="card history-card">
        <h2>Your Verification History</h2>
        <?php if (count($scan_history) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Product Details</th>
                        <th>Scanned Code</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scan_history as $scan): ?>
                        <tr>
                            <td style="font-size: 13px; color: #546e7a;"><?php echo date('M d, Y g:i A', strtotime($scan['scan_date'])); ?></td>
                            <td>
                                <?php echo $scan['medicine_name'] ? htmlspecialchars($scan['medicine_name']) : '<span style="color:#9e9e9e; font-style:italic;">Unknown (Counterfeit)</span>'; ?>
                            </td>
                            <td style="font-family: monospace; font-size: 15px;"><?php echo htmlspecialchars($scan['medicine_code']); ?></td>
                            <td>
    <?php if ($scan['scan_status'] === 'Authentic'): ?>
        <span class="badge badge-auth">Authentic</span>
    <?php elseif ($scan['scan_status'] === 'Expired'): ?>
        <span class="badge badge-expired">Expired</span>
    <?php elseif ($scan['scan_status'] === 'Recalled'): ?>
        <span class="badge badge-recalled">Recalled</span>
    <?php else: ?>
        <span class="badge badge-fake">Fake</span>
    <?php endif; ?>
</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; padding: 20px; opacity: 0.8;">You have not verified any medicines yet.</p>
        <?php endif; ?>
    </div>

</div>

</body>
</html>