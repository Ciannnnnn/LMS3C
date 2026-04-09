<?php
require_once __DIR__ . '/security.php';

$serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
$httpHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocalEnvironment = in_array($serverName, ['localhost', '127.0.0.1'], true)
    || in_array($httpHost, ['localhost', '127.0.0.1'], true)
    || stripos(__DIR__, 'xampp\\htdocs') !== false;

$defaultDbConfig = $isLocalEnvironment
    ? [
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'name' => 'infotech_3c',
        'port' => 3307,
    ]
    : [
        'host' => 'sql101.infinityfree.com',
        'user' => 'if0_41600855',
        'pass' => 'cfOPJdyOSh',
        'name' => 'if0_41600855_infotech_3c',
        'port' => 3306,
    ];

define('DB_HOST', getenv('DB_HOST') ?: $defaultDbConfig['host']);
define('DB_USER', getenv('DB_USER') ?: $defaultDbConfig['user']);
define('DB_PASS', getenv('DB_PASS') ?: $defaultDbConfig['pass']);
define('DB_NAME', getenv('DB_NAME') ?: $defaultDbConfig['name']);
define('DB_PORT', (int)(getenv('DB_PORT') ?: $defaultDbConfig['port']));

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    die('Database connection failed. Please try again later.');
}

$conn->set_charset("utf8mb4");

