<?php
session_start();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'pharmacy' || !isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$host = 'localhost';
$dbname = 'meditrace';
$username = 'root'; 
$db_password = '';  

$conn = mysqli_connect($host, $username, $db_password, $dbname);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$pharmacy_id = $_SESSION['user_id'];
$pharmacy_name = "Pharmacy";
$status_message = "";

// Fetch Pharmacy Name
$name_stmt = mysqli_prepare($conn, "SELECT name FROM Pharmacy WHERE pharmacy_id = ?");
mysqli_stmt_bind_param($name_stmt, "i", $pharmacy_id);
mysqli_stmt_execute($name_stmt);
mysqli_stmt_bind_result($name_stmt, $fetched_name);
if (mysqli_stmt_fetch($name_stmt)) {
    $pharmacy_name = $fetched_name;
}
mysqli_stmt_close($name_stmt);

// --- PROCESS: ORDER FROM MANUFACTURER (Smart Aggregation by Batch) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['place_order'])) {
    $selected_medicine_id = intval($_POST['medicine_id'] ?? 0);
    $order_quantity = intval($_POST['order_quantity'] ?? 0);

    if ($selected_medicine_id > 0 && $order_quantity > 0) {
        $check_stmt = mysqli_prepare($conn, "SELECT medicine_name, batch_id, quantity FROM Medicine WHERE medicine_id = ? AND quantity >= ? AND is_recalled = 0");
        mysqli_stmt_bind_param($check_stmt, "ii", $selected_medicine_id, $order_quantity);
        mysqli_stmt_execute($check_stmt);
        $result = mysqli_stmt_get_result($check_stmt);
        
        if ($med_data = mysqli_fetch_assoc($result)) {
            $update_mfr = mysqli_prepare($conn, "UPDATE Medicine SET quantity = quantity - ? WHERE medicine_id = ?");
            mysqli_stmt_bind_param($update_mfr, "ii", $order_quantity, $selected_medicine_id);
            mysqli_stmt_execute($update_mfr);
            
            $check_pkg = mysqli_prepare($conn, "SELECT package_id, medicine_code FROM Package WHERE pharmacy_id = ? AND medicine_id = ?");
            mysqli_stmt_bind_param($check_pkg, "ii", $pharmacy_id, $selected_medicine_id);
            mysqli_stmt_execute($check_pkg);
            $pkg_res = mysqli_stmt_get_result($check_pkg);
            
            if ($existing_pkg = mysqli_fetch_assoc($pkg_res)) {
                $update_pkg = mysqli_prepare($conn, "UPDATE Package SET package_amount = package_amount + ?, current_stock = current_stock + ? WHERE package_id = ?");
                mysqli_stmt_bind_param($update_pkg, "iii", $order_quantity, $order_quantity, $existing_pkg['package_id']);
                mysqli_stmt_execute($update_pkg);
                $tracking_code = $existing_pkg['medicine_code'];
                $status_message = "<div class='alert success'><strong>RESTOCK SUCCESS:</strong> Added $order_quantity units of " . htmlspecialchars($med_data['medicine_name']) . " (Batch: " . htmlspecialchars($med_data['batch_id']) . ") to existing inventory.</div>";
            } else {
                $temp_code = 'TEMP-' . uniqid();
                $insert_pkg = mysqli_prepare($conn, "INSERT INTO Package (medicine_code, medicine_id, pharmacy_id, package_amount, current_stock, send_date) VALUES (?, ?, ?, ?, ?, CURDATE())");
                mysqli_stmt_bind_param($insert_pkg, "siiii", $temp_code, $selected_medicine_id, $pharmacy_id, $order_quantity, $order_quantity);
                mysqli_stmt_execute($insert_pkg);
                
                $new_package_id = mysqli_insert_id($conn);
                $tracking_code = $new_package_id . $selected_medicine_id . $pharmacy_id . date('d');
                
                $update_code = mysqli_prepare($conn, "UPDATE Package SET medicine_code = ? WHERE package_id = ?");
                mysqli_stmt_bind_param($update_code, "si", $tracking_code, $new_package_id);
                mysqli_stmt_execute($update_code);
                $status_message = "<div class='alert success'><strong>NEW ORDER APPROVED:</strong> Ordered $order_quantity units of " . htmlspecialchars($med_data['medicine_name']) . " (Batch: " . htmlspecialchars($med_data['batch_id']) . "). Barcode: <strong>$tracking_code</strong></div>";
            }
        } else {
            $status_message = "<div class='alert error'><strong>ORDER FAILED:</strong> Manufacturer does not have enough stock, or batch is recalled.</div>";
        }
    }
}

