<?php
require_once __DIR__ . '/security.php';
requireCliOnly();

$conn = new mysqli('localhost', 'root', '', 'infotech_3c', 3307);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}
$result = $conn->query("SELECT TABLE_NAME, CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'infotech_3c' AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
while ($row = $result->fetch_assoc()) {
    echo $row['TABLE_NAME'] . ' - ' . $row['CONSTRAINT_NAME'] . PHP_EOL;
}
$conn->close();
?>
