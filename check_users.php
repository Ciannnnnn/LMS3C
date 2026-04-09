<?php
require_once __DIR__ . '/security.php';
requireCliOnly();

$conn = new mysqli('localhost', 'root', '', 'infotech_3c', 3307);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
$result = $conn->query('SELECT userId, email, role FROM users');
while ($row = $result->fetch_assoc()) {
    echo $row['userId'] . ' - ' . $row['email'] . ' - ' . $row['role'] . PHP_EOL;
}
$conn->close();
?>
