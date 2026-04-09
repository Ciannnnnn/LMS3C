<?php
session_start();
include 'config.php';

if (!isset($_SESSION['userId']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $conn->close();
    header('Location: admin_dashboard.php?message=' . urlencode('Security token expired. Please try again.'));
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$allowedRoles = ['user', 'admin', 'professor'];

if ($action === 'add_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = trim($_POST['userId'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? 'user');
    $password = trim($_POST['password'] ?? '');

    if ($userId && $email && $role && $password && filter_var($email, FILTER_VALIDATE_EMAIL) && in_array($role, $allowedRoles, true)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (userId, email, userPassword, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $userId, $email, $hash, $role);
        if ($stmt->execute()) {
            $message = 'User created successfully.';
        } else {
            $message = 'Error creating user: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = 'Valid user ID, email, role, and password are required to create a user.';
    }
}

if ($action === 'edit_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $originalUserId = trim($_POST['originalUserId'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? 'user');
    $password = trim($_POST['password'] ?? '');

    if ($originalUserId && $email && $role && filter_var($email, FILTER_VALIDATE_EMAIL) && in_array($role, $allowedRoles, true)) {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET email = ?, role = ?, userPassword = ? WHERE userId = ?");
            $stmt->bind_param('ssss', $email, $role, $hash, $originalUserId);
        } else {
            $stmt = $conn->prepare("UPDATE users SET email = ?, role = ? WHERE userId = ?");
            $stmt->bind_param('sss', $email, $role, $originalUserId);
        }
        if ($stmt->execute()) {
            $message = 'User updated successfully.';
        } else {
            $message = 'Error updating user: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = 'Valid email and role are required.';
    }
}

if ($action === 'delete_user' && isset($_POST['userId'])) {
    $userId = $_POST['userId'];
    if ($userId === $_SESSION['userId']) {
        $message = 'You cannot delete your own account.';
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE userId = ?");
        $stmt->bind_param('s', $userId);
        if ($stmt->execute()) {
            $message = 'User deleted successfully.';
        } else {
            $message = 'Error deleting user: ' . $stmt->error;
        }
        $stmt->close();
    }
}

if ($action === 'delete_module' && isset($_POST['moduleId'])) {
    $moduleId = intval($_POST['moduleId']);
    $filesToDelete = [];

    $assignmentFilesStmt = $conn->prepare("SELECT filePath FROM assignments WHERE moduleId = ? AND filePath IS NOT NULL AND filePath <> ''");
    $assignmentFilesStmt->bind_param('i', $moduleId);
    $assignmentFilesStmt->execute();
    $assignmentFilesResult = $assignmentFilesStmt->get_result();
    while ($row = $assignmentFilesResult->fetch_assoc()) {
        $filesToDelete[] = $row['filePath'];
    }
    $assignmentFilesStmt->close();

    $submissionFilesStmt = $conn->prepare("SELECT s.filePath
        FROM submissions s
        JOIN assignments a ON a.assignmentId = s.assignmentId
        WHERE a.moduleId = ? AND s.filePath IS NOT NULL AND s.filePath <> ''");
    $submissionFilesStmt->bind_param('i', $moduleId);
    $submissionFilesStmt->execute();
    $submissionFilesResult = $submissionFilesStmt->get_result();
    while ($row = $submissionFilesResult->fetch_assoc()) {
        $filesToDelete[] = $row['filePath'];
    }
    $submissionFilesStmt->close();

    $resourceFilesStmt = $conn->prepare("SELECT filePath FROM module_resources WHERE moduleId = ? AND filePath IS NOT NULL AND filePath <> ''");
    $resourceFilesStmt->bind_param('i', $moduleId);
    $resourceFilesStmt->execute();
    $resourceFilesResult = $resourceFilesStmt->get_result();
    while ($row = $resourceFilesResult->fetch_assoc()) {
        $filesToDelete[] = $row['filePath'];
    }
    $resourceFilesStmt->close();

    $stmt = $conn->prepare("DELETE FROM modules WHERE moduleId = ?");
    $stmt->bind_param('i', $moduleId);
    if ($stmt->execute()) {
        foreach (array_unique($filesToDelete) as $filePath) {
            if (strpos($filePath, 'uploads/') === 0) {
                $localPath = __DIR__ . '/' . $filePath;
                if (is_file($localPath)) {
                    @unlink($localPath);
                }
            }
        }
        $message = 'Module deleted successfully.';
    } else {
        $message = 'Error deleting module: ' . $stmt->error;
    }
    $stmt->close();
}

if ($action === 'add_module' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $moduleName = trim($_POST['moduleName'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $courseId = intval($_POST['courseId'] ?? 0);

    if ($moduleName === '' || $courseId <= 0) {
        $message = 'Module name and course are required.';
    } else {
        $courseCheckStmt = $conn->prepare("SELECT courseId FROM courses WHERE courseId = ? LIMIT 1");
        $courseCheckStmt->bind_param('i', $courseId);
        $courseCheckStmt->execute();
        $courseExists = $courseCheckStmt->get_result()->num_rows > 0;
        $courseCheckStmt->close();

        if (!$courseExists) {
            $message = 'Selected course does not exist.';
        } else {
            $stmt = $conn->prepare("INSERT INTO modules (moduleName, description, courseId) VALUES (?, ?, ?)");
            $stmt->bind_param('ssi', $moduleName, $description, $courseId);
            if ($stmt->execute()) {
                $message = 'Module created successfully.';
            } else {
                $message = 'Error creating module: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

if ($action === 'add_module_resource' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $resourceType = trim($_POST['resourceType'] ?? 'lesson');
    $allowedResourceTypes = ['lesson', 'activity', 'material'];
    $moduleIdParam = null;

    if ($title === '' || !in_array($resourceType, $allowedResourceTypes, true)) {
        $message = 'Title and resource type are required.';
    } else {
        $filePath = null;
        if (!empty($_FILES['resourceFile']['name'])) {
            $uploadResult = storeUploadedFile($_FILES['resourceFile'], __DIR__ . '/uploads/module_resources', 'uploads/module_resources');
            if ($uploadResult['success']) {
                $filePath = $uploadResult['filePath'];
            } else {
                $message = $uploadResult['message'];
            }
        }

        if ($message === '') {
            $createdBy = $_SESSION['userId'];
            $stmt = $conn->prepare("INSERT INTO module_resources (moduleId, title, description, resourceType, filePath, createdBy) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssss', $moduleIdParam, $title, $description, $resourceType, $filePath, $createdBy);
            if ($stmt->execute()) {
                $message = 'Lesson uploaded successfully.';
            } else {
                $message = 'Error uploading lesson: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

if ($action === 'delete_module_resource' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $resourceId = intval($_POST['resourceId'] ?? 0);

    if ($resourceId <= 0) {
        $message = 'Invalid module resource.';
    } else {
        $findStmt = $conn->prepare("SELECT filePath FROM module_resources WHERE resourceId = ?");
        $findStmt->bind_param('i', $resourceId);
        $findStmt->execute();
        $resourceRow = $findStmt->get_result()->fetch_assoc();
        $findStmt->close();

        if (!$resourceRow) {
            $message = 'Module resource not found.';
        } else {
            $stmt = $conn->prepare("DELETE FROM module_resources WHERE resourceId = ?");
            $stmt->bind_param('i', $resourceId);
            if ($stmt->execute()) {
                if (!empty($resourceRow['filePath']) && strpos($resourceRow['filePath'], 'uploads/module_resources/') === 0) {
                    $localPath = __DIR__ . '/' . $resourceRow['filePath'];
                    if (is_file($localPath)) {
                        @unlink($localPath);
                    }
                }
                $message = 'Module resource deleted successfully.';
            } else {
                $message = 'Error deleting module resource: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

if ($action === 'delete_course' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = intval($_POST['courseId'] ?? 0);
    if ($courseId > 0) {
        $stmt = $conn->prepare("DELETE FROM courses WHERE courseId = ?");
        $stmt->bind_param('i', $courseId);
        if ($stmt->execute()) {
            $message = 'Course deleted successfully. Modules unlinked.';
        } else {
            $message = 'Error deleting course: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = 'Invalid course.';
    }
}

if ($action === 'add_course' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseName = trim($_POST['courseName'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $professorId = trim($_POST['professorId'] ?? '');
    $professorIdParam = $professorId !== '' ? $professorId : null;

    if ($courseName) {
        if ($professorIdParam !== null) {
            $checkStmt = $conn->prepare("SELECT userId FROM users WHERE userId = ? AND role = 'professor'");
            $checkStmt->bind_param('s', $professorIdParam);
            $checkStmt->execute();
            $isValidProfessor = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if (!$isValidProfessor) {
                $message = 'Selected professor is invalid.';
            }
        }

        if ($message === '') {
            $stmt = $conn->prepare("INSERT INTO courses (courseName, description, professorId) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $courseName, $description, $professorIdParam);
            if ($stmt->execute()) {
                $message = 'Course created successfully.';
            } else {
                $message = 'Error creating course: ' . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $message = 'Course name is required.';
    }
}

if ($action === 'assign_course_professor' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = intval($_POST['courseId'] ?? 0);
    $professorId = trim($_POST['professorId'] ?? '');
    $professorIdParam = $professorId !== '' ? $professorId : null;

    if ($courseId > 0) {
        if ($professorIdParam !== null) {
            $checkStmt = $conn->prepare("SELECT userId FROM users WHERE userId = ? AND role = 'professor'");
            $checkStmt->bind_param('s', $professorIdParam);
            $checkStmt->execute();
            $isValidProfessor = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if (!$isValidProfessor) {
                $message = 'Invalid professor selected.';
            }
        }

        if ($message === '') {
            $stmt = $conn->prepare("UPDATE courses SET professorId = ? WHERE courseId = ?");
            $stmt->bind_param('si', $professorIdParam, $courseId);
            if ($stmt->execute()) {
                $message = $professorIdParam === null
                    ? 'Professor unassigned from course successfully.'
                    : 'Professor assigned to course successfully.';
            } else {
                $message = 'Error assigning professor: ' . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $message = 'Course is required.';
    }
}

if ($action === 'approve_enrollment_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = intval($_POST['requestId'] ?? 0);

    if ($requestId > 0) {
        $fetchStmt = $conn->prepare("SELECT userId, courseId FROM enrollment_requests WHERE requestId = ? AND status = 'pending'");
        $fetchStmt->bind_param('i', $requestId);
        $fetchStmt->execute();
        $request = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();

        if ($request) {
            $userId = $request['userId'];
            $courseId = intval($request['courseId']);

            $existsStmt = $conn->prepare("SELECT enrollmentId FROM enrollments WHERE userId = ? AND courseId = ? LIMIT 1");
            $existsStmt->bind_param('si', $userId, $courseId);
            $existsStmt->execute();
            $alreadyEnrolled = $existsStmt->get_result()->num_rows > 0;
            $existsStmt->close();

            if (!$alreadyEnrolled) {
                $enrollStmt = $conn->prepare("INSERT INTO enrollments (userId, courseId, moduleId) VALUES (?, ?, NULL)");
                $enrollStmt->bind_param('si', $userId, $courseId);
                $enrollStmt->execute();
                $enrollStmt->close();
            }

            $reviewer = $_SESSION['userId'];
            $updateStmt = $conn->prepare("UPDATE enrollment_requests SET status = 'approved', reviewedAt = NOW(), reviewedBy = ? WHERE requestId = ?");
            $updateStmt->bind_param('si', $reviewer, $requestId);
            if ($updateStmt->execute()) {
                $message = 'Enrollment request approved successfully.';
            } else {
                $message = 'Unable to approve request: ' . $updateStmt->error;
            }
            $updateStmt->close();
        } else {
            $message = 'Request not found or already reviewed.';
        }
    } else {
        $message = 'Invalid request ID.';
    }
}

if ($action === 'reject_enrollment_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = intval($_POST['requestId'] ?? 0);

    if ($requestId > 0) {
        $reviewer = $_SESSION['userId'];
        $updateStmt = $conn->prepare("UPDATE enrollment_requests SET status = 'rejected', reviewedAt = NOW(), reviewedBy = ? WHERE requestId = ? AND status = 'pending'");
        $updateStmt->bind_param('si', $reviewer, $requestId);
        if ($updateStmt->execute() && $updateStmt->affected_rows > 0) {
            $message = 'Enrollment request rejected.';
        } else {
            $message = 'Request not found or already reviewed.';
        }
        $updateStmt->close();
    } else {
        $message = 'Invalid request ID.';
    }
}

if ($action === 'update_submission_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $historyId = intval($_POST['historyId'] ?? 0);
    $grade = trim($_POST['grade'] ?? '');
    $feedback = trim($_POST['feedback'] ?? '');

    if ($historyId > 0) {
        $stmt = $conn->prepare("UPDATE submission_history SET grade = ?, feedback = ? WHERE historyId = ?");
        $stmt->bind_param('ssi', $grade, $feedback, $historyId);
        if ($stmt->execute()) {
            $message = 'Submission history updated successfully.';
        } else {
            $message = 'Unable to update submission history: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = 'Invalid history record.';
    }
}

if ($action === 'delete_submission_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $historyId = intval($_POST['historyId'] ?? 0);

    if ($historyId > 0) {
        $filePath = null;
        $findStmt = $conn->prepare("SELECT filePath FROM submission_history WHERE historyId = ?");
        $findStmt->bind_param('i', $historyId);
        $findStmt->execute();
        $row = $findStmt->get_result()->fetch_assoc();
        if ($row) {
            $filePath = $row['filePath'];
        }
        $findStmt->close();

        $stmt = $conn->prepare("DELETE FROM submission_history WHERE historyId = ?");
        $stmt->bind_param('i', $historyId);
        if ($stmt->execute()) {
            if (!empty($filePath) && strpos($filePath, 'uploads/submissions/') === 0) {
                $localPath = __DIR__ . '/' . $filePath;
                if (is_file($localPath)) {
                    @unlink($localPath);
                }
            }
            $message = 'Submission history deleted successfully.';
        } else {
            $message = 'Unable to delete submission history: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = 'Invalid history record.';
    }
}

$conn->close();
header('Location: admin_dashboard.php?message=' . urlencode($message));
exit;

