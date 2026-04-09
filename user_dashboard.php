<?php
session_start();
include 'config.php';

if (!isset($_SESSION['userId'])) {
    header('Location: index.php');
    exit;
}

if ($_SESSION['role'] === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

if ($_SESSION['role'] === 'professor') {
    header('Location: professor_dashboard.php');
    exit;
}

$userId = $_SESSION['userId'];
$sql = "SELECT userId, email, role FROM users WHERE userId = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$roleLabel = $user['role'] === 'user' ? 'Student' : ucfirst($user['role']);

$messages = [];
$csrfToken = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');

function studentCanAccessAssignment($conn, $userId, $assignmentId) {
    $sql = "SELECT a.assignmentId, a.moduleId, a.createdBy, m.courseId
            FROM assignments a
            LEFT JOIN modules m ON m.moduleId = a.moduleId
            WHERE a.assignmentId = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $assignmentId);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$assignment) {
        return false;
    }

    if (empty($assignment['moduleId'])) {
        $generalAccessStmt = $conn->prepare("SELECT 1
            FROM enrollments e
            JOIN courses c ON c.courseId = e.courseId
            WHERE e.userId = ? AND c.professorId = ?
            LIMIT 1");
        $generalAccessStmt->bind_param('ss', $userId, $assignment['createdBy']);
        $generalAccessStmt->execute();
        $allowed = $generalAccessStmt->get_result()->num_rows > 0;
        $generalAccessStmt->close();
        return $allowed;
    }

    $moduleId = intval($assignment['moduleId']);
    $courseId = isset($assignment['courseId']) ? intval($assignment['courseId']) : 0;

    if ($courseId > 0) {
        $accessStmt = $conn->prepare("SELECT 1 FROM enrollments WHERE userId = ? AND (moduleId = ? OR courseId = ?) LIMIT 1");
        $accessStmt->bind_param('sii', $userId, $moduleId, $courseId);
    } else {
        $accessStmt = $conn->prepare("SELECT 1 FROM enrollments WHERE userId = ? AND moduleId = ? LIMIT 1");
        $accessStmt->bind_param('si', $userId, $moduleId);
    }

    $accessStmt->execute();
    $allowed = $accessStmt->get_result()->num_rows > 0;
    $accessStmt->close();
    return $allowed;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $messages[] = ['type' => 'error', 'text' => 'Security token expired. Please refresh and try again.'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_assignment') {
    $assignmentId = intval($_POST['assignmentId'] ?? 0);
    $submissionContent = trim($_POST['submissionContent'] ?? '');
    $filePath = null;
    $uploadDir = __DIR__ . '/uploads/submissions';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if ($assignmentId <= 0) {
        $messages[] = ['type' => 'error', 'text' => 'Please choose an assignment.'];
    } elseif ($submissionContent === '' && empty($_FILES['submissionFile']['name'])) {
        $messages[] = ['type' => 'error', 'text' => 'Add a note or upload a file before submitting.'];
    } elseif (!studentCanAccessAssignment($conn, $userId, $assignmentId)) {
        $messages[] = ['type' => 'error', 'text' => 'You are not enrolled in the course/module for this assignment.'];
    } else {
        if (!empty($_FILES['submissionFile']['name'])) {
            $uploadResult = storeUploadedFile($_FILES['submissionFile'], $uploadDir, 'uploads/submissions');
            if ($uploadResult['success']) {
                $filePath = $uploadResult['filePath'];
            } else {
                $messages[] = ['type' => 'error', 'text' => $uploadResult['message']];
            }
        }

        if (empty($messages)) {
            $stmtSubmit = $conn->prepare("INSERT INTO submissions (userId, assignmentId, submissionContent, filePath) VALUES (?, ?, ?, ?)");
            $stmtSubmit->bind_param('siss', $userId, $assignmentId, $submissionContent, $filePath);
            if ($stmtSubmit->execute()) {
                $messages[] = ['type' => 'success', 'text' => 'Assignment answer submitted successfully.'];
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Submission failed: ' . $conn->error];
            }
            $stmtSubmit->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_course_enrollment' && verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $courseId = intval($_POST['courseId'] ?? 0);

    if ($courseId <= 0) {
        $messages[] = ['type' => 'error', 'text' => 'Invalid course selected.'];
    } else {
        $courseCheckStmt = $conn->prepare("SELECT courseId FROM courses WHERE courseId = ? LIMIT 1");
        $courseCheckStmt->bind_param('i', $courseId);
        $courseCheckStmt->execute();
        $courseExists = $courseCheckStmt->get_result()->num_rows > 0;
        $courseCheckStmt->close();

        if (!$courseExists) {
            $messages[] = ['type' => 'error', 'text' => 'Selected course does not exist.'];
        } else {
            $enrollmentCheckStmt = $conn->prepare("SELECT enrollmentId FROM enrollments WHERE userId = ? AND courseId = ? LIMIT 1");
            $enrollmentCheckStmt->bind_param('si', $userId, $courseId);
            $enrollmentCheckStmt->execute();
            $alreadyEnrolled = $enrollmentCheckStmt->get_result()->num_rows > 0;
            $enrollmentCheckStmt->close();

            if ($alreadyEnrolled) {
                $messages[] = ['type' => 'error', 'text' => 'You are already enrolled in this course.'];
            } else {
                $pendingCheckStmt = $conn->prepare("SELECT requestId FROM enrollment_requests WHERE userId = ? AND courseId = ? AND status = 'pending' LIMIT 1");
                $pendingCheckStmt->bind_param('si', $userId, $courseId);
                $pendingCheckStmt->execute();
                $hasPending = $pendingCheckStmt->get_result()->num_rows > 0;
                $pendingCheckStmt->close();

                if ($hasPending) {
                    $messages[] = ['type' => 'error', 'text' => 'You already have a pending request for this course.'];
                } else {
                    $requestStmt = $conn->prepare("INSERT INTO enrollment_requests (userId, courseId, status) VALUES (?, ?, 'pending')");
                    $requestStmt->bind_param('si', $userId, $courseId);
                    if ($requestStmt->execute()) {
                        $messages[] = ['type' => 'success', 'text' => 'Enrollment request submitted. Please wait for admin approval.'];
                    } else {
                        $messages[] = ['type' => 'error', 'text' => 'Unable to submit request right now.'];
                    }
                    $requestStmt->close();
                }
            }
        }
    }
}

$enrollmentsStmt = $conn->prepare("SELECT * FROM enrollments WHERE userId = ?");
$enrollmentsStmt->bind_param('s', $userId);
$enrollmentsStmt->execute();
$enrollments = $enrollmentsStmt->get_result();
$enrollmentCount = $enrollments->num_rows;
$enrollmentsStmt->close();

$enrolledCourseIds = [];
$enrolledCoursesStmt = $conn->prepare("SELECT DISTINCT courseId FROM enrollments WHERE userId = ? AND courseId IS NOT NULL");
$enrolledCoursesStmt->bind_param('s', $userId);
$enrolledCoursesStmt->execute();
$enrolledCourseResults = $enrolledCoursesStmt->get_result();
while ($row = $enrolledCourseResults->fetch_assoc()) {
    $enrolledCourseIds[(int)$row['courseId']] = true;
}
$enrolledCoursesStmt->close();

$pendingRequestCourseIds = [];
$pendingRequestsStmt = $conn->prepare("SELECT courseId FROM enrollment_requests WHERE userId = ? AND status = 'pending'");
$pendingRequestsStmt->bind_param('s', $userId);
$pendingRequestsStmt->execute();
$pendingRequestsResult = $pendingRequestsStmt->get_result();
while ($row = $pendingRequestsResult->fetch_assoc()) {
    $pendingRequestCourseIds[(int)$row['courseId']] = true;
}
$pendingRequestsStmt->close();

$availableCoursesResult = $conn->query("SELECT c.courseId, c.courseName, c.description, u.email AS professorEmail FROM courses c LEFT JOIN users u ON u.userId = c.professorId ORDER BY c.createdAt DESC");
$availableCourses = [];
if ($availableCoursesResult) {
    while ($row = $availableCoursesResult->fetch_assoc()) {
        $availableCourses[] = $row;
    }
}

$moduleResourcesStmt = $conn->prepare("SELECT r.*, m.moduleName, c.courseName, u.email AS uploaderEmail
    FROM module_resources r
    LEFT JOIN modules m ON m.moduleId = r.moduleId
    LEFT JOIN courses c ON c.courseId = m.courseId
    LEFT JOIN users u ON u.userId = r.createdBy
    WHERE r.moduleId IS NULL
       OR EXISTS (
        SELECT 1
        FROM enrollments e
        WHERE e.userId = ?
          AND (
            e.moduleId = r.moduleId
            OR (m.courseId IS NOT NULL AND e.courseId = m.courseId)
          )
    )
    ORDER BY r.createdAt DESC");
$moduleResourcesStmt->bind_param('s', $userId);
$moduleResourcesStmt->execute();
$moduleResourcesResult = $moduleResourcesStmt->get_result();
$moduleResources = [];
while ($row = $moduleResourcesResult->fetch_assoc()) {
    $moduleResources[] = $row;
}
$moduleResourcesStmt->close();

$assignmentResults = $conn->prepare("SELECT a.*, u.email AS instructorEmail, m.moduleName, c.courseName
    FROM assignments a
    LEFT JOIN users u ON u.userId = a.createdBy
    LEFT JOIN modules m ON m.moduleId = a.moduleId
    LEFT JOIN courses c ON c.courseId = m.courseId
    WHERE (
        a.moduleId IS NOT NULL AND EXISTS (
            SELECT 1
            FROM enrollments e
            WHERE e.userId = ?
              AND (
                e.moduleId = a.moduleId
                OR (m.courseId IS NOT NULL AND e.courseId = m.courseId)
              )
        )
    ) OR (
        a.moduleId IS NULL AND EXISTS (
            SELECT 1
            FROM enrollments e
            JOIN courses c2 ON c2.courseId = e.courseId
            WHERE e.userId = ?
              AND c2.professorId = a.createdBy
        )
    )
    ORDER BY a.createdAt DESC");
$assignmentResults->bind_param('ss', $userId, $userId);
$assignmentResults->execute();
$assignmentRows = $assignmentResults->get_result();
$assignments = [];
$assignmentLookup = [];
while ($row = $assignmentRows->fetch_assoc()) {
    $assignments[] = $row;
    $assignmentLookup[(int)$row['assignmentId']] = $row;
}
$assignmentResults->close();

$submissionStmt = $conn->prepare("SELECT * FROM submissions WHERE userId = ?");
$submissionStmt->bind_param('s', $userId);
$submissionStmt->execute();
$submissionResults = $submissionStmt->get_result();
$submissions = [];
while ($row = $submissionResults->fetch_assoc()) {
    $submissions[$row['assignmentId']] = $row;
}
$submissionStmt->close();

$historyStmt = $conn->prepare("SELECT h.*, u.email AS gradedByEmail FROM submission_history h LEFT JOIN users u ON u.userId = h.gradedBy WHERE h.userId = ? ORDER BY h.gradedAt DESC");
$historyStmt->bind_param('s', $userId);
$historyStmt->execute();
$historyResults = $historyStmt->get_result();
$submissionHistory = [];
$historyByAssignment = [];
while ($row = $historyResults->fetch_assoc()) {
    $submissionHistory[] = $row;
    if (!empty($row['assignmentId']) && !isset($historyByAssignment[$row['assignmentId']])) {
        $historyByAssignment[$row['assignmentId']] = $row;
    }
}
$historyStmt->close();

$pendingAssignments = false;
foreach ($assignments as $assignment) {
    if (!isset($submissions[$assignment['assignmentId']]) && !isset($historyByAssignment[$assignment['assignmentId']])) {
        $pendingAssignments = true;
        break;
    }
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - LMS Infotech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-a: #1f6feb;
            --brand-b: #00a6fb;
            --ink: #182033;
            --muted: #5d6b86;
            --card: #ffffff;
            --surface: #f4f8ff;
            --line: #e3eaf7;
            --ok-bg: #dff7e6;
            --ok-ink: #145b2e;
            --pending-bg: #ffe8d9;
            --pending-ink: #8f3f0f;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top right, #e7f1ff 0%, #f3f8ff 40%, #f9fbff 100%);
            color: var(--ink);
            scroll-behavior: smooth;
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 28px;
        }

        .header {
            background: linear-gradient(135deg, #0a4db6 0%, #0e67e6 60%, #16a2f8 100%);
            color: white;
            padding: 30px 34px;
            border-radius: 22px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 22px 45px rgba(15, 86, 191, 0.28);
            position: relative;
            overflow: hidden;
        }

        .header::after {
            content: "";
            position: absolute;
            width: 300px;
            height: 300px;
            right: -120px;
            top: -140px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.14);
        }

        .header h1 {
            font-size: 30px;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .header p {
            opacity: 0.95;
            font-size: 14px;
            position: relative;
            z-index: 1;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 10px 20px;
            border: 2px solid white;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            position: relative;
            z-index: 1;
        }

        .logout-btn:hover {
            background: white;
            color: #0e67e6;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 26px;
        }

        .card {
            background: var(--card);
            padding: 25px;
            border-radius: 18px;
            border: 1px solid var(--line);
            box-shadow: 0 10px 30px rgba(26, 52, 91, 0.08);
            transition: all 0.3s;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 36px rgba(26, 52, 91, 0.12);
        }

        .card h3 {
            color: #1f4f9d;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .card p {
            color: var(--muted);
            line-height: 1.6;
        }

        .stat-box {
            background: linear-gradient(135deg, #0c5bd3 0%, #17a1f7 100%);
            color: white;
            padding: 25px;
            border-radius: 18px;
            text-align: center;
            box-shadow: 0 14px 34px rgba(15, 106, 220, 0.28);
        }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }

        .courses-section {
            background: var(--card);
            padding: 28px;
            border-radius: 18px;
            border: 1px solid var(--line);
            box-shadow: 0 10px 30px rgba(26, 52, 91, 0.08);
            margin-bottom: 20px;
        }

        .courses-section h2 {
            margin-bottom: 20px;
            color: var(--ink);
            font-size: 22px;
        }

        .enrollments-table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
        }

        .enrollments-table th {
            background: #edf4ff;
            padding: 14px;
            text-align: left;
            font-weight: 600;
            color: #385384;
            border-bottom: 2px solid #dce8fb;
        }

        .enrollments-table td {
            padding: 14px;
            border-bottom: 1px solid #edf2fb;
        }

        .enrollments-table tr:hover {
            background: #f7faff;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-active {
            background: var(--ok-bg);
            color: var(--ok-ink);
        }

        .badge-inactive {
            background: var(--pending-bg);
            color: var(--pending-ink);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #7b8aa8;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .message-list {
            margin-bottom: 20px;
        }

        .message {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 500;
        }

        .message.success {
            background: #e6f8ed;
            color: #185a2f;
            border: 1px solid #bdeacd;
        }

        .message.error {
            background: #fde8e8;
            color: #8a1d1d;
            border: 1px solid #f7c4c4;
        }

        .btn-primary {
            margin-top: 15px;
            padding: 11px 20px;
            background: linear-gradient(135deg, #0c5bd3 0%, #17a1f7 100%);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            box-shadow: 0 8px 20px rgba(12, 91, 211, 0.25);
        }

        a.btn-primary {
            display: inline-block;
            text-decoration: none;
        }

        .action-link {
            color: #0c5bd3;
            text-decoration: none;
            font-weight: 600;
        }

        .action-link:hover {
            text-decoration: underline;
        }

        .submission-item {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 18px;
            background: var(--surface);
            margin-bottom: 14px;
        }

        .submission-item h3 {
            margin-bottom: 8px;
            color: #153a76;
        }

        .submission-item p {
            color: var(--muted);
            margin-bottom: 10px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 12px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #39537f;
        }

        .form-group textarea,
        .form-group input[type="file"] {
            width: 100%;
            border: 1px solid #cfdbf1;
            border-radius: 10px;
            padding: 11px 12px;
            background: #fff;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .course-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 14px;
        }

        .course-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            background: var(--surface);
        }

        .course-card h3 {
            color: #153a76;
            margin-bottom: 8px;
            font-size: 17px;
        }

        .course-meta {
            font-size: 13px;
            color: #5d6b86;
            margin-bottom: 10px;
        }

        .status-chip {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 8px;
        }

        .chip-enrolled {
            background: #dff7e6;
            color: #145b2e;
        }

        .chip-pending {
            background: #fff2dd;
            color: #83531a;
        }

        @media (max-width: 1024px) {
            .container {
                padding: 20px;
            }

            .dashboard-grid {
                grid-template-columns: repeat(2, minmax(250px, 1fr));
            }

            .enrollments-table {
                min-width: 760px;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }

            .container {
                padding: 16px;
            }

            .header h1 {
                font-size: 24px;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .courses-section {
                padding: 18px;
                overflow-x: auto;
            }

            .enrollments-table {
                min-width: 700px;
            }

            .course-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .header {
                padding: 20px;
                border-radius: 16px;
            }

            .header h1 {
                font-size: 20px;
            }

            .logout-btn {
                width: 100%;
                text-align: center;
            }

            .stat-box, .card {
                padding: 18px;
            }

            .submission-item {
                padding: 14px;
            }

            .btn-primary {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Welcome back, <?php echo htmlspecialchars($user['userId']); ?>! ðŸ‘‹</h1>
                <p>Your learning dashboard</p>
            </div>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>

        <?php if (!empty($messages)): ?>
            <div class="message-list">
                <?php foreach ($messages as $notice): ?>
                    <div class="message <?php echo $notice['type'] === 'success' ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars($notice['text']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <div class="stat-box">
                <div class="stat-number"><?php echo $enrollmentCount; ?></div>
                <div class="stat-label">Courses Enrolled</div>
            </div>

            <div class="card">
                <h3><i class="fas fa-user-circle"></i> Your Profile</h3>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email'] ?? 'Not provided'); ?></p>
                <p><strong>Role:</strong> <?php echo htmlspecialchars($roleLabel); ?></p>
                <p><strong>User ID:</strong> <?php echo htmlspecialchars($user['userId']); ?></p>
            </div>

            <div class="card">
                <h3><i class="fas fa-book"></i> Learning</h3>
                <p>Continue learning and improve your skills with our comprehensive courses.</p>
                <a href="#available-courses" class="btn-primary">Browse Courses</a>
            </div>
        </div>

        <div class="courses-section">
            <h2>Lessons & Activities</h2>
            <?php if (!empty($moduleResources)): ?>
                <div class="course-grid">
                    <?php foreach ($moduleResources as $resource): ?>
                        <div class="course-card">
                            <h3><?php echo htmlspecialchars($resource['title']); ?></h3>
                            <div class="course-meta">
                                <?php echo htmlspecialchars(ucfirst($resource['resourceType'])); ?>
                            </div>
                            <p><?php echo nl2br(htmlspecialchars($resource['description'] ?: 'No description available.')); ?></p>
                            <p class="course-meta">Uploaded by: <?php echo htmlspecialchars($resource['uploaderEmail'] ?: 'Admin'); ?></p>
                            <?php if (!empty($resource['filePath'])): ?>
                                <a href="<?php echo htmlspecialchars($resource['filePath']); ?>" class="btn-primary" target="_blank">Download File</a>
                            <?php else: ?>
                                <span class="status-chip chip-enrolled">Text Resource</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book-reader"></i>
                    <h3>No lessons yet</h3>
                    <p>Your admin has not uploaded lessons or activities yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="courses-section">
            <h2>Available Assignments</h2>
            <?php if (!empty($assignments)): ?>
                <table class="enrollments-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Grade</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment):
                            $submission = $submissions[$assignment['assignmentId']] ?? null;
                            $historySubmission = $historyByAssignment[$assignment['assignmentId']] ?? null;
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($assignment['title']); ?></td>
                                <td>
                                    <?php if ($historySubmission): ?>
                                        <span class="badge-active">Graded</span>
                                    <?php elseif ($submission): ?>
                                        <span class="badge-active">Submitted</span>
                                    <?php else: ?>
                                        <span class="badge-inactive">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($historySubmission['grade'] ?? ($submission['grade'] ?? 'Not graded')); ?></td>
                                <td>
                                    <?php if ($historySubmission): ?>
                                        <a class="action-link" href="#history">View History</a>
                                    <?php elseif ($submission): ?>
                                        <a class="action-link" href="#submission-<?php echo intval($assignment['assignmentId']); ?>">View</a>
                                    <?php else: ?>
                                        <a class="action-link" href="#submit-<?php echo intval($assignment['assignmentId']); ?>">Submit</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No assignments available yet</h3>
                    <p>Your professor has not posted assignments yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="courses-section">
            <h2>My Courses</h2>
            <?php if ($enrollmentCount > 0): ?>
                <table class="enrollments-table">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Date Enrolled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $enrollmentsStmt = $conn->prepare("SELECT e.enrollmentDate, c.courseName FROM enrollments e LEFT JOIN courses c ON e.courseId = c.courseId WHERE e.userId = ? ORDER BY e.enrollmentDate DESC");
                        $enrollmentsStmt->bind_param('s', $userId);
                        $enrollmentsStmt->execute();
                        $enrollments = $enrollmentsStmt->get_result();
                        while ($enrollment = $enrollments->fetch_assoc()): 
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($enrollment['courseName'] ?: 'Unassigned Course'); ?></strong></td>
                                <td><span class="status-badge badge-active">Active</span></td>
                                <td>
                                    <div style="width: 100px; height: 6px; background: #e0e0e0; border-radius: 3px; overflow: hidden;">
                                        <div style="width: 65%; height: 100%; background: linear-gradient(90deg, #667eea, #764ba2);"></div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($enrollment['enrollmentDate'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <?php $enrollmentsStmt->close(); ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Courses Yet</h3>
                    <p>You haven't enrolled in any courses yet. Browse our courses to get started!</p>
                    <a href="#available-courses" class="btn-primary" style="margin-top: 20px;">Browse Courses</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="courses-section" id="available-courses">
            <h2>Available Courses</h2>
            <?php if (!empty($availableCourses)): ?>
                <div class="course-grid">
                    <?php foreach ($availableCourses as $course): ?>
                        <?php
                        $courseId = (int)$course['courseId'];
                        $isEnrolled = isset($enrolledCourseIds[$courseId]);
                        $isPending = isset($pendingRequestCourseIds[$courseId]);
                        ?>
                        <div class="course-card">
                            <h3><?php echo htmlspecialchars($course['courseName']); ?></h3>
                            <div class="course-meta">Instructor: <?php echo htmlspecialchars($course['professorEmail'] ?? 'Unassigned'); ?></div>
                            <p><?php echo nl2br(htmlspecialchars($course['description'] ?? 'No description available.')); ?></p>

                            <?php if ($isEnrolled): ?>
                                <span class="status-chip chip-enrolled">Already Enrolled</span>
                            <?php elseif ($isPending): ?>
                                <span class="status-chip chip-pending">Request Pending Approval</span>
                            <?php else: ?>
                                <form method="POST" style="margin-top: 10px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="request_course_enrollment">
                                    <input type="hidden" name="courseId" value="<?php echo $courseId; ?>">
                                    <button type="submit" class="btn-primary" style="margin-top: 0;">Request Enrollment</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h3>No courses available</h3>
                    <p>Please check again later.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($assignments) && $pendingAssignments): ?>
            <div class="courses-section">
                <h2>Submit Assignment Answers</h2>
                <?php foreach ($assignments as $assignment):
                    $submission = $submissions[$assignment['assignmentId']] ?? null;
                    $historySubmission = $historyByAssignment[$assignment['assignmentId']] ?? null;
                    if ($submission || $historySubmission) {
                        continue;
                    }
                ?>
                    <div class="submission-item" id="submit-<?php echo intval($assignment['assignmentId']); ?>">
                        <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                        <p><?php echo nl2br(htmlspecialchars($assignment['description'])); ?></p>
                        <?php if ($assignment['filePath']): ?>
                            <p><strong>Assignment File:</strong> <a href="<?php echo htmlspecialchars($assignment['filePath']); ?>" target="_blank">Download</a></p>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="submit_assignment">
                            <input type="hidden" name="assignmentId" value="<?php echo intval($assignment['assignmentId']); ?>">
                            <div class="form-group">
                                <label for="submissionContent-<?php echo intval($assignment['assignmentId']); ?>">Answer or Notes</label>
                                <textarea id="submissionContent-<?php echo intval($assignment['assignmentId']); ?>" name="submissionContent" placeholder="Write your submission notes..."></textarea>
                            </div>
                            <div class="form-group">
                                <label for="submissionFile-<?php echo intval($assignment['assignmentId']); ?>">Upload File</label>
                                <input type="file" id="submissionFile-<?php echo intval($assignment['assignmentId']); ?>" name="submissionFile" accept=".pdf,.docx,.pptx,.zip,.jpg,.jpeg,.png,.txt">
                            </div>
                            <button type="submit" class="btn-primary" style="margin-top: 2px;">Submit Answer</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="courses-section" id="history">
            <h2>Assignment History</h2>
            <?php if (!empty($submissionHistory)): ?>
                <table class="enrollments-table">
                    <thead>
                        <tr>
                            <th>Assignment</th>
                            <th>Instructor</th>
                            <th>Submitted</th>
                            <th>Graded</th>
                            <th>Grade</th>
                            <th>Feedback</th>
                            <th>File</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissionHistory as $submission):
                            $assignmentTitle = 'Unknown Assignment';
                            $instructorName = '-';
                            $historyAssignmentId = intval($submission['assignmentId'] ?? 0);
                            if ($historyAssignmentId > 0 && isset($assignmentLookup[$historyAssignmentId])) {
                                $assignmentTitle = $assignmentLookup[$historyAssignmentId]['title'];
                                $instructorName = $assignmentLookup[$historyAssignmentId]['instructorEmail'] ?? '-';
                            } elseif (!empty($submission['gradedByEmail'])) {
                                $instructorName = $submission['gradedByEmail'];
                            }
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($assignmentTitle); ?></td>
                                <td><?php echo htmlspecialchars($instructorName); ?></td>
                                <td><?php echo htmlspecialchars($submission['submittedAt'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($submission['gradedAt'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($submission['grade'] ?: 'Not graded'); ?></td>
                                <td><?php echo nl2br(htmlspecialchars($submission['feedback'] ?: '-')); ?></td>
                                <td>
                                    <?php if (!empty($submission['filePath'])): ?>
                                        <a href="<?php echo htmlspecialchars($submission['filePath']); ?>" target="_blank">Download</a>
                                    <?php else: ?>
                                        <span class="text-muted">No file</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-active">Archived</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h3>No assignment history yet</h3>
                    <p>Your submitted work will show here after you submit assignments.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>