// --- PROCESS: SELL MEDICINE (POS) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['sell_medicine'])) {
    $sell_code = trim($_POST['sell_medicine_code'] ?? '');
    $sell_quantity = intval($_POST['sell_quantity'] ?? 0);

    if ($sell_code !== '' && $sell_quantity > 0) {
        $check_inv = mysqli_prepare($conn, "SELECT p.current_stock, p.medicine_id, m.retail_price, m.expiry_date, m.medicine_name, m.is_recalled FROM Package p JOIN Medicine m ON p.medicine_id = m.medicine_id WHERE p.medicine_code = ? AND p.pharmacy_id = ?");
        mysqli_stmt_bind_param($check_inv, "si", $sell_code, $pharmacy_id);
        mysqli_stmt_execute($check_inv);
        $res = mysqli_stmt_get_result($check_inv);
        
        if ($med_data = mysqli_fetch_assoc($res)) {
            if ($med_data['is_recalled'] == 1) {
                $status_message = "<div class='alert error'><strong>SALE BLOCKED:</strong> This batch has been recalled by the manufacturer!</div>";
            } elseif ($med_data['expiry_date'] < date('Y-m-d')) {
                $status_message = "<div class='alert error'><strong>SALE BLOCKED:</strong> Cannot sell expired medicine!</div>";
            } elseif ($sell_quantity <= $med_data['current_stock']) {
                $total_price = $med_data['retail_price'] * $sell_quantity;
                
                $sale_stmt = mysqli_prepare($conn, "INSERT INTO Medicine_Sale (pharmacy_id, medicine_id, quantity_sold, total_price_amount, sale_date) VALUES (?, ?, ?, ?, NOW())");
                mysqli_stmt_bind_param($sale_stmt, "iiid", $pharmacy_id, $med_data['medicine_id'], $sell_quantity, $total_price);
                mysqli_stmt_execute($sale_stmt);
                
                $update_pkg = mysqli_prepare($conn, "UPDATE Package SET current_stock = current_stock - ? WHERE medicine_code = ?");
                mysqli_stmt_bind_param($update_pkg, "is", $sell_quantity, $sell_code);
                mysqli_stmt_execute($update_pkg);
                
                $status_message = "<div class='alert success'><strong>SALE SUCCESSFUL:</strong> Sold $sell_quantity units of " . htmlspecialchars($med_data['medicine_name']) . " for ৳$total_price.</div>";
            } else {
                $status_message = "<div class='alert error'><strong>SALE FAILED:</strong> Not enough stock in this package.</div>";
            }
        }
    }
}

// --- DATA FETCHING FOR UI ---
$available_inventory = [];
$recalled_inventory = [];

// 1. Active Inventory (Explicitly Hides Recalled Batches)
$inv_query = mysqli_query($conn, "SELECT p.medicine_code, p.current_stock, m.medicine_name, m.batch_id, m.expiry_date FROM Package p JOIN Medicine m ON p.medicine_id = m.medicine_id WHERE p.pharmacy_id = $pharmacy_id AND p.current_stock > 0 AND m.is_recalled = 0 ORDER BY m.expiry_date ASC");
while ($row = mysqli_fetch_assoc($inv_query)) { 
    $available_inventory[$row['medicine_code']] = $row; 
}

