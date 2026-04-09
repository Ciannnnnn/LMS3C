<?php
session_start();
include 'config.php';

if (!isset($_SESSION['userId']) || $_SESSION['role'] !== 'professor') {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['userId'];
$messages = [];

$assignmentUploadDir = __DIR__ . '/uploads/assignments';
$submissionUploadDir = __DIR__ . '/uploads/submissions';
if (!is_dir($assignmentUploadDir)) {
    mkdir($assignmentUploadDir, 0777, true);
}
if (!is_dir($submissionUploadDir)) {
    mkdir($submissionUploadDir, 0777, true);
}

$csrfToken = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $messages[] = ['type' => 'error', 'text' => 'Security token expired. Please refresh and try again.'];
    } elseif (isset($_POST['action']) && $_POST['action'] === 'create_assignment') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if (empty($title)) {
            $messages[] = ['type' => 'error', 'text' => 'Assignment title is required.'];
        } else {
            $filePath = null;
            if (!empty($_FILES['assignmentFile']['name'])) {
                $uploadResult = storeUploadedFile($_FILES['assignmentFile'], $assignmentUploadDir, 'uploads/assignments');
                if ($uploadResult['success']) {
                    $filePath = $uploadResult['filePath'];
                } else {
                    $messages[] = ['type' => 'error', 'text' => $uploadResult['message']];
                }
            }

            if (empty($messages)) {
                $validateStmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE userId = ? AND role = 'professor'");
                $validateStmt->bind_param('s', $userId);
                $validateStmt->execute();
                $validateResult = $validateStmt->get_result();
                $userExists = $validateResult->fetch_row()[0] > 0;
                $validateStmt->close();

                if (!$userExists) {
                    $messages[] = ['type' => 'error', 'text' => 'User account invalid or not a professor. Please log out and log in again.'];
                } else {
                    $dbUserStmt = $conn->prepare("SELECT userId FROM users WHERE userId = ? AND role = 'professor'");
                    $dbUserStmt->bind_param('s', $userId);
                    $dbUserStmt->execute();
                    $dbUserResult = $dbUserStmt->get_result();
                    $dbUserRow = $dbUserResult->fetch_assoc();
                    $dbUserStmt->close();

                    if (!$dbUserRow) {
                        $messages[] = ['type' => 'error', 'text' => 'Professor account not found in the database. Please contact admin or log in again.'];
                    } else {
                        $dbUserId = $dbUserRow['userId'];
                        if (empty($messages)) {
                            $moduleIdParam = null;
                            $stmt = $conn->prepare("INSERT INTO assignments (title, description, filePath, createdBy, moduleId) VALUES (?, ?, ?, ?, ?)");
                            $stmt->bind_param('ssssi', $title, $description, $filePath, $dbUserId, $moduleIdParam);
                            if ($stmt->execute()) {
                                $messages[] = ['type' => 'success', 'text' => 'Assignment created successfully.'];
                            } else {
                                $messages[] = ['type' => 'error', 'text' => 'Database INSERT error: ' . htmlspecialchars($stmt->error)];
                            }
                            $stmt->close();
                        }
                    }
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'grade_submission') {
        $submissionId = intval($_POST['submissionId'] ?? 0);
        $grade = trim($_POST['grade'] ?? '');
        $feedback = trim($_POST['feedback'] ?? '');

        if ($submissionId > 0) {
            $findStmt = $conn->prepare("SELECT s.submissionId, s.userId, s.assignmentId, s.submissionContent, s.filePath, s.submissionDate
                FROM submissions s
                JOIN assignments a ON a.assignmentId = s.assignmentId
                WHERE s.submissionId = ? AND a.createdBy = ?");
            $findStmt->bind_param('is', $submissionId, $userId);
            $findStmt->execute();
            $submissionRow = $findStmt->get_result()->fetch_assoc();
            $findStmt->close();

            if (!$submissionRow) {
                $messages[] = ['type' => 'error', 'text' => 'Submission not found or not owned by your assignment.'];
            } else {
                $conn->begin_transaction();
                try {
                    $archiveStmt = $conn->prepare("INSERT INTO submission_history
                        (originalSubmissionId, userId, assignmentId, submissionContent, filePath, grade, feedback, submittedAt, gradedBy, gradedAt)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $archiveStmt->bind_param(
                        'isissssss',
                        $submissionRow['submissionId'],
                        $submissionRow['userId'],
                        $submissionRow['assignmentId'],
                        $submissionRow['submissionContent'],
                        $submissionRow['filePath'],
                        $grade,
                        $feedback,
                        $submissionRow['submissionDate'],
                        $userId
                    );
                    $archiveStmt->execute();
                    $archiveStmt->close();

                    $deleteStmt = $conn->prepare("DELETE FROM submissions WHERE submissionId = ?");
                    $deleteStmt->bind_param('i', $submissionId);
                    $deleteStmt->execute();
                    $deleted = $deleteStmt->affected_rows > 0;
                    $deleteStmt->close();

                    if (!$deleted) {
                        throw new Exception('Failed to remove submission from active queue.');
                    }

                    $conn->commit();
                    $messages[] = ['type' => 'success', 'text' => 'Submission graded and moved to history.'];
                } catch (Exception $e) {
                    $conn->rollback();
                    $messages[] = ['type' => 'error', 'text' => 'Unable to finalize grade right now.'];
                }
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_assignment') {
        $assignmentId = intval($_POST['assignmentId'] ?? 0);

        if ($assignmentId <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Invalid assignment selected.'];
        } else {
            $findStmt = $conn->prepare("SELECT filePath FROM assignments WHERE assignmentId = ? AND createdBy = ?");
            $findStmt->bind_param('is', $assignmentId, $userId);
            $findStmt->execute();
            $assignmentRow = $findStmt->get_result()->fetch_assoc();
            $findStmt->close();

            if (!$assignmentRow) {
                $messages[] = ['type' => 'error', 'text' => 'You can only delete assignments you created.'];
            } else {
                $submissionFiles = [];
                $submissionFilesStmt = $conn->prepare("SELECT filePath FROM submissions WHERE assignmentId = ? AND filePath IS NOT NULL AND filePath <> ''");
                $submissionFilesStmt->bind_param('i', $assignmentId);
                $submissionFilesStmt->execute();
                $submissionFilesResult = $submissionFilesStmt->get_result();
                while ($fileRow = $submissionFilesResult->fetch_assoc()) {
                    $submissionFiles[] = $fileRow['filePath'];
                }
                $submissionFilesStmt->close();

                $conn->begin_transaction();
                try {
                    $deleteSubmissionsStmt = $conn->prepare("DELETE FROM submissions WHERE assignmentId = ?");
                    $deleteSubmissionsStmt->bind_param('i', $assignmentId);
                    $deleteSubmissionsStmt->execute();
                    $deleteSubmissionsStmt->close();

                    $deleteStmt = $conn->prepare("DELETE FROM assignments WHERE assignmentId = ? AND createdBy = ?");
                    $deleteStmt->bind_param('is', $assignmentId, $userId);
                    $deleteStmt->execute();
                    $deletedAssignment = $deleteStmt->affected_rows > 0;
                    $deleteStmt->close();

                    if (!$deletedAssignment) {
                        throw new Exception('Assignment was not deleted.');
                    }

                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $messages[] = ['type' => 'error', 'text' => 'Unable to delete assignment right now.'];
                    $deleteFailed = true;
                }

                if (empty($deleteFailed)) {
                    if (!empty($assignmentRow['filePath']) && strpos($assignmentRow['filePath'], 'uploads/assignments/') === 0) {
                        $localPath = __DIR__ . '/' . $assignmentRow['filePath'];
                        if (is_file($localPath)) {
                            @unlink($localPath);
                        }
                    }

                    foreach ($submissionFiles as $submissionFilePath) {
                        if (strpos($submissionFilePath, 'uploads/submissions/') === 0) {
                            $localSubmissionPath = __DIR__ . '/' . $submissionFilePath;
                            if (is_file($localSubmissionPath)) {
                                @unlink($localSubmissionPath);
                            }
                        }
                    }

                    $messages[] = ['type' => 'success', 'text' => 'Assignment and related student submissions deleted successfully.'];
                }
            }
        }
    }
}

$userSql = $conn->prepare("SELECT userId, email, role FROM users WHERE userId = ?");
$userSql->bind_param('s', $userId);
$userSql->execute();
$user = $userSql->get_result()->fetch_assoc();
$userSql->close();

$profAssignmentsStmt = $conn->prepare("SELECT a.*, m.moduleName, c.courseName
    FROM assignments a
    LEFT JOIN modules m ON m.moduleId = a.moduleId
    LEFT JOIN courses c ON c.courseId = m.courseId
    WHERE a.createdBy = ?
    ORDER BY a.createdAt DESC");
$profAssignmentsStmt->bind_param('s', $userId);
$profAssignmentsStmt->execute();
$assignments = $profAssignmentsStmt->get_result();
$profAssignmentsStmt->close();
$submissionQueryStmt = $conn->prepare("SELECT s.*, a.title AS assignmentTitle, u.email AS studentEmail FROM submissions s
    LEFT JOIN assignments a ON s.assignmentId = a.assignmentId
    LEFT JOIN users u ON s.userId = u.userId
    WHERE a.createdBy = ?
    ORDER BY s.submissionDate DESC");
$submissionQueryStmt->bind_param('s', $userId);
$submissionQueryStmt->execute();
$submissions = $submissionQueryStmt->get_result();
$submissionQueryStmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professor Dashboard - LMS Infotech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ink: #1f2a44;
            --muted: #63708a;
            --line: #dce5f5;
            --card: #ffffff;
            --brand-a: #0f5cd8;
            --brand-b: #1ea7fd;
            --danger: #d63e46;
            --ok-bg: #e9f9ef;
            --ok-ink: #155b2e;
            --err-bg: #fdeaea;
            --err-ink: #8e2323;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background: radial-gradient(circle at 0% 0%, #edf4ff 0%, #f6f9ff 45%, #fafcff 100%);
            color: var(--ink);
        }

        .page-wrap { max-width: 1220px; margin: 0 auto; padding: 28px; }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 26px;
            background: linear-gradient(135deg, #0d53c6 0%, #126ee6 60%, #23adfb 100%);
            border-radius: 20px;
            padding: 24px 26px;
            color: #fff;
            box-shadow: 0 22px 48px rgba(15, 92, 216, 0.25);
            position: relative;
            overflow: hidden;
        }

        .header::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            right: -110px;
            top: -120px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.14);
        }

        .header-title { position: relative; z-index: 1; }
        .header-title h1 { font-size: 30px; color: #fff; letter-spacing: -0.2px; }
        .header-title p { color: rgba(255, 255, 255, 0.93); margin-top: 8px; }

        .logout-btn {
            position: relative;
            z-index: 1;
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
            padding: 10px 16px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.35);
        }

        .logout-btn:hover {
            background: #fff;
            color: #105fda;
        }

        .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; margin-bottom: 22px; }

        .card {
            background: var(--card);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--line);
            box-shadow: 0 10px 25px rgba(31, 42, 68, 0.08);
        }

        .card h3 { font-size: 16px; margin-bottom: 8px; color: #254070; }
        .card p { color: var(--ink); line-height: 1.6; font-size: 30px; font-weight: 700; }

        .badge { display: inline-block; padding: 8px 13px; border-radius: 999px; background: #e8f0fe; color: #1b4f96; font-weight: 600; font-size: 12px; }

        .alert { border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; border: 1px solid transparent; font-weight: 500; }
        .alert.success { background: var(--ok-bg); color: var(--ok-ink); border-color: #c6eeda; }
        .alert.error { background: var(--err-bg); color: var(--err-ink); border-color: #f8cdcd; }

        .form-card,
        .card {
            margin-bottom: 18px;
        }

        .form-card {
            background: var(--card);
            border-radius: 16px;
            padding: 22px;
            border: 1px solid var(--line);
            box-shadow: 0 10px 25px rgba(31, 42, 68, 0.08);
        }

        .form-card h3,
        .card > h3 {
            margin-bottom: 14px;
            color: #264275;
        }

        .form-group { margin-bottom: 14px; }
        label { display: block; margin-bottom: 7px; color: #39527d; font-weight: 600; }
        input, textarea, select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #ccd9f1;
            border-radius: 10px;
            font-size: 14px;
            color: var(--ink);
            background: #fff;
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #3f86ef;
            box-shadow: 0 0 0 3px rgba(63, 134, 239, 0.15);
        }

        textarea { min-height: 115px; resize: vertical; }

        button {
            padding: 11px 18px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--brand-a) 0%, var(--brand-b) 100%);
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            transition: transform .18s ease, box-shadow .18s ease;
            box-shadow: 0 8px 18px rgba(15, 92, 216, 0.23);
        }

        button:hover { transform: translateY(-1px); box-shadow: 0 11px 22px rgba(15, 92, 216, 0.3); }

        .table { width: 100%; border-collapse: collapse; margin-top: 10px; overflow: hidden; }
        .table th, .table td { padding: 13px 14px; border-bottom: 1px solid #ebf0fa; text-align: left; vertical-align: top; }
        .table th { background: #eef4ff; color: #4a5f86; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .table tr:hover { background: #f7faff; }
        .table a { color: #0f5cd8; text-decoration: none; font-weight: 600; }
        .table a:hover { text-decoration: underline; }

        .btn-danger {
            background: var(--danger);
            color: #fff;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            box-shadow: none;
        }

        .btn-danger:hover { background: #bf343b; transform: translateY(-1px); box-shadow: none; }

        .submission-card {
            margin-bottom: 14px;
            padding: 16px;
            background: #fdfefe;
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(31, 42, 68, 0.06);
        }

        .submission-card h4 { margin-bottom: 8px; color: #244173; }
        .text-muted { color: var(--muted); font-size: 13px; }

        @media (max-width: 1024px) {
            .page-wrap { padding: 18px; }
            .card-grid { grid-template-columns: repeat(2, minmax(220px, 1fr)); }
            .table { min-width: 780px; }
        }

        @media (max-width: 768px) {
            .page-wrap { padding: 14px; }
            .header { flex-direction: column; align-items: flex-start; padding: 18px; }
            .header-title h1 { font-size: 26px; }
            .card-grid { grid-template-columns: 1fr; }
            .form-card, .card { padding: 16px; }
            .table { min-width: 720px; }
            .card { overflow-x: auto; }
            .submission-card form > div { grid-template-columns: 1fr !important; }
        }

        @media (max-width: 576px) {
            .header-title h1 { font-size: 22px; }
            .header-title p { font-size: 13px; }
            .logout-btn { width: 100%; text-align: center; }
            .form-group input,
            .form-group textarea,
            .form-group select { font-size: 13px; padding: 10px 12px; }
            button { width: 100%; }
            .btn-danger { width: auto; }
            .submission-card { padding: 12px; }
        }
    </style>
</head>
<body>
    <div class="page-wrap">
        <div class="header">
            <div class="header-title">
                <h1>Professor Dashboard</h1>
                <p>Welcome, <?php echo htmlspecialchars($user['email']); ?>. Share assignments and grade student submissions.</p>
            </div>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>

        <?php foreach ($messages as $notice): ?>
            <div class="alert <?php echo $notice['type'] === 'success' ? 'success' : 'error'; ?>">
                <?php echo $notice['text']; ?>
            </div>
        <?php endforeach; ?>

        <div class="card-grid">
            <div class="card">
                <h3>Total Assignments</h3>
                <p><?php echo $assignments->num_rows; ?></p>
            </div>
            <div class="card">
                <h3>Pending Submissions</h3>
                <p><?php echo $submissions->num_rows; ?></p>
            </div>
            <div class="card">
                <h3>Your Role</h3>
                <span class="badge">Professor</span>
            </div>
        </div>

        <div class="form-card">
            <h3>Create New Assignment</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create_assignment">
                <div class="form-group">
                    <label for="title">Assignment Title</label>
                    <input type="text" id="title" name="title" required>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description"></textarea>
                </div>
                <div class="form-group">
                    <label>Scope</label>
                    <div class="text-muted" style="margin-top: 8px;">This will be posted as a general assignment for your students.</div>
                </div>
                <div class="form-group">
                    <label for="assignmentFile">Upload File</label>
                    <input type="file" id="assignmentFile" name="assignmentFile" accept=".pdf,.docx,.pptx,.zip,.jpg,.jpeg,.png,.txt">
                </div>
                <button type="submit">Send Assignment to Students</button>
            </form>
        </div>

        <div class="card">
            <h3>Active Assignments</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Created</th>
                        <th>File</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($assignment = $assignments->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($assignment['title']); ?></td>
                            <td><?php echo htmlspecialchars($assignment['createdAt']); ?></td>
                            <td>
                                <?php if ($assignment['filePath']): ?>
                                    <a href="<?php echo htmlspecialchars($assignment['filePath']); ?>" target="_blank">Download</a>
                                <?php else: ?>
                                    <span class="text-muted">No file</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Delete this module/assignment and all linked submissions?');" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="delete_assignment">
                                    <input type="hidden" name="assignmentId" value="<?php echo intval($assignment['assignmentId']); ?>">
                                    <button type="submit" class="btn-danger"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3>Student Submissions</h3>
            <?php if ($submissions && $submissions->num_rows > 0): ?>
                <?php while ($submission = $submissions->fetch_assoc()): ?>
                    <div class="submission-card">
                        <h4><?php echo htmlspecialchars($submission['assignmentTitle'] ?: 'Unknown Assignment'); ?></h4>
                        <p class="text-muted">Student: <?php echo htmlspecialchars($submission['studentEmail']); ?> | Submitted: <?php echo htmlspecialchars($submission['submissionDate']); ?></p>
                        <p><?php echo nl2br(htmlspecialchars($submission['submissionContent'] ?? 'No comment provided.')); ?></p>
                        <p>
                            <?php if ($submission['filePath']): ?>
                                <strong>File:</strong> <a href="<?php echo htmlspecialchars($submission['filePath']); ?>" target="_blank">Download submission</a>
                            <?php else: ?>
                                <strong>File:</strong> <span class="text-muted">No file uploaded</span>
                            <?php endif; ?>
                        </p>
                        <p><strong>Grade:</strong> <?php echo htmlspecialchars($submission['grade'] ?: 'Not graded'); ?></p>
                        <p><strong>Feedback:</strong> <?php echo htmlspecialchars($submission['feedback'] ?: 'No feedback yet'); ?></p>
                        <form method="POST" style="margin-top: 16px;">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="grade_submission">
                            <input type="hidden" name="submissionId" value="<?php echo intval($submission['submissionId']); ?>">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                                <input type="text" name="grade" placeholder="Grade (e.g. A, 85%)" value="<?php echo htmlspecialchars($submission['grade']); ?>">
                                <input type="text" name="feedback" placeholder="Feedback" value="<?php echo htmlspecialchars($submission['feedback']); ?>">
                            </div>
                            <button type="submit">Save Grade</button>
                        </form>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p class="text-muted">No submissions received yet.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
