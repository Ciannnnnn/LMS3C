<?php

function getCsrfToken() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return session_status() === PHP_SESSION_ACTIVE
        && is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCliOnly() {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('Forbidden');
    }
}

function storeUploadedFile(array $file, $uploadDir, $relativePrefix, array $allowedExtensions = ['pdf', 'docx', 'pptx', 'zip', 'jpg', 'jpeg', 'png', 'txt'], $maxBytes = 10485760) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'filePath' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload failed. Please try again.'];
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => 'Invalid upload payload detected.'];
    }

    if (($file['size'] ?? 0) > $maxBytes) {
        return ['success' => false, 'message' => 'File is too large. Maximum size is 10 MB.'];
    }

    $originalName = basename((string)($file['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        return ['success' => false, 'message' => 'Unsupported file type uploaded.'];
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return ['success' => false, 'message' => 'Upload folder is not available right now.'];
    }

    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $safeBaseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
    $safeBaseName = trim($safeBaseName, '_');
    if ($safeBaseName === '') {
        $safeBaseName = 'file';
    }

    $targetName = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safeBaseName . '.' . $extension;
    $targetPath = rtrim($uploadDir, '\\/') . DIRECTORY_SEPARATOR . $targetName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'message' => 'Unable to save the uploaded file.'];
    }

    return [
        'success' => true,
        'filePath' => trim($relativePrefix, '\\/') . '/' . $targetName,
    ];
}