// 2. Quarantined / Recalled Inventory
$recall_query = mysqli_query($conn, "SELECT p.medicine_code, p.current_stock, m.medicine_name, m.batch_id FROM Package p JOIN Medicine m ON p.medicine_id = m.medicine_id WHERE p.pharmacy_id = $pharmacy_id AND p.current_stock > 0 AND m.is_recalled = 1");
while ($row = mysqli_fetch_assoc($recall_query)) { 
    $recalled_inventory[$row['medicine_code']] = $row; 
}
$low_stock_items = [];
// Ignores recalled items so they aren't prompted for restock
$low_query = mysqli_query($conn, "
    SELECT m.medicine_name, SUM(p.current_stock) as total_stock 
    FROM Package p 
    JOIN Medicine m ON p.medicine_id = m.medicine_id 
    WHERE p.pharmacy_id = $pharmacy_id AND m.is_recalled = 0
    GROUP BY m.medicine_name 
    HAVING total_stock < 10 
    ORDER BY total_stock ASC
");
while ($row = mysqli_fetch_assoc($low_query)) { $low_stock_items[] = $row; }

$medicines_list = [];
$med_query = mysqli_query($conn, "SELECT medicine_id, medicine_name, batch_id, quantity, retail_price FROM Medicine WHERE quantity > 0 AND is_recalled = 0 ORDER BY medicine_name ASC");
while ($row = mysqli_fetch_assoc($med_query)) { $medicines_list[] = $row; }

// --- 6-MONTH CHART DATA PREPARATION ---
$months = [];
$revenue_data = [];
$volume_data = [];
$top_product_data = [];

for ($i = 5; $i >= 0; $i--) {
    $month_label = date('M Y', strtotime("-$i months"));
    $months[$month_label] = ['rev' => 0, 'vol' => 0, 'top' => 0];
}

$chart_query = mysqli_query($conn, "
    SELECT DATE_FORMAT(sale_date, '%b %Y') as m_label, SUM(total_price_amount) as monthly_rev, SUM(quantity_sold) as monthly_vol 
    FROM Medicine_Sale WHERE pharmacy_id = $pharmacy_id AND sale_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 5 MONTH) 
    GROUP BY m_label
");
while ($row = mysqli_fetch_assoc($chart_query)) {
    if (isset($months[$row['m_label']])) {
        $months[$row['m_label']]['rev'] = $row['monthly_rev'];
        $months[$row['m_label']]['vol'] = $row['monthly_vol'];
    }
}

$top_seller_name = "No Sales Yet";
$top_id = 0;
$top_query = mysqli_query($conn, "SELECT m.medicine_id, m.medicine_name FROM Medicine_Sale ms JOIN Medicine m ON ms.medicine_id = m.medicine_id WHERE ms.pharmacy_id = $pharmacy_id GROUP BY m.medicine_id ORDER BY SUM(ms.quantity_sold) DESC LIMIT 1");
if ($top_row = mysqli_fetch_assoc($top_query)) {
    $top_id = $top_row['medicine_id'];
    $top_seller_name = $top_row['medicine_name'];
    
    $top_trend_query = mysqli_query($conn, "
        SELECT DATE_FORMAT(sale_date, '%b %Y') as m_label, SUM(quantity_sold) as monthly_vol 
        FROM Medicine_Sale WHERE pharmacy_id = $pharmacy_id AND medicine_id = $top_id AND sale_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 5 MONTH) 
        GROUP BY m_label
    ");
    while ($row = mysqli_fetch_assoc($top_trend_query)) {
        if (isset($months[$row['m_label']])) {
            $months[$row['m_label']]['top'] = $row['monthly_vol'];
        }
    }
}

$js_labels = json_encode(array_keys($months));
$js_rev = json_encode(array_column($months, 'rev'));
$js_vol = json_encode(array_column($months, 'vol'));
$js_top = json_encode(array_column($months, 'top'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pharmacy Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ================= Base + blurred bamboo background ================= */
        *, *::before, *::after { box-sizing: border-box; }
        html { background: #0a2a1c; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0; min-height: 100vh; padding: 40px 20px;
            color: #f3f9ec; overflow-x: hidden;
        }
        body::before {
            content: ""; position: fixed; inset: -30px; z-index: -1;
            background:
                linear-gradient(rgba(4, 30, 18, .30), rgba(4, 30, 18, .45)),
                url("bambo_bush.png") center / cover no-repeat;
            filter: blur(9px);
        }

        .dashboard-container { max-width: 1300px; margin: 0 auto; }

        /* ================= 12-Column CSS Grid System ================= */
        .grid-layout {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 25px;
            margin-bottom: 25px;
        }
        .col-4 { grid-column: span 4; }
        .col-5 { grid-column: span 5; }
        .col-7 { grid-column: span 7; }
        .col-8 { grid-column: span 8; }
        .col-12 { grid-column: span 12; }

        /* ================= Glass Panels ================= */
        .glass-panel {
            background: linear-gradient(135deg, rgba(120, 205, 140, .12), rgba(28, 104, 62, .15));
            -webkit-backdrop-filter: blur(20px) saturate(140%);
            backdrop-filter: blur(20px) saturate(140%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 24px 60px rgba(1, 20, 10, .45), inset 0 0 60px rgba(255, 255, 255, .05);
            border-radius: 24px;
            padding: 30px;
            display: flex; flex-direction: column;
        }
        
        .glass-panel.danger-panel {
            background: linear-gradient(135deg, rgba(244, 67, 54, 0.1), rgba(183, 28, 28, 0.15));
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        /* ================= Typography & Headers ================= */
        .header { 
            display: flex; justify-content: space-between; align-items: center; 
        }
        .header h1 { 
            margin: 0; color: #fff; font-size: 28px; 
            font-family: "Iowan Old Style", "Palatino Linotype", serif; 
            text-shadow: 0 2px 10px rgba(0, 30, 15, .4);
        }
        h2 { 
            color: #eaf9ae; font-size: 20px; margin-top: 0; margin-bottom: 20px; 
            font-family: "Iowan Old Style", "Palatino Linotype", serif; letter-spacing: 0.5px;
        }
        
        /* ================= Buttons ================= */
        .logout-btn { 
            background: rgba(255, 83, 80, 0.2); border: 1px solid rgba(255, 83, 80, 0.4);
            color: #ffebee; padding: 10px 20px; text-decoration: none; border-radius: 12px; 
            font-weight: 600; font-size: 14px; transition: 0.3s;
        }
        .logout-btn:hover { background: rgba(255, 83, 80, 0.4); }
        
        .btn-submit { 
            background: linear-gradient(135deg, #eaf9ae, #a6d96b); 
            color: #0d3a23; padding: 14px 20px; border: none; border-radius: 14px; 
            cursor: pointer; font-size: 16px; font-weight: 700; transition: 0.3s; 
            width: 100%; margin-top: auto;
            box-shadow: 0 8px 20px rgba(15, 60, 28, .3), inset 0 1px 0 rgba(255, 255, 255, .7);
        }
        .btn-submit:hover { 
            transform: translateY(-2px); filter: brightness(1.06); 
            box-shadow: 0 12px 25px rgba(15, 60, 28, .4), inset 0 1px 0 rgba(255, 255, 255, .7); 
        }

        .btn-sell { 
            background: linear-gradient(135deg, #4dd0e1, #00acc1); color: #fff; 
            box-shadow: 0 8px 20px rgba(0, 172, 193, 0.3), inset 0 1px 0 rgba(255, 255, 255, .4);
        }
        .btn-sell:hover { filter: brightness(1.1); box-shadow: 0 12px 25px rgba(0, 172, 193, 0.4), inset 0 1px 0 rgba(255, 255, 255, .4); }
        
        /* ================= Form Fields ================= */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #e0f1cf; font-size: 14px; }
        .form-group select, .form-group input { 
            width: 100%; height: 48px; padding: 0 16px; box-sizing: border-box; 
            border-radius: 12px; background: rgba(255, 255, 255, .1); 
            border: 1px solid rgba(255, 255, 255, .25); color: #fff;
            font-size: 15px; outline: none; transition: 0.3s;
        }
        .form-group input:focus, .form-group select:focus { 
            background: rgba(255, 255, 255, .18); border-color: #dff3a0; 
            box-shadow: 0 0 0 3px rgba(223, 243, 160, .2); 
        }
        .form-group select {
            appearance: none; cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' fill='none' stroke='%23e9fbc4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 1.5l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 16px center;
        }
        .form-group select option { color: #12321f; background: #eef6e6; }
        .form-group select option:disabled { color: #9e9e9e; background: #f5f5f5; }

        /* ================= Alerts ================= */
        .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 25px; font-size: 15px; backdrop-filter: blur(10px); }
        .success { background: rgba(76, 175, 80, 0.2); border: 1px solid rgba(76, 175, 80, 0.4); color: #dcedc8; }
        .error { background: rgba(244, 67, 54, 0.2); border: 1px solid rgba(244, 67, 54, 0.4); color: #ffcdd2; }
        .alert strong { color: #fff; }

        /* ================= Tables ================= */
        .table-container { max-height: 280px; overflow-y: auto; padding-right: 5px;}
        .table-container::-webkit-scrollbar { width: 6px; }
        .table-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 14px 12px; text-align: left; border: none; }
        th { 
            color: #eaf9ae; font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.25);
            position: sticky; top: 0; background: rgba(20, 50, 30, 0.95); z-index: 1; 
        }
        .danger-panel th { background: rgba(60, 20, 20, 0.95); color: #ffcdd2; border-bottom-color: rgba(244, 67, 54, 0.3); }
        
        td { border-bottom: 1px solid rgba(255, 255, 255, 0.1); color: #f3f9ec; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: rgba(255, 255, 255, 0.05); }

        .barcode { font-family: monospace; color: #fff; background: rgba(255,255,255,0.1); padding: 4px 8px; border-radius: 6px; letter-spacing: 1px;}
        .row-expired { background-color: rgba(244, 67, 54, 0.15); }
        .row-warning { background-color: rgba(255, 152, 0, 0.15); }

        /* ================= Badges ================= */
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; color: white; margin-left: 8px; text-transform: uppercase;}
        .badge-red { background: rgba(244, 67, 54, 0.4); border: 1px solid rgba(244,67,54,0.6); }
        .badge-orange { background: rgba(255, 152, 0, 0.4); border: 1px solid rgba(255,152,0,0.6); }

        /* ================= Charts & Neon Glow ================= */
        .chart-container { position: relative; height: 260px; width: 100%; margin-top: auto;}
        
        #topSellerChart {
            filter: drop-shadow(0 0 12px rgba(0, 255, 255, 0.8));
        }

        /* Toggle Switch CSS */
        .switch-container { display: flex; align-items: center; justify-content: flex-end; }
        .switch-label { font-size: 13px; font-weight: 600; color: #e0f1cf; margin: 0 10px; }
        .switch { position: relative; display: inline-block; width: 44px; height: 22px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(255,255,255,0.2); transition: .4s; border-radius: 24px; border: 1px solid rgba(255,255,255,0.3);}
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: #fff; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: rgba(234, 249, 174, 0.4); border-color: #eaf9ae; }
        input:checked + .slider:before { transform: translateX(22px); background-color: #eaf9ae;}

        /* Mobile Adjustments */
        @media (max-width: 992px) {
            .col-4, .col-5, .col-7, .col-8, .col-12 { grid-column: span 12; }
            .chart-container { height: 220px; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    
    <!-- Header Panel -->
    <div class="glass-panel col-12" style="padding: 20px 30px; margin-bottom: 25px; flex-direction: row; justify-content: space-between; align-items: center;">
        <h1 style="margin:0;">Welcome back, <?php echo htmlspecialchars($pharmacy_name); ?>!</h1>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <?php echo $status_message; ?>

    <!-- ================= ROW 1: Actions (POS & Order) ================= -->
    <div class="grid-layout">
        
        <!-- POS Panel (Left) -->
        <div class="glass-panel col-5">
            <h2>Point of Sale (POS)</h2>
            <form method="POST" action="" style="display:flex; flex-direction:column; height: 100%;">
                <div class="form-group">
                    <label>Select Package to Sell From</label>
                    <select name="sell_medicine_code" required>
                        <option value="">-- Choose a package barcode --</option>
                        <?php 
                        foreach ($available_inventory as $code => $data): 
                            $is_expired = (strtotime($data['expiry_date']) < time());
                            // Fallback to 0 if is_recalled isn't in the array (prevent warnings)
                            $is_recalled = (($data['is_recalled'] ?? 0) == 1);
                            $disabled = ($is_expired || $is_recalled) ? 'disabled' : '';
                        ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $disabled; ?>>
                                <?php echo htmlspecialchars($data['medicine_name']); ?> (Batch: <?php echo htmlspecialchars($data['batch_id']); ?>) 
                                - Stock: <?php echo $data['current_stock']; ?>
                                <?php if($is_recalled) echo ' [RECALLED]'; elseif($is_expired) echo ' [EXPIRED]'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity to Sell</label>
                    <input type="number" name="sell_quantity" min="1" required>
                </div>
                <button type="submit" name="sell_medicine" class="btn-submit btn-sell">Confirm Sale</button>
            </form>
        </div>

        <!-- Order Panel (Right) -->
        <div class="glass-panel col-7">
            <h2>Order New Medicine from Manufacturer</h2>
            <form method="POST" action="" style="display:flex; flex-direction:column; height: 100%;">
                <div class="form-group">
                    <label>Select Fresh Batch</label>
                    <select name="medicine_id" required>
                        <option value="">-- Choose an Available Medicine --</option>
                        <?php foreach ($medicines_list as $med): ?>
                            <option value="<?php echo $med['medicine_id']; ?>">
                                <?php echo htmlspecialchars($med['medicine_name']); ?> (Batch: <?php echo htmlspecialchars($med['batch_id']); ?>) - Mfr Stock: <?php echo $med['quantity']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Order Quantity</label>
                    <input type="number" name="order_quantity" min="1" required>
                </div>
                <button type="submit" name="place_order" class="btn-submit">Submit Restock Order</button>
            </form>
        </div>

    </div>

    <!-- ================= ROW 2: Charts ================= -->
    <div class="grid-layout">
        
        <!-- Bar Chart (Left) -->
        <div class="glass-panel col-7">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h2 style="margin:0;">6-Month Performance</h2>
                <div class="switch-container">
                    <span class="switch-label">Revenue (৳)</span>
                    <label class="switch">
                        <input type="checkbox" id="chartToggle" onchange="toggleChartData()">
                        <span class="slider"></span>
                    </label>
                    <span class="switch-label">Sales (Vol)</span>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="performanceChart"></canvas>
            </div>
        </div>
        
        <!-- Neon Line Chart (Right) -->
        <div class="glass-panel col-5">
            <h2>Top Product Trend: <?php echo htmlspecialchars($top_seller_name); ?></h2>
            <div class="chart-container">
                <canvas id="topSellerChart"></canvas>
            </div>
        </div>

    </div>

    <!-- ================= ROW 3: Tables ================= -->
    <div class="grid-layout">
        
        <!-- Active Inventory (Left) -->
        <div class="glass-panel col-8">
            <h2>Current Package Inventory</h2>
            <div class="table-container">
                <?php if (count($available_inventory) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Tracking Code</th>
                                <th>Product Details</th>
                                <th>Stock</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $current_time = time();
                            foreach ($available_inventory as $code => $data): 
                                $expiry_time = strtotime($data['expiry_date']);
                                $days_left = round(($expiry_time - $current_time) / (60 * 60 * 24));
                                $is_recalled = (($data['is_recalled'] ?? 0) == 1);
                                
                                $row_class = "";
                                $expiry_label = htmlspecialchars($data['expiry_date']);
                                
                                if ($is_recalled) {
                                    $row_class = "row-expired";
                                    $expiry_label .= " <span class='badge badge-red'>RECALLED</span>";
                                } elseif ($days_left < 0) {
                                    $row_class = "row-expired";
                                    $expiry_label .= " <span class='badge badge-red'>EXPIRED</span>";
                                } elseif ($days_left <= 30) {
                                    $row_class = "row-warning";
                                    $expiry_label .= " <span class='badge badge-orange'>SOON</span>";
                                }
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td><span class="barcode"><?php echo htmlspecialchars($code); ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars($data['medicine_name']); ?><br>
                                        <small style="color: rgba(255,255,255,0.5);">Batch: <?php echo htmlspecialchars($data['batch_id']); ?></small>
                                    </td>
                                    <td><strong style="font-size: 16px;"><?php echo htmlspecialchars($data['current_stock']); ?></strong></td>
                                    <td><?php echo $expiry_label; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: rgba(255,255,255,0.5); text-align: center; margin-top:40px;">Your inventory is empty.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Low Stock (Right) -->
        <div class="glass-panel col-4">
            <h2>Low Stock Alerts</h2>
            <div class="table-container">
                <?php if (count($low_stock_items) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Brand</th>
                                <th>Combined Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_stock_items as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['medicine_name']); ?></td>
                                    <td>
                                        <?php if ($item['total_stock'] == 0): ?>
                                            <span style="color: #ff8a80; font-weight: bold;">0 (Depleted)</span>
                                        <?php else: ?>
                                            <span style="color: #ffe0b2; font-weight: bold;"><?php echo $item['total_stock']; ?> (Low)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #eaf9ae; font-weight: bold; text-align: center; margin-top:40px;">All products well stocked!</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ================= ROW 4: Quarantined / Recalled Batches ================= -->
    <div class="grid-layout">
        <div class="glass-panel danger-panel col-12">
            <h2 style="color: #ffcdd2 !important; margin-bottom: 5px;">Quarantined (Recalled) Batches</h2>
            <p style="color: rgba(255,255,255,0.7); font-size: 13px; margin-top: 0; margin-bottom: 20px;">
                These packages have been recalled by the manufacturer. Remove them from shelves immediately.
            </p>
            
            <div class="table-container" style="max-height: 250px;">
                <?php if (count($recalled_inventory) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Tracking Code</th>
                                <th>Product Details</th>
                                <th>Quarantined Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recalled_inventory as $code => $data): ?>
                                <tr class="row-expired">
                                    <td><span class="barcode"><?php echo htmlspecialchars($code); ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars($data['medicine_name']); ?><br>
                                        <small style="color: rgba(255,255,255,0.5);">Batch: <?php echo htmlspecialchars($data['batch_id']); ?></small>
                                    </td>
                                    <td><strong style="color: #ffcdd2; font-size: 16px;"><?php echo htmlspecialchars($data['current_stock']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: rgba(255,255,255,0.8); font-weight: bold; text-align: center; margin-top:20px;">
                        No recalled batches in your pharmacy.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- Chart.js Rendering Scripts -->
<script>
    const labels = <?php echo $js_labels; ?>;
    const revData = <?php echo $js_rev; ?>;
    const volData = <?php echo $js_vol; ?>;
    const topData = <?php echo $js_top; ?>;

    // Global Chart Settings for Dark Glass Theme
    Chart.defaults.color = 'rgba(255, 255, 255, 0.7)';
    Chart.defaults.font.family = "'Segoe UI', sans-serif";
    
    // Shared Scales with ONLY horizontal low-opacity lines
    const glassScales = {
        x: {
            grid: { display: false, drawBorder: false }
        },
        y: {
            grid: { color: 'rgba(255, 255, 255, 0.1)', drawBorder: false },
            beginAtZero: true
        }
    };

    // 1. Performance Bar Chart (Toggleable)
    const ctxPerf = document.getElementById('performanceChart').getContext('2d');
    let performanceChart = new Chart(ctxPerf, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Monthly Revenue (৳)',
                data: revData,
                backgroundColor: 'rgba(234, 249, 174, 0.8)',
                borderColor: '#eaf9ae',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: glassScales,
            plugins: { legend: { labels: { color: '#fff' } } }
        }
    });

    function toggleChartData() {
        const isSalesVol = document.getElementById('chartToggle').checked;
        if (isSalesVol) {
            performanceChart.data.datasets[0].label = 'Sales Volume (Units)';
            performanceChart.data.datasets[0].data = volData;
            performanceChart.data.datasets[0].backgroundColor = 'rgba(77, 208, 225, 0.8)';
            performanceChart.data.datasets[0].borderColor = '#4dd0e1';
        } else {
            performanceChart.data.datasets[0].label = 'Monthly Revenue (৳)';
            performanceChart.data.datasets[0].data = revData;
            performanceChart.data.datasets[0].backgroundColor = 'rgba(234, 249, 174, 0.8)';
            performanceChart.data.datasets[0].borderColor = '#eaf9ae';
        }
        performanceChart.update();
    }

    // 2. Neon Line Chart (Glow achieved via CSS filter on canvas)
    const ctxTop = document.getElementById('topSellerChart').getContext('2d');
    
    // Create a slight gradient for the area under the line
    let neonGradient = ctxTop.createLinearGradient(0, 0, 0, 300);
    neonGradient.addColorStop(0, 'rgba(0, 255, 255, 0.3)');
    neonGradient.addColorStop(1, 'rgba(0, 255, 255, 0)');

    new Chart(ctxTop, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Units Sold',
                data: topData,
                borderColor: '#00ffff',
                backgroundColor: neonGradient,
                borderWidth: 3,
                fill: true,
                tension: 0.4, 
                pointBackgroundColor: '#fff',
                pointBorderColor: '#00ffff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: glassScales,
            plugins: { legend: { display: false } }
        }
    });
</script>

</body>
</html>