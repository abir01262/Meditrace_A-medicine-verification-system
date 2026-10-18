<?php

$host = 'localhost';
$dbname = 'meditrace';
$username = 'root'; 
$db_password = '';  

$conn = mysqli_connect($host, $username, $db_password, $dbname);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Only accept POST submissions
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit();
}

$role = $_POST['role'] ?? '';

// ------------------------------------------------------------
// CONSUMER: name, phone, password -> Consumer table
// ------------------------------------------------------------
if ($role === 'consumer') {

    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $phone === '' || $password === '') {
        header("Location: register.php?role=consumer&error=" . urlencode("Please fill in all fields."));
        exit();
    }

    // Hash the password before storing it 
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO Consumer (name, phone, password) VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "sss", $name, $phone, $hashedPassword);
//এখন actual INSERT হচ্ছে।
    if (mysqli_stmt_execute($stmt)) {
        //Registration successful হলে Statement বন্ধ করছে।
        mysqli_stmt_close($stmt);
        header("Location: index.php?registered=1");
        exit();
    } else {
        // Most likely cause: phone number already registered (UNIQUE)
        $error = (mysqli_errno($conn) === 1062)
            ? "That phone number is already registered."
            : "Registration failed. Please try again.";
        mysqli_stmt_close($stmt);
        header("Location: register.php?role=consumer&error=" . urlencode($error));
        exit();
    }

// ------------------------------------------------------------
// PHARMACY: name, location, email, phone, password -> Pharmacy table
// ------------------------------------------------------------
} elseif ($role === 'pharmacy') {

    $name     = trim($_POST['name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $location === '' || $email === '' || $phone === '' || $password === '') {
        header("Location: register.php?role=pharmacy&error=" . urlencode("Please fill in all fields."));
        exit();
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO Pharmacy (name, location, contact_email, password, phone) VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "sssss", $name, $location, $email, $hashedPassword, $phone);

    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        header("Location: index.php?registered=1");
        exit();
    } else {
        // Most likely cause: email or phone already registered (UNIQUE)
        $error = (mysqli_errno($conn) === 1062)
            ? "That email or phone number is already registered."
            : "Registration failed. Please try again.";
        mysqli_stmt_close($stmt);
        header("Location: register.php?role=pharmacy&error=" . urlencode($error));
        exit();
    }

// ------------------------------------------------------------
// MANUFACTURER: name, location, email, password -> Manufacturer table
// ------------------------------------------------------------
} elseif ($role === 'manufacturer') {

    $name     = trim($_POST['name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $location === '' || $email === '' || $password === '') {
        header("Location: register.php?role=manufacturer&error=" . urlencode("Please fill in all fields."));
        exit();
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO Manufacturer (name, location, contact_email, password) VALUES (?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "ssss", $name, $location, $email, $hashedPassword);

    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        header("Location: index.php?registered=1");
        exit();
    } else {
        // Most likely cause: email already registered (UNIQUE)
        $error = (mysqli_errno($conn) === 1062)
            ? "That email is already registered."
            : "Registration failed. Please try again.";
        mysqli_stmt_close($stmt);
        header("Location: register.php?role=manufacturer&error=" . urlencode($error));
        exit();
    }

// ------------------------------------------------------------
// Unknown/missing role
// ------------------------------------------------------------
} else {
    header("Location: index.php");
    exit();
}
