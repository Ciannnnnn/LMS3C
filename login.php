<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode([
            'success' => false,
            'message' => 'Security token expired. Please refresh and try again.'
        ]);
        exit;
    }

    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $allowedRoles = ['user', 'admin', 'professor'];

    if (empty($email) || empty($password) || empty($role)) {
        echo json_encode([
            'success' => false,
            'message' => 'All fields are required'
        ]);
        exit;
    }

    if (!in_array($role, $allowedRoles, true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid role selected'
        ]);
        exit;
    }

    $sql = "SELECT userId, email, userPassword, role FROM users WHERE (userId = ? OR email = ?) AND role = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to process login right now'
        ]);
        exit;
    }

    $stmt->bind_param("sss", $email, $email, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password'
        ]);
        exit;
    }

    $user = $result->fetch_assoc();

    if (password_verify($password, $user['userPassword'])) {
        session_regenerate_id(true);
        $_SESSION['userId'] = $user['userId'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['email'] = $user['email'];

        $ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $historyStmt = $conn->prepare("INSERT INTO login_history (userId, email, role, ipAddress, userAgent) VALUES (?, ?, ?, ?, ?)");
        if ($historyStmt) {
            $historyStmt->bind_param('sssss', $user['userId'], $user['email'], $user['role'], $ipAddress, $userAgent);
            $historyStmt->execute();
            $historyStmt->close();
        }

        if ($user['role'] === 'admin') {
            $redirect = 'admin_dashboard.php';
        } elseif ($user['role'] === 'professor') {
            $redirect = 'professor_dashboard.php';
        } else {
            $redirect = 'user_dashboard.php';
        }

        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => $redirect,
            'role' => $user['role']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password'
        ]);
    }

    $stmt->close();
    $conn->close();
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
?>
