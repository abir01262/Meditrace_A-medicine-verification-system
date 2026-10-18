<?php
session_start();

// Verify that the user is logged in and is a manufacturer
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'manufacturer' || !isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Database connection parameters
$host = 'localhost';
$dbname = 'meditrace';
$username = 'root'; 
$db_password = '';  

// Connect to database
$conn = mysqli_connect($host, $username, $db_password, $dbname);
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$manufacturer_id = $_SESSION['user_id'];
$manufacturer_name = "Manufacturer"; // Fallback name
$message = "";

// Fetch the manufacturer's name from the database
$name_stmt = mysqli_prepare($conn, "SELECT name FROM Manufacturer WHERE manufacturer_id = ?");
mysqli_stmt_bind_param($name_stmt, "i", $manufacturer_id);
mysqli_stmt_execute($name_stmt);
mysqli_stmt_bind_result($name_stmt, $fetched_name);
if (mysqli_stmt_fetch($name_stmt)) {
    $manufacturer_name = $fetched_name;
}
mysqli_stmt_close($name_stmt);

// --- PROCESS: TRIGGER BATCH RECALL ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['trigger_recall'])) {
    $recall_med_id = intval($_POST['recall_medicine_id']);
    
    // Update sets is_recalled to 1 AND zeros out the warehouse quantity
    $recall_stmt = mysqli_prepare($conn, "UPDATE Medicine SET is_recalled = 1, quantity = 0 WHERE medicine_id = ? AND manufacturer_id = ?");
    mysqli_stmt_bind_param($recall_stmt, "ii", $recall_med_id, $manufacturer_id);
    
    if (mysqli_stmt_execute($recall_stmt)) {
        $message = "<div class='alert success'><strong>CRITICAL ACTION:</strong> Batch recalled. Remaining warehouse stock has been zeroed out and quarantined.</div>";
    } else {
        $message = "<div class='alert error'>Failed to recall batch: " . mysqli_error($conn) . "</div>";
    }
    mysqli_stmt_close($recall_stmt);
}

// --- PROCESS: ADD NEW MEDICINE ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_medicine'])) {
    $medicine_name = $_POST['medicine_name'] ?? '';
    $generic_name = $_POST['generic_name'] ?? '';
    $manufacturing_date = $_POST['manufacturing_date'] ?? '';
    $expiry_date = $_POST['expiry_date'] ?? '';
    $quantity = $_POST['quantity'] ?? 0;
    $batch_id = $_POST['batch_id'] ?? '';
    $mrp = $_POST['mrp'] ?? 0.00;
    $retail_price = $_POST['retail_price'] ?? 0.00;
    $wholesale_price = $_POST['wholesale_price'] ?? 0.00;

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO Medicine (manufacturer_id, medicine_name, generic_name, manufacturing_date, expiry_date, quantity, batch_id, mrp, retail_price, wholesale_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    
    mysqli_stmt_bind_param(
        $stmt, 
        "issssisddd", 
        $manufacturer_id, 
        $medicine_name, 
        $generic_name, 
        $manufacturing_date, 
        $expiry_date, 
        $quantity, 
        $batch_id, 
        $mrp, 
        $retail_price, 
        $wholesale_price
    );

    if (mysqli_stmt_execute($stmt)) {
        $message = "<div class='alert success'>Medicine added successfully to your inventory!</div>";
    } else {
        $message = "<div class='alert error'>Failed to add medicine: " . mysqli_error($conn) . "</div>";
    }
    mysqli_stmt_close($stmt);
}

// --- FETCH ANALYTICS WIDGET DATA ---
$stats = [
    'total_stock' => 0,
    'active_lines' => 0,
    'projected_value' => 0,
    'depleted_batches' => 0
];

// Query explicitly filters out recalled batches for active metrics
$stat_query = "SELECT 
                COALESCE(SUM(CASE WHEN is_recalled = 0 THEN quantity ELSE 0 END), 0) as total_stock,
                COUNT(DISTINCT CASE WHEN is_recalled = 0 THEN medicine_name END) as active_lines,
                COALESCE(SUM(CASE WHEN is_recalled = 0 THEN (quantity * wholesale_price) ELSE 0 END), 0) as projected_value,
                SUM(CASE WHEN quantity = 0 AND is_recalled = 0 THEN 1 ELSE 0 END) as depleted_batches
               FROM Medicine WHERE manufacturer_id = $manufacturer_id";
$stat_res = mysqli_query($conn, $stat_query);
if ($stat_res && $row = mysqli_fetch_assoc($stat_res)) {
    $stats = $row;
}

