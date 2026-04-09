<?php
session_start();
include 'config.php';

if (!isset($_SESSION['userId'])) {
    header('Location: index.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$userId = $_SESSION['userId'];
$sql = "SELECT userId, email, role FROM users WHERE userId = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

$usersCount = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$enrollmentsCount = $conn->query("SELECT COUNT(*) as count FROM enrollments")->fetch_assoc()['count'];
$modulesCount = $conn->query("SELECT COUNT(*) as count FROM modules")->fetch_assoc()['count'];
$coursesCount = $conn->query("SELECT COUNT(*) as count FROM courses")->fetch_assoc()['count'];

$message = trim($_GET['message'] ?? '');
$userList = $conn->query("SELECT userId, email, role, createdAt FROM users ORDER BY createdAt DESC");
$courseList = $conn->query("SELECT c.*, u.email AS professorEmail, (SELECT COUNT(*) FROM modules WHERE courseId = c.courseId) as moduleCount, (SELECT COUNT(*) FROM enrollments WHERE courseId = c.courseId) as studentCount FROM courses c LEFT JOIN users u ON u.userId = c.professorId ORDER BY c.createdAt DESC");
$moduleList = $conn->query("SELECT m.moduleId, m.moduleName, m.description, m.createdAt, c.courseName, c.courseId, u.email AS professorEmail FROM modules m LEFT JOIN courses c ON c.courseId = m.courseId LEFT JOIN users u ON u.userId = c.professorId ORDER BY m.createdAt DESC");
$moduleCourseOptions = $conn->query("SELECT courseId, courseName FROM courses ORDER BY courseName");
$moduleResourceList = $conn->query("SELECT r.resourceId, r.title, r.description, r.resourceType, r.filePath, r.createdAt, u.email AS createdByEmail FROM module_resources r LEFT JOIN users u ON u.userId = r.createdBy ORDER BY r.createdAt DESC");
$loginHistoryList = $conn->query("SELECT loginId, userId, email, role, ipAddress, userAgent, signedInAt FROM login_history ORDER BY signedInAt DESC LIMIT 200");
$recentActivity = $conn->query("SELECT CONCAT(u.email, ' submitted ', COALESCE(a.title, 'an assignment')) AS action, s.submissionDate AS date FROM submissions s LEFT JOIN users u ON u.userId = s.userId LEFT JOIN assignments a ON a.assignmentId = s.assignmentId UNION SELECT CONCAT(u.email, ' created assignment ', a.title) AS action, a.createdAt AS date FROM assignments a LEFT JOIN users u ON u.userId = a.createdBy ORDER BY date DESC LIMIT 5");
$studentProgress = $conn->query("SELECT
    u.userId,
    u.email,
    COALESCE(m.moduleName, c.courseName, 'Unassigned') AS learningItem,
    e.courseId,
    e.enrollmentDate
    FROM enrollments e
    JOIN users u ON e.userId = u.userId
    LEFT JOIN modules m ON e.moduleId = m.moduleId
    LEFT JOIN courses c ON e.courseId = c.courseId
    WHERE u.role = 'user'
    ORDER BY e.enrollmentDate DESC
    LIMIT 50");
$submissionHistoryList = $conn->query("SELECT h.historyId, h.userId, h.assignmentId, h.grade, h.feedback, h.submittedAt, h.gradedAt, h.filePath, u.email AS studentEmail, a.title AS assignmentTitle, g.email AS gradedByEmail FROM submission_history h LEFT JOIN users u ON u.userId = h.userId LEFT JOIN assignments a ON a.assignmentId = h.assignmentId LEFT JOIN users g ON g.userId = h.gradedBy ORDER BY h.gradedAt DESC LIMIT 200");
$pendingEnrollmentRequests = $conn->query("SELECT r.requestId, r.userId, r.courseId, r.requestedAt, u.email AS studentEmail, c.courseName FROM enrollment_requests r JOIN users u ON u.userId = r.userId JOIN courses c ON c.courseId = r.courseId WHERE r.status = 'pending' ORDER BY r.requestedAt ASC");
$pendingRequestCount = $pendingEnrollmentRequests ? $pendingEnrollmentRequests->num_rows : 0;
$professorListResult = $conn->query("SELECT userId, email FROM users WHERE role = 'professor' ORDER BY email");
$professorOptions = [];
if ($professorListResult) {
    while ($prof = $professorListResult->fetch_assoc()) {
        $professorOptions[] = $prof;
    }
}

function roleLabel($role) {
    return $role === 'user' ? 'Student' : ucfirst($role);
}

$csrfToken = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - LMS Infotech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
        }

        .sidebar-logo {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
        }

        .sidebar-logo h2 {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .sidebar-logo p {
            font-size: 12px;
            opacity: 0.8;
            margin-top: 5px;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu li {
            margin: 15px 0;
        }

        .sidebar-menu a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-radius: 8px;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.2);
            padding-left: 25px;
        }

        .sidebar-menu i {
            margin-right: 12px;
            font-size: 16px;
            width: 20px;
        }

        .main-content {
            margin-left: 280px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .header {
            background: white;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #f0f0f0;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header-title h1 {
            font-size: 24px;
            color: #667eea;
            font-weight: 700;
        }

        .header-title p {
            font-size: 12px;
            color: #999;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 15px 10px 35px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            width: 250px;
            font-size: 13px;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 10px rgba(102, 126, 234, 0.2);
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 15px;
            background: #f5f5f5;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .user-profile:hover {
            background: #e8e8e8;
        }

        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .user-info p {
            font-size: 13px;
            font-weight: 500;
            color: #333;
        }

        .user-info span {
            font-size: 11px;
            color: #999;
        }

        .content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .stat-card.users {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(102, 126, 234, 0.02));
        }

        .stat-card.courses {
            background: linear-gradient(135deg, rgba(35, 165, 213, 0.05), rgba(35, 165, 213, 0.02));
        }

        .stat-card.modules {
            background: linear-gradient(135deg, rgba(35, 166, 213, 0.05), rgba(0, 153, 255, 0.02));
        }

        .stat-card.students {
            background: linear-gradient(135deg, rgba(240, 147, 251, 0.05), rgba(240, 147, 251, 0.02));
        }

        .stat-card.progress {
            background: linear-gradient(135deg, rgba(79, 172, 254, 0.05), rgba(79, 172, 254, 0.02));
        }

        .stat-icon {
            font-size: 28px;
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            color: white;
        }

        .stat-card.users .stat-icon {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .stat-card.courses .stat-icon {
            background: linear-gradient(135deg, #23a6d5, #0099ff);
        }

        .stat-card.modules .stat-icon {
            background: linear-gradient(135deg, #23a6d5, #0099ff);
        }

        .stat-card.students .stat-icon {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        .stat-card.progress .stat-icon {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }

        .stat-label {
            font-size: 13px;
            color: #999;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }

        .stat-change {
            font-size: 12px;
            color: #28a745;
            font-weight: 600;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        .section-title {
            font-size: 22px;
            font-weight: 700;
            color: #333;
            margin-bottom: 25px;
        }

        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead {
            background: linear-gradient(135deg, #f5f7fa, #f0f0f0);
        }

        .table th {
            padding: 18px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td {
            padding: 16px 18px;
            border-top: 1px solid #f0f0f0;
            font-size: 14px;
            color: #666;
        }

        .table tbody tr:hover {
            background: #f9f9f9;
        }

        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge.active {
            background: #d4edda;
            color: #155724;
        }

        .badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            margin-right: 5px;
            transition: all 0.3s;
        }

        .action-btn.edit {
            background: #e7f3ff;
            color: #0066cc;
        }

        .action-btn.edit:hover {
            background: #cce5ff;
        }

        .action-btn.delete {
            background: #ffe7e7;
            color: #cc0000;
        }

        .action-btn.delete:hover {
            background: #ffcccc;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #d0d0d0;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .chart-placeholder {
            height: 300px;
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f0f0 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 16px;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(220px, 1fr));
            }

            .search-box input {
                width: 200px;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 90px;
                padding: 22px 8px;
            }

            .sidebar-logo p,
            .sidebar-menu span {
                display: none;
            }

            .sidebar-menu a {
                justify-content: center;
                padding: 12px 10px;
            }

            .sidebar-menu i {
                margin-right: 0;
            }

            .main-content {
                margin-left: 90px;
            }

            .header {
                padding: 16px 20px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 20px 0;
            }

            .sidebar-logo {
                margin-bottom: 20px;
            }

            .sidebar-logo h2 {
                font-size: 16px;
            }

            .sidebar-logo p {
                display: none;
            }

            .sidebar-menu a {
                justify-content: center;
                padding: 12px 10px;
            }

            .sidebar-menu i {
                margin-right: 0;
            }

            .sidebar-menu span {
                display: none;
            }

            .main-content {
                margin-left: 70px;
            }

            .header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .search-box input {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 15px;
            }

            .table-container {
                overflow-x: auto;
            }

            .table {
                min-width: 720px;
            }
        }

        @media (max-width: 576px) {
            .header-title h1 {
                font-size: 20px;
            }

            .header-right {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .user-profile {
                justify-content: space-between;
            }

            .content {
                padding: 10px;
            }

            .stat-card {
                padding: 18px;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            #searchStatus {
                position: static !important;
                margin-top: 8px;
            }
        }

        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #667eea;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #764ba2;
        }
    </style>
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="sidebar-logo">
                <h2><i class="fas fa-graduation-cap"></i> LMS</h2>
                <p>Admin Panel</p>
            </div>

            <ul class="sidebar-menu">
                <li>
                    <a href="#" onclick="showSection('dashboard', this)" class="active" data-section="dashboard">
                        <i class="fas fa-chart-line"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" onclick="showSection('users', this)" data-section="users">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li>
                    <a href="#" onclick="showSection('courses', this)" data-section="courses">
                        <i class="fas fa-book"></i>
                        <span>Courses</span>
                    </a>
                </li>
                <li>
                    <a href="#" onclick="showSection('students', this)" data-section="students">
                        <i class="fas fa-user-graduate"></i>
                        <span>Students</span>
                    </a>
                </li>
                <li>
                    <a href="#" onclick="showSection('reports', this)" data-section="reports">
                        <i class="fas fa-file-alt"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li style="margin-top: 40px;">
                    <a href="logout.php" style="background: rgba(255, 255, 255, 0.2);">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </aside>

        <div class="main-content">
            <header class="header">
                <div class="header-left">
                    <div class="header-title">
                        <h1 id="pageTitle">Dashboard</h1>
                        <p id="pageSubtitle">Welcome back, Administrator</p>
                    </div>
                </div>

                <div class="header-right">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="globalSearchInput" placeholder="Search users, courses...">
                        <div id="searchStatus" style="position:absolute; left:0; right:0; top:44px; font-size:12px; color:#777; background:#fff; border:1px solid #eee; border-radius:8px; padding:6px 10px; display:none; z-index:5;"></div>
                    </div>

                    

                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($user['userId'], 0, 2)); ?></div>
                        <div class="user-info">
                            <p><?php echo htmlspecialchars($user['userId']); ?></p>
                            <span><?php echo roleLabel($user['role']); ?></span>
                        </div>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                </div>
            </header>

            <div class="content">
                <?php if (!empty($message)): ?>
                    <div style="background: #e8f7ea; border: 1px solid #34a853; color: #1d682e; padding: 16px 20px; border-radius: 14px; margin-bottom: 20px;">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div id="moduleResourceModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
                    <div style="background: white; margin: 10% auto; padding: 30px; border-radius: 15px; width: 90%; max-width: 560px; position: relative;">
                        <h2 style="margin-bottom: 20px; color: #333;">Upload Lesson</h2>
                        <form method="POST" action="admin_action.php" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="add_module_resource">
                            <div style="margin-bottom: 20px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #555;">Title *</label>
                                <input type="text" name="title" required style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                            </div>
                            <div style="margin-bottom: 20px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #555;">Type *</label>
                                <select name="resourceType" required style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                                    <option value="lesson">Lesson</option>
                                    <option value="activity">Activity</option>
                                    <option value="material">Material</option>
                                </select>
                            </div>
                            <div style="margin-bottom: 20px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #555;">Description</label>
                                <textarea name="description" rows="3" style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; resize: vertical;"></textarea>
                            </div>
                            <div style="margin-bottom: 20px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #555;">File</label>
                                <input type="file" name="resourceFile" accept=".pdf,.docx,.pptx,.zip,.jpg,.jpeg,.png,.txt" style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                            </div>
                            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                <button type="button" onclick="closeModuleResourceModal()" class="btn btn-secondary" style="padding: 12px 24px;">Cancel</button>
                                <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">Upload</button>
                            </div>
                        </form>
                        <span onclick="closeModuleResourceModal()" style="position: absolute; right: 15px; top: 15px; font-size: 24px; cursor: pointer; color: #999;">&times;</span>
                    </div>
                </div>
                <div class="section active" id="dashboard">
                    <div class="stats-grid">
                        <div class="stat-card users">
                            <div class="stat-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-label">Total Users</div>
                            <div class="stat-value"><?php echo number_format($usersCount); ?></div>
                            <div class="stat-change"><i class="fas fa-arrow-up"></i> Active users</div>
                        </div>

<div class="stat-card courses">
                            <div class="stat-icon">
                                <i class="fas fa-book"></i>
                            </div>
                            <div class="stat-label">Total Courses</div>
                            <div class="stat-value"><?php echo number_format($coursesCount); ?></div>
                            <div class="stat-change"><i class="fas fa-arrow-up"></i> Active courses</div>
                        </div>

                        <div class="stat-card modules">
                            <div class="stat-icon">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <div class="stat-label">Total Modules</div>
                            <div class="stat-value"><?php echo number_format($modulesCount); ?></div>
                            <div class="stat-change"><i class="fas fa-arrow-up"></i> Course modules</div>
                        </div>

                        <div class="stat-card students">
                            <div class="stat-icon">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <div class="stat-label">Total Enrollments</div>
                            <div class="stat-value"><?php echo number_format($enrollmentsCount); ?></div>
                            <div class="stat-change"><i class="fas fa-arrow-up"></i> Active enrollments</div>
                        </div>

                        <div class="stat-card progress">
                            <div class="stat-icon">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <div class="stat-label">Database Status</div>
                            <div class="stat-value">Active</div>
                            <div class="stat-change"><i class="fas fa-check-circle"></i> Connected</div>
                        </div>
                    </div>

                    

                    <div class="chart-container">
                        <h3 style="margin-bottom: 20px; color: #333; font-weight: 600;">Recent Activity</h3>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recentActivity && $recentActivity->num_rows > 0): ?>
                                    <?php while ($activity = $recentActivity->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($activity['action']); ?></td>
                                            <td><?php echo htmlspecialchars($activity['date']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" style="text-align:center; padding: 20px;">No activity available yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section" id="users">
                    <div class="section-header">
                        <h2 class="section-title">User Management</h2>
                        <a href="admin_user.php" class="btn btn-primary" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">+ Add New User</a>
                    </div>

                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                        <?php if ($userList && $userList->num_rows > 0): ?>
                            <?php while ($row = $userList->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['userId']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars(roleLabel($row['role'])); ?></td>
                                    <td><span class="badge active">Active</span></td>
                                    <td><?php echo date('M d, Y', strtotime($row['createdAt'] ?? 'now')); ?></td>
                                    <td>
                                        <a href="admin_user.php?userId=<?php echo urlencode($row['userId']); ?>" class="action-btn edit"><i class="fas fa-edit"></i> Edit</a>
                                        <form method="POST" action="admin_action.php" style="display: inline;" onsubmit="return confirm('Delete this user?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="userId" value="<?php echo htmlspecialchars($row['userId']); ?>">
                                            <button type="submit" class="action-btn delete" style="border: none; background: none; cursor: pointer;"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding: 20px;">No users found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

                <div class="section" id="courses">
                    <div class="section-header">
                        <h2 class="section-title">Course Management</h2>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-secondary" onclick="openModuleResourceModal()">+ Upload Lesson</button>
                            <button class="btn btn-primary" onclick="openCreateCourseModal()">+ Create Course</button>
                        </div>
                    </div>

                    <div id="createCourseModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
                        <div style="background: white; margin: 10% auto; padding: 30px; border-radius: 15px; width: 90%; max-width: 500px; position: relative;">
                            <h2 style="margin-bottom: 20px; color: #333;">Create New Course</h2>
                            <form method="POST" action="admin_action.php">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="add_course">
                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #555;">Course Name *</label>
                                    <input type="text" name="courseName" required style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                                </div>
                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #555;">Description</label>
                                    <textarea name="description" rows="3" style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; resize: vertical;"></textarea>
                                </div>
                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #555;">Assign Professor</label>
                                    <select name="professorId" style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                                        <option value="">No professor (assign later)</option>
                                        <?php foreach ($professorOptions as $p): ?>
                                            <option value="<?php echo htmlspecialchars($p['userId']); ?>"><?php echo htmlspecialchars($p['email']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                    <button type="button" onclick="closeCreateCourseModal()" class="btn btn-secondary" style="padding: 12px 24px;">Cancel</button>
                                    <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">Create Course</button>
                                </div>
                            </form>
                            <span onclick="closeCreateCourseModal()" style="position: absolute; right: 15px; top: 15px; font-size: 24px; cursor: pointer; color: #999;">&times;</span>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Course Name</th>
                                    <th>Instructor</th>
                                    <th>Students</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Assign Professor</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($courseList && $courseList->num_rows > 0): ?>
                                    <?php while ($course = $courseList->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($course['courseName']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($course['professorEmail'] ?: 'Unassigned'); ?></td>
                                            <td><?php echo number_format($course['studentCount']); ?> students</td>
                                            <td><span class="badge active">Active</span></td>
                                            <td><?php echo date('M d, Y', strtotime($course['createdAt'])); ?></td>
                                            <td>
                                                <form method="POST" action="admin_action.php" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="action" value="assign_course_professor">
                                                    <input type="hidden" name="courseId" value="<?php echo intval($course['courseId']); ?>">
                                                    <select name="professorId" style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; min-width: 150px; background: white;">
                                                        <option value="">Unassign Professor</option>
                                                        <?php foreach($professorOptions as $prof): ?>
                                                            <option value="<?php echo htmlspecialchars($prof['userId']); ?>" <?php echo $course['professorId'] === $prof['userId'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prof['email']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="action-btn edit" style="margin-left: 5px;"><i class="fas fa-user-plus"></i> Assign</button>
                                                </form>
                                            </td>
                                            <td>
                                                <button class="action-btn edit" onclick="alert('Edit course coming soon')"><i class="fas fa-edit"></i> Edit</button>
                                                <form method="POST" action="admin_action.php" style="display: inline;" onsubmit="return confirm('Delete this course? All modules will be unlinked.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="action" value="delete_course">
                                                    <input type="hidden" name="courseId" value="<?php echo intval($course['courseId']); ?>">
                                                    <button type="submit" class="action-btn delete" style="border: none; background: none; cursor: pointer;"><i class="fas fa-trash"></i> Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center; padding: 20px;">No courses found. Create your first course above!</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>


                <div class="section" id="students">
                    <div class="section-header">
                        <h2 class="section-title">Student Progress Tracking</h2>
                    </div>

                    <div class="table-container">
                        <div style="padding: 18px 18px 0 18px; font-weight: 700; color: #333;">
                            Pending Enrollment Requests (<?php echo number_format($pendingRequestCount); ?>)
                        </div>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Course</th>
                                    <th>Requested At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($pendingEnrollmentRequests && $pendingEnrollmentRequests->num_rows > 0): ?>
                                    <?php while ($request = $pendingEnrollmentRequests->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($request['studentEmail']); ?></strong><br>
                                                <span style="font-size: 12px; color: #777;"><?php echo htmlspecialchars($request['userId']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($request['courseName']); ?></td>
                                            <td><?php echo date('M d, Y h:i A', strtotime($request['requestedAt'])); ?></td>
                                            <td>
                                                <form method="POST" action="admin_action.php" style="display:inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="action" value="approve_enrollment_request">
                                                    <input type="hidden" name="requestId" value="<?php echo intval($request['requestId']); ?>">
                                                    <button type="submit" class="action-btn edit"><i class="fas fa-check"></i> Approve</button>
                                                </form>
                                                <form method="POST" action="admin_action.php" style="display:inline;" onsubmit="return confirm('Reject this enrollment request?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="action" value="reject_enrollment_request">
                                                    <input type="hidden" name="requestId" value="<?php echo intval($request['requestId']); ?>">
                                                    <button type="submit" class="action-btn delete"><i class="fas fa-times"></i> Reject</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center; padding: 20px;">No pending enrollment requests.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Course</th>
                                    <th>Enrolled</th>
                                    <th>Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($studentProgress && $studentProgress->num_rows > 0): ?>
                                    <?php while ($progress = $studentProgress->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($progress['email']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($progress['learningItem']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($progress['enrollmentDate'])); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($progress['enrollmentDate'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center; padding: 20px;">No student progress data available yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="section-header" style="margin-top: 10px;">
                        <h2 class="section-title" style="margin: 0;">Lessons & Activities</h2>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>File</th>
                                    <th>Uploaded By</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($moduleResourceList && $moduleResourceList->num_rows > 0): ?>
                                    <?php while ($resource = $moduleResourceList->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($resource['title']); ?></strong><br>
                                                <span style="font-size: 12px; color: #777;"><?php echo htmlspecialchars($resource['description'] ?: '-'); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars(ucfirst($resource['resourceType'])); ?></td>
                                            <td>
                                                <?php if (!empty($resource['filePath'])): ?>
                                                    <a href="<?php echo htmlspecialchars($resource['filePath']); ?>" target="_blank">Download</a>
                                                <?php else: ?>
                                                    <span style="color: #777;">No file</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($resource['createdByEmail'] ?: 'Admin'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($resource['createdAt'])); ?></td>
                                            <td>
                                                <form method="POST" action="admin_action.php" style="display:inline;" onsubmit="return confirm('Delete this module resource?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="action" value="delete_module_resource">
                                                    <input type="hidden" name="resourceId" value="<?php echo intval($resource['resourceId']); ?>">
                                                    <button type="submit" class="action-btn delete"><i class="fas fa-trash"></i> Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center; padding: 20px;">No lessons or activities uploaded yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section" id="reports">
                    <h2 class="section-title">Reports & Analytics</h2>

                    <div class="table-container" style="margin-bottom: 24px;">
                        <div style="padding: 18px 18px 0 18px; font-weight: 700; color: #333;">
                            Sign-In History
                        </div>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>IP Address</th>
                                    <th>Device / Browser</th>
                                    <th>Signed In</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($loginHistoryList && $loginHistoryList->num_rows > 0): ?>
                                    <?php while ($loginEntry = $loginHistoryList->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($loginEntry['email'] ?: ($loginEntry['userId'] ?: 'Unknown User')); ?></strong><br>
                                                <span style="font-size: 12px; color: #777;"><?php echo htmlspecialchars($loginEntry['userId'] ?: '-'); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars(ucfirst($loginEntry['role'] ?: 'unknown')); ?></td>
                                            <td><?php echo htmlspecialchars($loginEntry['ipAddress'] ?: '-'); ?></td>
                                            <td style="max-width: 280px; word-break: break-word;"><?php echo htmlspecialchars($loginEntry['userAgent'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($loginEntry['signedInAt'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center; padding: 20px;">No sign-in history yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-container">
                        <div style="padding: 18px 18px 0 18px; font-weight: 700; color: #333;">
                            Submission History (Admin Control)
                        </div>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Assignment</th>
                                    <th>Submitted</th>
                                    <th>Graded</th>
                                    <th>Grade</th>
                                    <th>Feedback</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($submissionHistoryList && $submissionHistoryList->num_rows > 0): ?>
                                    <?php while ($history = $submissionHistoryList->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($history['studentEmail'] ?: $history['userId']); ?></strong><br>
                                                <span style="font-size: 12px; color: #777;">By: <?php echo htmlspecialchars($history['gradedByEmail'] ?: 'Professor'); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($history['assignmentTitle'] ?: ('Assignment #' . $history['assignmentId'])); ?></td>
                                            <td><?php echo htmlspecialchars($history['submittedAt'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($history['gradedAt'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($history['grade'] ?: '-'); ?></td>
                                            <td style="max-width: 220px;"><?php echo nl2br(htmlspecialchars($history['feedback'] ?: '-')); ?></td>
                                            <td>
                                                <form method="POST" action="admin_action.php" style="display:block; margin-bottom: 8px;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="action" value="update_submission_history">
                                                    <input type="hidden" name="historyId" value="<?php echo intval($history['historyId']); ?>">
                                                    <input type="text" name="grade" value="<?php echo htmlspecialchars($history['grade']); ?>" placeholder="Grade" style="width: 90px; padding: 6px; margin-right: 6px; border: 1px solid #ddd; border-radius: 4px;">
                                                    <input type="text" name="feedback" value="<?php echo htmlspecialchars($history['feedback']); ?>" placeholder="Feedback" style="width: 140px; padding: 6px; margin-right: 6px; border: 1px solid #ddd; border-radius: 4px;">
                                                    <button type="submit" class="action-btn edit"><i class="fas fa-save"></i> Save</button>
                                                </form>
                                                <form method="POST" action="admin_action.php" style="display:inline;" onsubmit="return confirm('Delete this history record?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="action" value="delete_submission_history">
                                                    <input type="hidden" name="historyId" value="<?php echo intval($history['historyId']); ?>">
                                                    <button type="submit" class="action-btn delete"><i class="fas fa-trash"></i> Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center; padding: 20px;">No graded history records yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    
                </div>

            </div>
        </div>
    </div>

    <script>
        function setActiveSection(sectionId, element) {
            const targetElement = element || document.querySelector('.sidebar-menu a[data-section="' + sectionId + '"]');

            const sections = document.querySelectorAll('.section');
            sections.forEach(section => section.classList.remove('active'));

            document.getElementById(sectionId).classList.add('active');

            document.querySelectorAll('.sidebar-menu a').forEach(link => {
                link.classList.remove('active');
            });
            if (targetElement) {
                targetElement.classList.add('active');
            }

            const titles = {
                dashboard: 'Dashboard',
                users: 'User Management',
                courses: 'Course Management',
                students: 'Student Progress',
                reports: 'Reports & Analytics'
            };

            document.getElementById('pageTitle').textContent = titles[sectionId];
        }

        function showSection(sectionId, element) {
            event.preventDefault();
            setActiveSection(sectionId, element);
        }

        function openCreateCourseModal() {
            document.getElementById('createCourseModal').style.display = 'block';
        }

        function closeCreateCourseModal() {
            document.getElementById('createCourseModal').style.display = 'none';
        }

        function openModuleResourceModal() {
            document.getElementById('moduleResourceModal').style.display = 'block';
        }

        function closeModuleResourceModal() {
            document.getElementById('moduleResourceModal').style.display = 'none';
        }

        window.onclick = function(event) {
            const courseModal = document.getElementById('createCourseModal');
            const moduleResourceModal = document.getElementById('moduleResourceModal');
            if (event.target == courseModal) {
                closeCreateCourseModal();
            }
            if (event.target == moduleResourceModal) {
                closeModuleResourceModal();
            }
        }

        document.querySelectorAll('input[type="text"], input[type="email"], textarea, select').forEach(input => {
            input.addEventListener('focus', function() {
                this.style.borderColor = '#667eea';
                this.style.boxShadow = '0 0 10px rgba(102, 126, 234, 0.2)';
            });
            input.addEventListener('blur', function() {
                this.style.borderColor = '#e0e0e0';
                this.style.boxShadow = 'none';
            });
        });

        const searchInput = document.getElementById('globalSearchInput');
        const searchStatus = document.getElementById('searchStatus');
        const searchableSections = ['users', 'courses', 'students', 'reports'];

        function filterRowsInSection(sectionId, term) {
            const section = document.getElementById(sectionId);
            if (!section) return { totalRows: 0, matchedRows: 0 };

            const rows = section.querySelectorAll('tbody tr');
            let matchedRows = 0;
            rows.forEach((row) => {
                const match = term === '' || row.textContent.toLowerCase().includes(term);
                row.style.display = match ? '' : 'none';
                if (match) matchedRows++;
            });

            return { totalRows: rows.length, matchedRows };
        }

        function runGlobalSearch() {
            const term = searchInput.value.trim().toLowerCase();
            let firstSectionWithMatch = null;
            let totalMatches = 0;

            searchableSections.forEach((sectionId) => {
                const stats = filterRowsInSection(sectionId, term);
                totalMatches += stats.matchedRows;
                if (!firstSectionWithMatch && stats.matchedRows > 0) {
                    firstSectionWithMatch = sectionId;
                }
            });

            if (term === '') {
                searchStatus.style.display = 'none';
                return;
            }

            if (firstSectionWithMatch) {
                setActiveSection(firstSectionWithMatch);
                searchStatus.textContent = totalMatches + ' match(es) found';
                searchStatus.style.display = 'block';
            } else {
                searchStatus.textContent = 'No matching results';
                searchStatus.style.display = 'block';
            }
        }

        searchInput.addEventListener('input', runGlobalSearch);
    </script>
</body>
</html>
<?php $conn->close(); ?>


