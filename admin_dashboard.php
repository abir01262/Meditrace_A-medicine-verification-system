<?php
session_start();

// Verify user is logged in as admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
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

// 1. Fetch data from the View_Board
$stats = [
    'total_verification' => 0,
    'successful_verification' => 0,
    'failed_verification' => 0,
    'total_medicine_sold' => 0,
    'total_manufactured_medicine' => 0
];

$query = "SELECT * FROM View_Board LIMIT 1";
$result = mysqli_query($conn, $query);
if ($result && mysqli_num_rows($result) > 0) {
    $stats = mysqli_fetch_assoc($result);
}

// 2. Fetch Entity Counts
$entity_query = "SELECT 
    (SELECT COUNT(*) FROM Manufacturer) AS m_count,
    (SELECT COUNT(*) FROM Pharmacy) AS p_count,
    (SELECT COUNT(*) FROM Consumer) AS c_count";
$entity_result = mysqli_query($conn, $entity_query);
$entities = mysqli_fetch_assoc($entity_result);

// 3. Fetch Suspicious Reports by District (Hotspots)
$hotspot_query = "SELECT p.location, COUNT(ms.scan_id) AS report_count 
                  FROM Medicine_Scan ms 
                  JOIN Pharmacy p ON ms.pharmacy_id = p.pharmacy_id 
                  WHERE ms.scan_status = 'Fake' 
                  GROUP BY p.location 
                  ORDER BY report_count DESC LIMIT 5";
$hotspot_result = mysqli_query($conn, $hotspot_query);

$hotspots = [];
$max_hotspot_count = 0;
if ($hotspot_result) {
    while ($row = mysqli_fetch_assoc($hotspot_result)) {
        $hotspots[] = $row;
        if ($row['report_count'] > $max_hotspot_count) {
            $max_hotspot_count = $row['report_count'];
        }
    }
}

// 4. Fetch Highly Demanded Medicines
$demand_query = "SELECT m.medicine_name, 
                    SUM(CASE WHEN MONTH(ms.sale_date) = MONTH(CURRENT_DATE()) THEN ms.quantity_sold ELSE 0 END) AS current_sales,
                    SUM(CASE WHEN MONTH(ms.sale_date) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH) THEN ms.quantity_sold ELSE 0 END) AS past_sales
                 FROM Medicine_Sale ms
                 JOIN Medicine m ON ms.medicine_id = m.medicine_id
                 GROUP BY m.medicine_id, m.medicine_name
                 ORDER BY current_sales DESC LIMIT 5";
$demand_result = mysqli_query($conn, $demand_query);

// 5. Fetch Regional Shortages
$shortage_query = "SELECT * FROM (
                        SELECT 
                            m.medicine_name,
                            p.location,
                            SUM(pkg.current_stock) AS current_inventory,
                            COALESCE((
                                SELECT SUM(ms2.quantity_sold)
                                FROM Medicine_Sale ms2
                                WHERE ms2.medicine_id = m.medicine_id
                                  AND ms2.pharmacy_id = p.pharmacy_id
                                  AND ms2.sale_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)
                            ), 0) AS required_inventory
                        FROM Package pkg
                        JOIN Medicine m ON pkg.medicine_id = m.medicine_id
                        JOIN Pharmacy p ON pkg.pharmacy_id = p.pharmacy_id
                        GROUP BY m.medicine_id, m.medicine_name, p.pharmacy_id, p.location
                    ) AS inventory_data
                    WHERE current_inventory < required_inventory
                    ORDER BY (required_inventory - current_inventory) DESC LIMIT 5";
$shortage_result = mysqli_query($conn, $shortage_query);

// 6. Global Scan Logs
$global_scans_query = "SELECT 
                            ms.scan_date, 
                            ms.medicine_code, 
                            ms.scan_status, 
                            c.consumer_id,
                            c.name AS consumer_name, 
                            p.name AS pharmacy_name,
                            p.location 
                       FROM Medicine_Scan ms 
                       JOIN Consumer c ON ms.consumer_id = c.consumer_id 
                       JOIN Pharmacy p ON ms.pharmacy_id = p.pharmacy_id
                       ORDER BY ms.scan_date DESC LIMIT 100";
