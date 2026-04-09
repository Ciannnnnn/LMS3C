<?php
require_once __DIR__ . '/security.php';
requireCliOnly();

$conn = new mysqli('localhost', 'root', '', 'infotech_3c', 3307);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
$result = $conn->query('SELECT userId, userPassword FROM users WHERE userId = "admin123"');
$row = $result->fetch_assoc();
if (!$row) {
    die('Admin account not found.' . PHP_EOL);
}
echo 'Current hash: ' . $row['userPassword'] . PHP_EOL;
echo 'Verify admin1234: ' . (password_verify('admin1234', $row['userPassword']) ? 'YES' : 'NO') . PHP_EOL;
echo 'Verify password123: ' . (password_verify('password123', $row['userPassword']) ? 'YES' : 'NO') . PHP_EOL;
$conn->close();
?>
