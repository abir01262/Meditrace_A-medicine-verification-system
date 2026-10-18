<?php
// Start a session so we can remember the user after they log in
session_start();

// Database connection credentials
$host = 'localhost';
$dbname = 'meditrace';
$username = 'root'; // Change if your DB uses a different username
$db_password = '';  // Change if your DB has a password

// 1. Connect to the database using PDO (PHP Data Objects) for security
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $db_password);
    // Set error mode to exception to catch database errors easily
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// 2. Check if the form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Grab the inputs from the form
    $role = $_POST['role'];             // 'manufacturer', 'pharmacy', or 'consumer'
    $login_id = trim($_POST['login_id']); // This is the Email OR Phone number
    $password = $_POST['password'];       // The raw password they typed
    
    $user = null;
    $sql = "";
    
    // 3. Prepare the correct SQL query based on the role
    if ($role === 'manufacturer') {
        $sql = "SELECT * FROM Manufacturer WHERE contact_email = :login_id";
        
    } elseif ($role === 'pharmacy') {
        // Pharmacy can log in with email OR phone
        $sql = "SELECT * FROM Pharmacy WHERE contact_email = :login_id OR phone = :login_id";
        
    } elseif ($role === 'consumer') {
        $sql = "SELECT * FROM Consumer WHERE phone = :login_id";
        
    } elseif ($role === 'admin') {
        // Admin hardcoded logic
        if ($login_id === 'admin' && $password === 'admin123') {
            $_SESSION['user_role'] = 'admin';
            $_SESSION['user_id'] = 0; // Admins don't have a DB ID here
            header("Location: admin_dashboard.php");
            exit();
        } else {
            die("Invalid Admin credentials.");
        }
    } else {
        die("Invalid role selected.");
    }

    // 4. Execute the query securely to prevent SQL Injection
    if ($sql !== "") {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['login_id' => $login_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC); // Fetch the user data as an array
    }

    // 5. Verify the user exists AND the password matches
    // password_verify() checks the raw typed password against the hashed password in the DB
    if ($user && password_verify($password, $user['password'])) {
        
        // Login Successful! Store data in the session variables
        $_SESSION['user_role'] = $role;
        
        // Store the correct ID based on the table
        if ($role === 'manufacturer') $_SESSION['user_id'] = $user['manufacturer_id'];
        if ($role === 'pharmacy') $_SESSION['user_id'] = $user['pharmacy_id'];
        if ($role === 'consumer') $_SESSION['user_id'] = $user['consumer_id'];
        
        // Redirect them to their respective dashboard
        header("Location: " . $role . "_dashboard.php");
        exit();
        
    } else {
        // Login Failed
        // Note: Never specify whether the email/phone was wrong or the password was wrong. 
        // Just say "Invalid credentials" for security.
       header("Location: index.php?login_error=" . urlencode("Invalid login credentials. Please try again."));
       exit();
        // You would typically redirect back to login.php?error=1 here
    }
}
?>