// --- FETCH INSERTED MEDICINES ---
$medicines = [];
// Added medicine_id, wholesale_price, and is_recalled to the fetch query
$fetch_stmt = mysqli_prepare($conn, "SELECT medicine_id, medicine_name, generic_name, batch_id, quantity, manufacturing_date, expiry_date, mrp, wholesale_price, is_recalled FROM Medicine WHERE manufacturer_id = ? ORDER BY medicine_id DESC");
mysqli_stmt_bind_param($fetch_stmt, "i", $manufacturer_id);
mysqli_stmt_execute($fetch_stmt);
$result = mysqli_stmt_get_result($fetch_stmt);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $medicines[] = $row;
    }
}
mysqli_stmt_close($fetch_stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manufacturer Dashboard</title>
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

        .dashboard-container { max-width: 1400px; margin: 0 auto; }

        /* ================= 12-Column CSS Grid System ================= */
        .grid-layout {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 25px;
            margin-bottom: 25px;
        }
        .col-3 { grid-column: span 3; }
        .col-4 { grid-column: span 4; }
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

        /* Stats Blocks (Now inside individual glass panels) */
        .stat-box { text-align: center; display: flex; flex-direction: column; justify-content: center; height: 100%;}
        .stat-box h3 { margin: 0 0 10px 0; color: rgba(255,255,255,0.7); font-size: 13px; text-transform: uppercase; letter-spacing: 1.5px;}
        .stat-box .number { font-size: 32px; font-weight: bold; color: #fff; margin: 0; text-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .danger-panel .number { color: #ffcdd2; }
        
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
            width: 100%; margin-top: 15px;
            box-shadow: 0 8px 20px rgba(15, 60, 28, .3), inset 0 1px 0 rgba(255, 255, 255, .7);
        }
        .btn-submit:hover { 
            transform: translateY(-2px); filter: brightness(1.06); 
            box-shadow: 0 12px 25px rgba(15, 60, 28, .4), inset 0 1px 0 rgba(255, 255, 255, .7); 
        }

        .btn-recall { 
            background: linear-gradient(135deg, #e53935, #c62828); color: white; 
            border: none; padding: 8px 14px; border-radius: 8px; cursor: pointer; 
            font-weight: bold; font-size: 12px; transition: 0.2s;
            box-shadow: 0 4px 10px rgba(229, 57, 53, 0.3), inset 0 1px 0 rgba(255, 255, 255, .3);
        }
        .btn-recall:hover { filter: brightness(1.1); transform: translateY(-1px);}
        
        /* ================= Form Fields ================= */
        .form-grid { display: grid; grid-template-columns: 1fr; gap: 15px; } /* Stacked in narrow panel */
        .form-group { margin-bottom: 0px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #e0f1cf; font-size: 13px; }
        .form-group input { 
            width: 100%; height: 42px; padding: 0 14px; box-sizing: border-box; 
            border-radius: 10px; background: rgba(255, 255, 255, .1); 
            border: 1px solid rgba(255, 255, 255, .25); color: #fff;
            font-size: 14px; outline: none; transition: 0.3s;
        }
        .form-group input:focus { 
            background: rgba(255, 255, 255, .18); border-color: #dff3a0; 
            box-shadow: 0 0 0 3px rgba(223, 243, 160, .2); 
        }
        
        /* Custom date picker icon tint */
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; opacity: 0.6; }

        /* ================= Alerts ================= */
        .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 25px; font-size: 15px; backdrop-filter: blur(10px); }
        .success { background: rgba(76, 175, 80, 0.2); border: 1px solid rgba(76, 175, 80, 0.4); color: #dcedc8; }
        .error { background: rgba(244, 67, 54, 0.2); border: 1px solid rgba(244, 67, 54, 0.4); color: #ffcdd2; }
        .alert strong { color: #fff; }

        /* ================= Tables ================= */
        .table-container { max-height: 550px; overflow-y: auto; overflow-x: auto; padding-right: 5px;}
        .table-container::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 700px; }
        th, td { padding: 14px 12px; text-align: left; border: none; }
        th { 
            color: #eaf9ae; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.25);
            position: sticky; top: 0; background: rgba(20, 50, 30, 0.95); z-index: 1; 
        }
        td { border-bottom: 1px solid rgba(255, 255, 255, 0.1); color: #f3f9ec; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: rgba(255, 255, 255, 0.05); }

        .barcode { font-family: monospace; color: #fff; background: rgba(255,255,255,0.1); padding: 4px 8px; border-radius: 6px; letter-spacing: 1px;}
        .row-recalled { background-color: rgba(244, 67, 54, 0.15) !important; }
        .row-recalled td { color: rgba(255,255,255,0.6); }

        /* ================= Badges ================= */
        .badge-danger { 
            background: rgba(244, 67, 54, 0.4); border: 1px solid rgba(244,67,54,0.6); 
            color: white; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold; 
            display: inline-block; letter-spacing: 0.5px;
        }
        .margin-positive { color: #eaf9ae; font-weight: bold; }

        /* Mobile Adjustments */
        @media (max-width: 1024px) {
            .col-3, .col-4, .col-8, .col-12 { grid-column: span 12; }
            .form-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    
    <!-- Header Panel -->
    <div class="glass-panel col-12" style="padding: 20px 30px; margin-bottom: 25px; flex-direction: row; justify-content: space-between; align-items: center;">
        <h1 style="margin:0;">Welcome back, <?php echo htmlspecialchars($manufacturer_name); ?>!</h1>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <!-- ================= ROW 1: Analytics Widgets ================= -->
    <div class="grid-layout">
        <div class="glass-panel col-3">
            <div class="stat-box">
                <h3>Warehouse Stock</h3>
                <p class="number"><?php echo number_format($stats['total_stock']); ?></p>
            </div>
        </div>
        <div class="glass-panel col-3">
            <div class="stat-box">
                <h3>Active Product Lines</h3>
                <p class="number"><?php echo number_format($stats['active_lines']); ?></p>
            </div>
        </div>
        <div class="glass-panel col-3">
            <div class="stat-box">
                <h3>Projected Value</h3>
                <p class="number">৳<?php echo number_format($stats['projected_value'], 2); ?></p>
            </div>
        </div>
        <div class="glass-panel col-3 <?php echo $stats['depleted_batches'] > 0 ? 'danger-panel' : ''; ?>">
            <div class="stat-box">
                <h3>Depleted Batches</h3>
                <p class="number"><?php echo number_format($stats['depleted_batches']); ?></p>
            </div>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- ================= ROW 2: Form and Table ================= -->
    <div class="grid-layout">
        
        <!-- Add New Medicine Form (Left Side) -->
        <div class="glass-panel col-4">
            <h2>Produce New Batch</h2>
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Medicine Name</label>
                        <input type="text" name="medicine_name" maxlength="100" required>
                    </div>
                    <div class="form-group">
                        <label>Generic Name</label>
                        <input type="text" name="generic_name" maxlength="100" required>
                    </div>
                    <div class="form-group">
                        <label>Batch ID</label>
                        <input type="text" name="batch_id" maxlength="50" required placeholder="e.g. BATCH-A01">
                    </div>
                    <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label>Quantity</label>
                            <input type="number" name="quantity" required min="1">
                        </div>
                        <div>
                            <label>Wholesale (৳)</label>
                            <input type="number" step="0.01" name="wholesale_price" required>
                        </div>
                    </div>
                    <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label>Retail Price (৳)</label>
                            <input type="number" step="0.01" name="retail_price" required>
                        </div>
                        <div>
                            <label>Max Retail (৳)</label>
                            <input type="number" step="0.01" name="mrp" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Manufacturing Date</label>
                        <input type="date" name="manufacturing_date" required>
                    </div>
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" required>
                    </div>
                </div>
                <button type="submit" name="add_medicine" class="btn-submit">Add Batch to Inventory</button>
            </form>
        </div>

        <!-- Batch Inventory Data Table (Right Side) -->
        <div class="glass-panel col-8">
            <h2>Your Active & Historical Batches</h2>
            <div class="table-container">
                <?php if (count($medicines) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Batch ID</th>
                                <th>Medicine Name</th>
                                <th>Stock</th>
                                <th>Exp Date</th>
                                <th>Pricing</th>
                                <th>Gross Margin</th>
                                <th>Security Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($medicines as $med): 
                                // Calculate absolute gross margin spread
                                $margin = $med['mrp'] - $med['wholesale_price'];
                                $row_class = ($med['is_recalled'] == 1) ? 'row-recalled' : '';
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td><span class="barcode"><?php echo htmlspecialchars($med['batch_id']); ?></span></td>
                                    <td>
                                        <strong style="color: #fff;"><?php echo htmlspecialchars($med['medicine_name']); ?></strong><br>
                                        <small style="color:rgba(255,255,255,0.6);"><?php echo htmlspecialchars($med['generic_name']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($med['quantity'] == 0 && $med['is_recalled'] == 0): ?>
                                            <span style="color: #ff8a80; font-weight: bold;">0 (Depleted)</span>
                                        <?php elseif ($med['quantity'] == 0 && $med['is_recalled'] == 1): ?>
                                            <span style="color: #ffcdd2;">0 (Quarantined)</span>
                                        <?php else: ?>
                                            <strong style="font-size:15px;"><?php echo htmlspecialchars($med['quantity']); ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($med['expiry_date']); ?></td>
                                    <td style="font-size: 13px;">
                                        <span style="color:rgba(255,255,255,0.6);">W:</span> ৳<?php echo htmlspecialchars($med['wholesale_price']); ?><br>
                                        <span style="color:rgba(255,255,255,0.6);">M:</span> ৳<?php echo htmlspecialchars($med['mrp']); ?>
                                    </td>
                                    <td class="margin-positive">+৳<?php echo number_format($margin, 2); ?></td>
                                    <td>
                                        <?php if ($med['is_recalled'] == 1): ?>
                                            <span class="badge-danger">RECALLED</span>
                                        <?php else: ?>
                                            <form method="POST" action="" onsubmit="return confirm('WARNING: Are you sure you want to recall this batch? This will flag all consumer scans as dangerous. This action cannot be undone.');">
                                                <input type="hidden" name="recall_medicine_id" value="<?php echo $med['medicine_id']; ?>">
                                                <button type="submit" name="trigger_recall" class="btn-recall">Trigger Recall</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: rgba(255,255,255,0.6); text-align: center; margin-top: 40px;">You have not added any medicines to your inventory yet.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

</body>
</html>