function ensureDatabaseSchema($conn) {
    $databaseName = DB_NAME;

    $tableExists = function ($tableName) use ($conn, $databaseName) {
        $safeTable = $conn->real_escape_string($tableName);
        $safeDb = $conn->real_escape_string($databaseName);
        $result = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$safeDb}' AND TABLE_NAME = '{$safeTable}' LIMIT 1");
        return $result && $result->num_rows > 0;
    };

    $constraintExists = function ($tableName, $constraintName) use ($conn, $databaseName) {
        $safeTable = $conn->real_escape_string($tableName);
        $safeConstraint = $conn->real_escape_string($constraintName);
        $safeDb = $conn->real_escape_string($databaseName);
        $result = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '{$safeDb}' AND TABLE_NAME = '{$safeTable}' AND CONSTRAINT_NAME = '{$safeConstraint}' LIMIT 1");
        return $result && $result->num_rows > 0;
    };

    if (!$tableExists('users')) {
        $conn->query("CREATE TABLE IF NOT EXISTS users (
            userId VARCHAR(50) PRIMARY KEY,
            email VARCHAR(100) UNIQUE NOT NULL,
            userPassword VARCHAR(255) NOT NULL,
            role ENUM('user', 'admin', 'professor') DEFAULT 'user',
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('users') && !$tableExists('courses')) {
        $conn->query("CREATE TABLE courses (
            courseId INT PRIMARY KEY AUTO_INCREMENT,
            courseName VARCHAR(100) NOT NULL,
            description TEXT,
            professorId VARCHAR(50) NULL,
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (professorId) REFERENCES users(userId) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('modules')) {
        $conn->query("CREATE TABLE IF NOT EXISTS modules (
            moduleId INT PRIMARY KEY AUTO_INCREMENT,
            moduleName VARCHAR(100) NOT NULL,
            description TEXT,
            courseId INT NULL,
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('enrollments')) {
        $conn->query("CREATE TABLE IF NOT EXISTS enrollments (
            enrollmentId INT PRIMARY KEY AUTO_INCREMENT,
            userId VARCHAR(50) NOT NULL,
            courseId INT NULL,
            moduleId INT NULL,
            enrollmentDate TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('enrollment_requests')) {
        $conn->query("CREATE TABLE IF NOT EXISTS enrollment_requests (
            requestId INT PRIMARY KEY AUTO_INCREMENT,
            userId VARCHAR(50) NOT NULL,
            courseId INT NOT NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            requestedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reviewedAt TIMESTAMP NULL DEFAULT NULL,
            reviewedBy VARCHAR(50) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('modules')) {
        $result = $conn->query("SHOW COLUMNS FROM modules LIKE 'courseId'");
        if (!$result || $result->num_rows === 0) {
            $conn->query("ALTER TABLE modules ADD courseId INT NULL");
        }
    }

    if ($tableExists('users')) {
        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
        if ($result && $row = $result->fetch_assoc()) {
            if (strpos($row['Type'], "professor") === false) {
                $conn->query("ALTER TABLE users MODIFY role ENUM('user','admin','professor') DEFAULT 'user'");
            }
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS assignments (
        assignmentId INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(150) NOT NULL,
        description TEXT,
        filePath VARCHAR(255),
        createdBy VARCHAR(50) NOT NULL,
        moduleId INT NULL,
        createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!$tableExists('module_resources')) {
        $conn->query("CREATE TABLE IF NOT EXISTS module_resources (
            resourceId INT PRIMARY KEY AUTO_INCREMENT,
            moduleId INT NULL,
            title VARCHAR(150) NOT NULL,
            description TEXT,
            resourceType ENUM('lesson', 'activity', 'material') DEFAULT 'lesson',
            filePath VARCHAR(255) NULL,
            createdBy VARCHAR(50) NOT NULL,
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('login_history')) {
        $conn->query("CREATE TABLE IF NOT EXISTS login_history (
            loginId INT PRIMARY KEY AUTO_INCREMENT,
            userId VARCHAR(50) NULL,
            email VARCHAR(100) NULL,
            role VARCHAR(20) NULL,
            ipAddress VARCHAR(45) NULL,
            userAgent VARCHAR(255) NULL,
            signedInAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('submissions')) {
        $conn->query("CREATE TABLE IF NOT EXISTS submissions (
            submissionId INT PRIMARY KEY AUTO_INCREMENT,
            enrollmentId INT NULL,
            userId VARCHAR(50),
            assignmentId INT NULL,
            submissionContent LONGTEXT,
            filePath VARCHAR(255) NULL,
            grade VARCHAR(10) NULL,
            feedback TEXT NULL,
            submissionDate TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('submission_history')) {
        $conn->query("CREATE TABLE IF NOT EXISTS submission_history (
            historyId INT PRIMARY KEY AUTO_INCREMENT,
            originalSubmissionId INT NULL,
            userId VARCHAR(50) NOT NULL,
            assignmentId INT NULL,
            submissionContent LONGTEXT,
            filePath VARCHAR(255) NULL,
            grade VARCHAR(10) NULL,
            feedback TEXT NULL,
            submittedAt TIMESTAMP NULL,
            gradedBy VARCHAR(50) NULL,
            gradedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($tableExists('assignments') && $tableExists('users') && !$constraintExists('assignments', 'fk_assignments_createdBy')) {
        $conn->query("ALTER TABLE assignments ADD CONSTRAINT fk_assignments_createdBy FOREIGN KEY (createdBy) REFERENCES users(userId) ON DELETE CASCADE");
    }

    if ($tableExists('assignments') && $tableExists('modules') && !$constraintExists('assignments', 'fk_assignments_moduleId')) {
        $conn->query("ALTER TABLE assignments ADD CONSTRAINT fk_assignments_moduleId FOREIGN KEY (moduleId) REFERENCES modules(moduleId) ON DELETE CASCADE");
    }

    if ($tableExists('enrollments') && $tableExists('users') && !$constraintExists('enrollments', 'fk_enrollments_userId')) {
        $conn->query("ALTER TABLE enrollments ADD CONSTRAINT fk_enrollments_userId FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE");
    }

    if ($tableExists('enrollments') && $tableExists('modules') && !$constraintExists('enrollments', 'fk_enrollments_moduleId')) {
        $conn->query("ALTER TABLE enrollments ADD CONSTRAINT fk_enrollments_moduleId FOREIGN KEY (moduleId) REFERENCES modules(moduleId) ON DELETE CASCADE");
    }

    if ($tableExists('submissions') && $tableExists('enrollments') && !$constraintExists('submissions', 'fk_submissions_enrollmentId')) {
        $conn->query("ALTER TABLE submissions ADD CONSTRAINT fk_submissions_enrollmentId FOREIGN KEY (enrollmentId) REFERENCES enrollments(enrollmentId) ON DELETE CASCADE");
    }

    if ($tableExists('submissions') && $tableExists('users') && !$constraintExists('submissions', 'fk_submissions_userId')) {
        $conn->query("ALTER TABLE submissions ADD CONSTRAINT fk_submissions_userId FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE");
    }

    if ($tableExists('submissions') && $tableExists('assignments') && !$constraintExists('submissions', 'fk_submissions_assignmentId')) {
        $conn->query("ALTER TABLE submissions ADD CONSTRAINT fk_submissions_assignmentId FOREIGN KEY (assignmentId) REFERENCES assignments(assignmentId) ON DELETE CASCADE");
    }

    if ($tableExists('modules') && $tableExists('courses') && !$constraintExists('modules', 'fk_modules_course')) {
        $conn->query("ALTER TABLE modules ADD CONSTRAINT fk_modules_course FOREIGN KEY (courseId) REFERENCES courses(courseId) ON DELETE SET NULL");
    }

    if ($tableExists('module_resources') && $tableExists('modules') && !$constraintExists('module_resources', 'fk_module_resources_module')) {
        $conn->query("ALTER TABLE module_resources ADD CONSTRAINT fk_module_resources_module FOREIGN KEY (moduleId) REFERENCES modules(moduleId) ON DELETE CASCADE");
    }

    if ($tableExists('module_resources')) {
        $result = $conn->query("SHOW COLUMNS FROM module_resources LIKE 'moduleId'");
        if ($result && $row = $result->fetch_assoc()) {
            if (strpos($row['Type'], 'int') !== false && $row['Null'] === 'NO') {
                $conn->query("ALTER TABLE module_resources MODIFY moduleId INT NULL");
            }
        }
    }

    if ($tableExists('module_resources') && $tableExists('users') && !$constraintExists('module_resources', 'fk_module_resources_createdBy')) {
        $conn->query("ALTER TABLE module_resources ADD CONSTRAINT fk_module_resources_createdBy FOREIGN KEY (createdBy) REFERENCES users(userId) ON DELETE CASCADE");
    }

    if ($tableExists('submission_history') && $tableExists('users') && !$constraintExists('submission_history', 'fk_submission_history_user')) {
        $conn->query("ALTER TABLE submission_history ADD CONSTRAINT fk_submission_history_user FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE");
    }

    if ($tableExists('submission_history') && $tableExists('assignments') && !$constraintExists('submission_history', 'fk_submission_history_assignment')) {
        $conn->query("ALTER TABLE submission_history ADD CONSTRAINT fk_submission_history_assignment FOREIGN KEY (assignmentId) REFERENCES assignments(assignmentId) ON DELETE SET NULL");
    }

    if ($tableExists('submission_history') && $tableExists('users') && !$constraintExists('submission_history', 'fk_submission_history_gradedBy')) {
        $conn->query("ALTER TABLE submission_history ADD CONSTRAINT fk_submission_history_gradedBy FOREIGN KEY (gradedBy) REFERENCES users(userId) ON DELETE SET NULL");
    }

    if ($tableExists('enrollment_requests') && $tableExists('users') && !$constraintExists('enrollment_requests', 'fk_enrollment_requests_user')) {
        $conn->query("ALTER TABLE enrollment_requests ADD CONSTRAINT fk_enrollment_requests_user FOREIGN KEY (userId) REFERENCES users(userId) ON DELETE CASCADE");
    }

    if ($tableExists('enrollment_requests') && $tableExists('courses') && !$constraintExists('enrollment_requests', 'fk_enrollment_requests_course')) {
        $conn->query("ALTER TABLE enrollment_requests ADD CONSTRAINT fk_enrollment_requests_course FOREIGN KEY (courseId) REFERENCES courses(courseId) ON DELETE CASCADE");
    }

    if ($tableExists('enrollment_requests') && $tableExists('users') && !$constraintExists('enrollment_requests', 'fk_enrollment_requests_reviewer')) {
        $conn->query("ALTER TABLE enrollment_requests ADD CONSTRAINT fk_enrollment_requests_reviewer FOREIGN KEY (reviewedBy) REFERENCES users(userId) ON DELETE SET NULL");
    }

    if ($tableExists('submissions')) {
        $result = $conn->query("SHOW COLUMNS FROM submissions LIKE 'assignmentId'");
        if (!$result || $result->num_rows === 0) {
            $conn->query("ALTER TABLE submissions ADD assignmentId INT NULL AFTER enrollmentId");
        }

        $result = $conn->query("SHOW COLUMNS FROM submissions LIKE 'grade'");
        if (!$result || $result->num_rows === 0) {
            $conn->query("ALTER TABLE submissions ADD grade VARCHAR(10) NULL AFTER assignmentId");
        }

        $result = $conn->query("SHOW COLUMNS FROM submissions LIKE 'feedback'");
        if (!$result || $result->num_rows === 0) {
            $conn->query("ALTER TABLE submissions ADD feedback TEXT NULL AFTER grade");
        }
    }

}

ensureDatabaseSchema($conn);
?>