$global_scans_result = mysqli_query($conn, $global_scans_query);

// 7. System Directory Data
$mfr_result = mysqli_query($conn, "SELECT manufacturer_id, name, location, contact_email FROM Manufacturer ORDER BY manufacturer_id DESC");
$pharm_result = mysqli_query($conn, "SELECT pharmacy_id, name, location, contact_email, phone FROM Pharmacy ORDER BY pharmacy_id DESC");
$cons_result = mysqli_query($conn, "SELECT consumer_id, name, phone FROM Consumer ORDER BY consumer_id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - System Overview</title>
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
        .col-4 { grid-column: span 4; }
        .col-6 { grid-column: span 6; }
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

        /* ================= Buttons & Filters ================= */
        .logout-btn { 
            background: rgba(255, 83, 80, 0.2); border: 1px solid rgba(255, 83, 80, 0.4);
            color: #ffebee; padding: 10px 20px; text-decoration: none; border-radius: 12px; 
            font-weight: 600; font-size: 14px; transition: 0.3s;
        }
        .logout-btn:hover { background: rgba(255, 83, 80, 0.4); }
        
        .filter-select {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            outline: none;
            font-family: inherit;
            cursor: pointer;
        }
        .filter-select option { background: #0a2a1c; color: #fff; }

        /* ================= Stats Boxes (Grid & Flex) ================= */
        .stat-box { text-align: center; display: flex; flex-direction: column; justify-content: center; height: 100%;}
        .stat-box h3 { margin: 0 0 10px 0; color: rgba(255,255,255,0.7); font-size: 14px; text-transform: uppercase; letter-spacing: 1.5px;}
        .stat-box .number { font-size: 40px; font-weight: bold; color: #fff; margin: 0; text-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        
        .stat-mini-container { display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between; }
        .stat-mini {
            flex: 1; min-width: 150px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }
        .stat-mini h4 {
            margin: 0 0 10px 0; color: rgba(255, 255, 255, 0.6);
            font-size: 13px; text-transform: uppercase; letter-spacing: 1px;
        }
        .stat-mini .number { margin: 0; font-size: 28px; font-weight: bold; color: #fff; }

        /* Custom Neon Colors for specific metrics */
        .text-blue { color: #40c4ff !important; text-shadow: 0 0 12px rgba(64, 196, 255, 0.4); }
        .text-orange { color: #ffd740 !important; text-shadow: 0 0 12px rgba(255, 215, 64, 0.4); }
        .text-purple { color: #e040fb !important; text-shadow: 0 0 12px rgba(224, 64, 251, 0.4); }
        .text-green { color: #69f0ae !important; text-shadow: 0 0 12px rgba(105, 240, 174, 0.4); }
        .text-red { color: #ff5252 !important; text-shadow: 0 0 12px rgba(255, 82, 82, 0.4); }

        /* ================= Tables ================= */
        .table-container { max-height: 380px; overflow-y: auto; overflow-x: auto; padding-right: 5px;}
        .table-container::-webkit-scrollbar { width: 6px; height: 6px; }
        .table-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 500px; }
        th, td { padding: 14px 12px; text-align: left; border: none; }
        th { 
            color: #eaf9ae; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.25);
            position: sticky; top: 0; background: rgba(20, 50, 30, 0.95); z-index: 1; 
        }
        td { border-bottom: 1px solid rgba(255, 255, 255, 0.1); color: #f3f9ec; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: rgba(255, 255, 255, 0.05); }
        td strong { color: #fff; }

        /* Table Trends & Badges */
        .trend-up { color: #69f0ae; font-weight: bold; }
        .trend-down { color: #ff5252; font-weight: bold; }
        .trend-neutral { color: rgba(255,255,255,0.5); }
        
        .badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; color: white; display: inline-block; letter-spacing: 0.5px; text-transform: uppercase;}
        .badge-auth { background: rgba(76, 175, 80, 0.3); border: 1px solid rgba(76, 175, 80, 0.6); color: #e8f5e9; }
        .badge-fake { background: rgba(244, 67, 54, 0.3); border: 1px solid rgba(244, 67, 54, 0.6); color: #ffebee; }
        .badge-expired { background: rgba(255, 152, 0, 0.3); border: 1px solid rgba(255, 152, 0, 0.6); color: #fff3e0; }
        .badge-recalled { background: rgba(183, 28, 28, 0.4); border: 1px solid rgba(183, 28, 28, 0.6); color: #ffcdd2; }

        /* ================= Custom Bar Chart (Hotspots) ================= */
        .bar-container { display: flex; align-items: center; margin-bottom: 18px; }
        .bar-label { width: 130px; color: #fff; font-weight: 500; font-size: 14px; }
        .bar-track { flex-grow: 1; margin: 0 15px; background: rgba(255, 255, 255, 0.1); border-radius: 8px; height: 18px; overflow: hidden; border: 1px solid rgba(255, 255, 255, 0.05); }
        .bar-fill { height: 100%; background: linear-gradient(90deg, #ff5252, #ff1744); border-radius: 8px; box-shadow: 0 0 10px rgba(255, 23, 68, 0.5); transition: width 0.5s ease; }
        .bar-value { width: 40px; text-align: right; font-weight: bold; color: #ffcdd2; font-size: 15px; }

        /* ================= Tabs (System Directory) ================= */
        .tab-buttons { display: flex; gap: 15px; border-bottom: 2px solid rgba(255,255,255,0.1); margin-bottom: 20px; padding-bottom: 10px; }
        .tab-btn { background: transparent; border: none; padding: 10px 20px; font-size: 14px; font-weight: 600; color: rgba(255,255,255,0.6); cursor: pointer; transition: 0.3s; border-radius: 8px; }
        .tab-btn:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .tab-btn.active { background: rgba(234, 249, 174, 0.15); color: #eaf9ae; border: 1px solid rgba(234, 249, 174, 0.3); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Mobile Adjustments */
        @media (max-width: 1024px) {
            .col-4, .col-6, .col-8, .col-12 { grid-column: span 12; }
            .stat-mini { flex-basis: 45%; }
        }
        @media (max-width: 600px) {
            .stat-mini { flex-basis: 100%; }
            .tab-buttons { flex-direction: column; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    
    <!-- Header Panel -->
    <div class="glass-panel col-12" style="padding: 20px 30px; margin-bottom: 25px; flex-direction: row; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="margin:0;">System Administrator Overview</h1>
            <p style="color: rgba(255,255,255,0.6); margin: 5px 0 0 0; font-size: 14px;">High-level analytics aggregated from the Meditrace database network.</p>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <!-- ================= ROW 1: Network Entities ================= -->
    <div class="grid-layout">
        <div class="glass-panel col-4">
            <div class="stat-box">
                <h3>Registered Manufacturers</h3>
                <p class="number"><?php echo number_format($entities['m_count']); ?></p>
            </div>
        </div>
        <div class="glass-panel col-4">
            <div class="stat-box">
                <h3>Registered Pharmacies</h3>
                <p class="number"><?php echo number_format($entities['p_count']); ?></p>
            </div>
        </div>
        <div class="glass-panel col-4">
            <div class="stat-box">
                <h3>Registered Consumers</h3>
                <p class="number"><?php echo number_format($entities['c_count']); ?></p>
            </div>
        </div>
    </div>

    <!-- ================= ROW 2: System Transactions ================= -->
    <div class="grid-layout">
        <div class="glass-panel col-12">
            <h2>System Transactions & Verifications</h2>
            <div class="stat-mini-container">
                <div class="stat-mini">
                    <h4>Total Manufactured</h4>
                    <p class="number text-blue"><?php echo number_format($stats['total_manufactured_medicine']); ?></p>
                </div>
                <div class="stat-mini">
                    <h4>Total Sold</h4>
                    <p class="number text-orange"><?php echo number_format($stats['total_medicine_sold']); ?></p>
                </div>
                <div class="stat-mini">
                    <h4>Total Scans</h4>
                    <p class="number text-purple"><?php echo number_format($stats['total_verification']); ?></p>
                </div>
                <div class="stat-mini">
                    <h4>Authentic Scans</h4>
                    <p class="number text-green"><?php echo number_format($stats['successful_verification']); ?></p>
                </div>
                <div class="stat-mini">
                    <h4>Fake Scans</h4>
                    <p class="number text-red"><?php echo number_format($stats['failed_verification']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= ROW 3: Security & Verifications ================= -->
    <div class="grid-layout">
        
        <!-- Counterfeit Hotspots (Left) -->
        <div class="glass-panel col-4">
            <h2>Counterfeit Hotspots</h2>
            <p style="color: rgba(255,255,255,0.6); font-size: 13px; margin-top: -10px; margin-bottom: 20px;">Regions with highest fake reports.</p>
            <div>
                <?php if (count($hotspots) > 0): ?>
                    <?php foreach ($hotspots as $spot): 
                        $percentage = ($max_hotspot_count > 0) ? ($spot['report_count'] / $max_hotspot_count) * 100 : 0;
                    ?>
                        <div class="bar-container">
                            <div class="bar-label"><?php echo htmlspecialchars($spot['location']); ?></div>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: <?php echo $percentage; ?>%;"></div>
                            </div>
                            <div class="bar-value"><?php echo htmlspecialchars($spot['report_count']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: rgba(255,255,255,0.5); text-align: center; margin-top: 40px;">No suspicious reports found yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Global Scan Logs (Right) -->
        <div class="glass-panel col-8">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                <h2 style="margin: 0;">Global Consumer Verification Logs</h2>
                <select id="scanFilter" class="filter-select" onchange="filterScans()">
                    <option value="All">View All Scans</option>
                    <option value="Fake">Show Fake Only</option>
                    <option value="Authentic">Show Authentic Only</option>
                    <option value="Expired">Show Expired / Recalled</option>
                </select>
            </div>
            <p style="color: rgba(255,255,255,0.6); font-size: 13px; margin-top: 0; margin-bottom: 15px;">
                Medicine names are masked in compliance with privacy policies. Pharmacies logged for tracking syndicates.
            </p>
            
            <div class="table-container">
                <table id="scanTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Scanned Barcode</th>
                            <th>Pharmacy / Distributor</th>
                            <th>Consumer Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($global_scans_result && mysqli_num_rows($global_scans_result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($global_scans_result)): 
                                $status_class = 'badge-auth';
                                if ($row['scan_status'] === 'Fake') $status_class = 'badge-fake';
                                elseif ($row['scan_status'] === 'Expired') $status_class = 'badge-expired';
                                elseif ($row['scan_status'] === 'Recalled') $status_class = 'badge-recalled';
                            ?>
                                <tr data-status="<?php echo htmlspecialchars($row['scan_status']); ?>">
                                    <td style="font-size: 12px; color: rgba(255,255,255,0.6);"><?php echo htmlspecialchars($row['scan_date']); ?></td>
                                    <td><span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($row['scan_status']); ?></span></td>
                                    <td style="font-family: monospace; font-size: 14px; font-weight: bold; color: <?php echo ($row['scan_status'] === 'Fake') ? '#ff5252' : '#fff'; ?>;">
                                        <?php echo htmlspecialchars($row['medicine_code']); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['pharmacy_name']); ?></strong><br>
                                        <small style="color: rgba(255,255,255,0.5);"><?php echo htmlspecialchars($row['location']); ?></small>
                                    </td>
                                    <td>
                                        ID: <?php echo htmlspecialchars($row['consumer_id']); ?> - <?php echo htmlspecialchars($row['consumer_name']); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center; color: rgba(255,255,255,0.5);">No consumer verifications logged yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ================= ROW 4: Supply Chain Status ================= -->
    <div class="grid-layout">
        
        <!-- High Demand (Left) -->
        <div class="glass-panel col-6">
            <h2>Highly Demanded Medicines</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>This Month</th>
                            <th>Trend (vs Last Month)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($demand_result && mysqli_num_rows($demand_result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($demand_result)): 
                                $diff = $row['current_sales'] - $row['past_sales'];
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['medicine_name']); ?></strong></td>
                                    <td><?php echo number_format($row['current_sales']); ?> units</td>
                                    <td>
                                        <?php if ($diff > 0): ?>
                                            <span class="trend-up">▲ +<?php echo $diff; ?></span>
                                        <?php elseif ($diff < 0): ?>
                                            <span class="trend-down">▼ <?php echo $diff; ?></span>
                                        <?php else: ?>
                                            <span class="trend-neutral">- No change</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align: center; color: rgba(255,255,255,0.5);">No sales data available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Shortages (Right) -->
        <div class="glass-panel col-6">
            <h2>Critical Shortages by Region</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Region</th>
                            <th>Current Stock</th>
                            <th>Required (30d)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($shortage_result && mysqli_num_rows($shortage_result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($shortage_result)): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['medicine_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['location']); ?></td>
                                    <td style="color: #ff5252; font-weight: bold;"><?php echo number_format($row['current_inventory']); ?></td>
                                    <td><?php echo number_format($row['required_inventory']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align: center; color: rgba(255,255,255,0.5); padding: 40px;">Inventory levels are sufficient across all regions.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ================= ROW 5: System Directory ================= -->
    <div class="grid-layout">
        <div class="glass-panel col-12">
            <h2 style="margin-bottom: 5px;">System User Directory</h2>
            <p style="color: rgba(255,255,255,0.6); font-size: 13px; margin-top: 0; margin-bottom: 20px;">
                Audit contact details and locations for all registered entities across the network.
            </p>
            
            <div class="tab-buttons">
                <button class="tab-btn active" onclick="showDirectoryTab('pharm-tab', this)">Pharmacies</button>
                <button class="tab-btn" onclick="showDirectoryTab('mfr-tab', this)">Manufacturers</button>
                <button class="tab-btn" onclick="showDirectoryTab('cons-tab', this)">Consumers</button>
            </div>

            <!-- Pharmacy Tab -->
            <div id="pharm-tab" class="tab-content active">
                <div class="table-container" style="max-height: 400px;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Pharmacy Name</th>
                                <th>Location (District)</th>
                                <th>Contact Email</th>
                                <th>Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($pharm_result && mysqli_num_rows($pharm_result) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($pharm_result)): ?>
                                    <tr>
                                        <td><strong style="color: #eaf9ae;">#<?php echo htmlspecialchars($row['pharmacy_id']); ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['location']); ?></td>
                                        <td><?php echo htmlspecialchars($row['contact_email']); ?></td>
                                        <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; color: rgba(255,255,255,0.5);">No pharmacies registered.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Manufacturer Tab -->
            <div id="mfr-tab" class="tab-content">
                <div class="table-container" style="max-height: 400px;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Manufacturer Name</th>
                                <th>Headquarters</th>
                                <th>Contact Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($mfr_result && mysqli_num_rows($mfr_result) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($mfr_result)): ?>
                                    <tr>
                                        <td><strong style="color: #eaf9ae;">#<?php echo htmlspecialchars($row['manufacturer_id']); ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['location']); ?></td>
                                        <td><?php echo htmlspecialchars($row['contact_email']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; color: rgba(255,255,255,0.5);">No manufacturers registered.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Consumer Tab -->
            <div id="cons-tab" class="tab-content">
                <div class="table-container" style="max-height: 400px;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Consumer Name</th>
                                <th>Registered Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($cons_result && mysqli_num_rows($cons_result) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($cons_result)): ?>
                                    <tr>
                                        <td><strong style="color: #eaf9ae;">#<?php echo htmlspecialchars($row['consumer_id']); ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="text-align: center; color: rgba(255,255,255,0.5);">No consumers registered.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Interactive Scripts -->
<script>
// Filter for Scan Logs
function filterScans() {
    var filterValue = document.getElementById('scanFilter').value;
    var rows = document.querySelectorAll('#scanTable tbody tr');

    rows.forEach(function(row) {
        if (!row.getAttribute('data-status')) return;
        
        var status = row.getAttribute('data-status');
        
        if (filterValue === 'All') {
            row.style.display = '';
        } else if (filterValue === 'Expired' && (status === 'Expired' || status === 'Recalled')) {
            row.style.display = '';
        } else if (status === filterValue) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Tabs for System Directory
function showDirectoryTab(tabId, btnElement) {
    // Hide all tab content
    document.querySelectorAll('.tab-content').forEach(function(tab) {
        tab.classList.remove('active');
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });
    
    // Show selected tab and highlight button
    document.getElementById(tabId).classList.add('active');
    btnElement.classList.add('active');
}
</script>

</body>
</html>