<?php
session_start();
include 'config.php';

if (!isset($_SESSION['userId']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$editing = false;
$userData = [
    'userId' => '',
    'email' => '',
    'role' => 'user',
];

if (isset($_GET['userId'])) {
    $editing = true;
    $userId = $_GET['userId'];
    $stmt = $conn->prepare("SELECT userId, email, role FROM users WHERE userId = ?");
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $userData = $result->fetch_assoc();
    }
    $stmt->close();
}

$csrfToken = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editing ? 'Edit User' : 'Add New User'; ?> - LMS Infotech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f4f7fb; color: #333; margin: 0; padding: 0; }
        .page { max-width: 700px; margin: 40px auto; padding: 30px; background: white; border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.08); }
        h1 { margin-bottom: 20px; color: #333; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input, select { width: 100%; padding: 14px 16px; border: 1px solid #dbe2ee; border-radius: 12px; font-size: 14px; }
        button { padding: 14px 22px; border: none; border-radius: 12px; background: #667eea; color: white; font-weight: 700; cursor: pointer; }
        a.button { display: inline-block; margin-top: 15px; text-decoration: none; color: #667eea; }

        @media (max-width: 768px) {
            .page {
                margin: 20px 14px;
                padding: 20px;
                border-radius: 14px;
            }

            h1 {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .page {
                margin: 12px;
                padding: 16px;
            }

            input, select {
                padding: 11px 12px;
                font-size: 13px;
            }

            button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <h1><?php echo $editing ? 'Edit User' : 'Add New User'; ?></h1>
        <form action="admin_action.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="action" value="<?php echo $editing ? 'edit_user' : 'add_user'; ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="originalUserId" value="<?php echo htmlspecialchars($userData['userId']); ?>">
            <?php endif; ?>
            <div class="form-group">
                <label for="userId">User ID</label>
                <input type="text" id="userId" name="userId" value="<?php echo htmlspecialchars($userData['userId']); ?>" required <?php echo $editing ? 'readonly' : ''; ?>>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($userData['email']); ?>" required>
            </div>
            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="user" <?php echo $userData['role'] === 'user' ? 'selected' : ''; ?>>Student</option>
                    <option value="professor" <?php echo $userData['role'] === 'professor' ? 'selected' : ''; ?>>Professor</option>
                    <option value="admin" <?php echo $userData['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label for="password"><?php echo $editing ? 'New Password (leave blank to keep current)' : 'Password'; ?></label>
                <input type="password" id="password" name="password" <?php echo $editing ? '' : 'required'; ?> placeholder="Enter password">
            </div>
            <button type="submit"><?php echo $editing ? 'Update User' : 'Create User'; ?></button>
        </form>
        <a href="admin_dashboard.php" class="button"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</body>
</html>
