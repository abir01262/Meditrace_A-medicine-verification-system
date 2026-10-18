CREATE DATABASE IF NOT EXISTS meditrace;
USE meditrace;




CREATE TABLE Manufacturer (
    manufacturer_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(150) NOT NULL,
    contact_email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(100) NOT NULL
);




CREATE TABLE Medicine (
    medicine_id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturer_id INT NOT NULL,
    medicine_name VARCHAR(100) NOT NULL,
    generic_name VARCHAR(100) NOT NULL,
    manufacturing_date DATE NOT NULL,
    expiry_date DATE NOT NULL,
    quantity INT NOT NULL,
    batch_id VARCHAR(50) NOT NULL,
    mrp DECIMAL(10,2) NOT NULL,
    retail_price DECIMAL(10,2) NOT NULL,
    wholesale_price DECIMAL(10,2) NOT NULL,
    is_recalled TINYINT(1) DEFAULT 0,

    FOREIGN KEY (manufacturer_id)
        REFERENCES Manufacturer(manufacturer_id)
);




CREATE TABLE Pharmacy (
    pharmacy_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(150) NOT NULL,
    contact_email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(100) NOT NULL,
    phone VARCHAR(20) UNIQUE NOT NULL
);







CREATE TABLE Package (
    package_id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_code VARCHAR(100)UNIQUE NOT NULL,
    medicine_id INT NOT NULL,
    pharmacy_id INT NOT NULL,
    package_amount INT NOT NULL,
    current_stock INT NOT NULL,
    send_date DATE NOT NULL,
    receive_date DATE,

    FOREIGN KEY (medicine_id)
        REFERENCES Medicine(medicine_id),

    FOREIGN KEY (pharmacy_id)
        REFERENCES Pharmacy(pharmacy_id)
);






CREATE TABLE Consumer (
    consumer_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) UNIQUE NOT NULL,
    password VARCHAR(100) NOT NULL
);





CREATE TABLE Medicine_Scan (
    scan_id INT AUTO_INCREMENT PRIMARY KEY,
    consumer_id INT NOT NULL,
    pharmacy_id INT NOT NULL,
    medicine_code VARCHAR(100) NOT NULL,
    scan_date DATETIME NOT NULL,
    scan_status VARCHAR(20) NOT NULL,

    FOREIGN KEY (consumer_id)
        REFERENCES Consumer(consumer_id),

    FOREIGN KEY (pharmacy_id)
        REFERENCES Pharmacy(pharmacy_id)

);




CREATE TABLE Scan_Verification (
    verification_id INT AUTO_INCREMENT PRIMARY KEY,
    scan_id INT NOT NULL,
    total_scan_amount INT NOT NULL DEFAULT 0,
    successful_scan_amount INT NOT NULL DEFAULT 0,
    failed_scan_amount INT NOT NULL DEFAULT 0,

    FOREIGN KEY (scan_id)
        REFERENCES Medicine_Scan(scan_id)
);




CREATE TABLE Medicine_Sale (
    sale_id INT AUTO_INCREMENT PRIMARY KEY,
    pharmacy_id INT NOT NULL,
    medicine_id INT NOT NULL,
    quantity_sold INT NOT NULL,
    total_price_amount DECIMAL(10,2) NOT NULL,
    sale_date DATETIME NOT NULL,

    FOREIGN KEY (pharmacy_id)
        REFERENCES Pharmacy(pharmacy_id),

    FOREIGN KEY (medicine_id)
        REFERENCES Medicine(medicine_id)
);




CREATE VIEW View_Board AS
SELECT 
    (SELECT COUNT(*) FROM Scan_Verification) AS total_verification,
    (SELECT COALESCE(SUM(successful_scan_amount), 0) FROM Scan_Verification) AS successful_verification,
    (SELECT COALESCE(SUM(failed_scan_amount), 0) FROM Scan_Verification) AS failed_verification,
    (SELECT COALESCE(SUM(quantity_sold), 0) FROM Medicine_Sale) AS total_medicine_sold,
    (SELECT COALESCE(SUM(quantity), 0) FROM Medicine) AS total_manufactured_medicine;
