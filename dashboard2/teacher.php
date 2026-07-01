<?php
session_start();
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/csrf.php';
require '../includes/config.php';
require_teacher();

function log_error($message, $context = []) {
    $logEntry = date('Y-m-d H:i:s') . " | " . $message;
    if (!empty($context)) {
        $logEntry .= " | " . json_encode($context);
    }
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    error_log($logEntry . "\n", 3, $logDir . '/teacher_errors.log');
}

/**
 * Normalizes an uploaded file field (which may be a single file or an
 * array of files, e.g. from name="score_key_file[]") into a flat list
 * of individual file arrays: [['name'=>..,'type'=>..,'tmp_name'=>..,'error'=>..,'size'=>..], ...]
 * Order of the returned array matches the order the browser submitted the files in.
 */
function normalize_uploaded_files($field) {
    $files = [];
    if (!isset($field['name'])) return $files;
    if (is_array($field['name'])) {
        $count = count($field['name']);
        for ($i = 0; $i < $count; $i++) {
            $files[] = [
                'name'     => $field['name'][$i],
                'type'     => $field['type'][$i],
                'tmp_name' => $field['tmp_name'][$i],
                'error'    => $field['error'][$i],
                'size'     => $field['size'][$i],
            ];
        }
    } else {
        $files[] = $field;
    }
    return $files;
}

/**
 * Validates and moves a set of uploaded score-key files.
 * Rules: either exactly one PDF, or one-or-more images (png/jpg/jpeg).
 * Images are stored in the order they were submitted.
 * Returns an array of destination paths, in upload order.
 */
function process_score_key_uploads($files) {
    $maxSize = 40 * 1024 * 1024;
    $allowed = ['pdf', 'png', 'jpg', 'jpeg'];

    if (empty($files)) {
        throw new Exception('No file uploaded.');
    }

    $exts = [];
    foreach ($files as $f) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed: ' . get_upload_error_message($f['error'] ?? UPLOAD_ERR_NO_FILE));
        }
        if (($f['size'] ?? 0) > $maxSize) {
            throw new Exception('File "' . $f['name'] . '" exceeds 40 MB limit.');
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            throw new Exception('Invalid score key document format. Allowed: PDF, PNG, JPG, JPEG.');
        }
        $exts[] = $ext;
    }

    $hasPdf = in_array('pdf', $exts, true);
    if ($hasPdf && count($files) > 1) {
        throw new Exception('Only one PDF file may be uploaded at a time. To upload multiple pages, use image files instead.');
    }

    if (!is_dir('uploads/score_keys')) mkdir('uploads/score_keys', 0755, true);

    $destPaths = [];
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $destPath = 'uploads/score_keys/' . uniqid('sk_', true) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $destPath)) {
            foreach ($destPaths as $done) {
                if (file_exists($done)) @unlink($done);
            }
            throw new Exception('Failed to move uploaded file "' . $f['name'] . '".');
        }
        $destPaths[] = $destPath;
    }

    return $destPaths;
}

/**
 * Builds the value to store in score_keys.file_path from a list of moved
 * destination paths, guarding against overflowing the DB column.
 * - 1 file  -> stored as a plain path string (backward compatible).
 * - 2+ files -> stored as a JSON array, in upload order.
 * If the JSON would be too large to store safely, the already-moved files
 * are deleted and an exception is thrown instead of silently truncating.
 */
function build_stored_file_path($destPaths) {
    // Safe ceiling assuming the file_path column is TEXT (max 65,535 bytes).
    // Kept well under that to leave headroom and stay safe even if the
    // column turns out to be a smaller type (e.g. VARCHAR).
    $maxStoredPathBytes = 60000;

    $storedPath = (count($destPaths) === 1) ? $destPaths[0] : json_encode($destPaths);

    if (strlen($storedPath) > $maxStoredPathBytes) {
        foreach ($destPaths as $done) {
            if (file_exists($done)) @unlink($done);
        }
        throw new Exception(
            'Too many images/too large a combined file list to store (' . count($destPaths) . ' files). ' .
            'Please split this score key into smaller batches, or ask your administrator to confirm the ' .
            'score_keys.file_path database column is of type TEXT.'
        );
    }

    return $storedPath;
}

function get_upload_error_message($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:   return 'File exceeds server upload limit.';
        case UPLOAD_ERR_FORM_SIZE:  return 'File exceeds form limit.';
        case UPLOAD_ERR_PARTIAL:    return 'File was only partially uploaded.';
        case UPLOAD_ERR_NO_FILE:    return 'No file was selected.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Temporary folder missing.';
        case UPLOAD_ERR_CANT_WRITE: return 'Failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION:  return 'File upload stopped by extension.';
        default: return 'Unknown upload error.';
    }
}

set_exception_handler(function ($e) {
    log_error('Uncaught exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => $e->getTraceAsString()
    ]);
    http_response_code(500);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Internal server error. Please try again later.']);
    } else {
        echo "<h1>Server Error</h1><p>An unexpected error occurred. Please contact support.</p>";
    }
    exit;
});

$teacher_id = $_SESSION['user_id'] ?? 0;
$notice = null; $error = null;

function send_notification($user_id, $sender_id, $type, $message, $link = null) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, type, message, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $sender_id, $type, $message, $link]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    if ($action === 'get_assignment_details' && isset($_GET['assignment_id'])) {
        $asm_id = (int)$_GET['assignment_id'];
        $stmt = $pdo->prepare("
            SELECT a.*, u.full_name as student_name, sk.version as score_key_version
            FROM assignments a
            JOIN users u ON a.student_id = u.id
            LEFT JOIN score_keys sk ON a.score_key_id = sk.id
            JOIN teacher_student ts ON u.id = ts.student_id
            WHERE a.id = ? AND ts.teacher_id = ?
        ");
        $stmt->execute([$asm_id, $teacher_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            log_error('AJAX get_assignment_details: not found', ['assignment_id' => $asm_id, 'teacher_id' => $teacher_id]);
            echo json_encode(['error' => 'Not found or access denied.']);
        } else {
            echo json_encode($result);
        }
        exit;
    }
    if ($action === 'get_assignment_for_edit' && isset($_GET['assignment_id'])) {
        $asm_id = (int)$_GET['assignment_id'];
        $stmt = $pdo->prepare("
            SELECT a.*
            FROM assignments a
            JOIN teacher_student ts ON a.student_id = ts.student_id
            WHERE a.id = ? AND ts.teacher_id = ?
        ");
        $stmt->execute([$asm_id, $teacher_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            log_error('AJAX get_assignment_for_edit: not found', ['assignment_id' => $asm_id, 'teacher_id' => $teacher_id]);
            echo json_encode(['error' => 'Not found or access denied.']);
        } else {
            echo json_encode($result);
        }
        exit;
    }
    if ($action === 'get_chat' && isset($_GET['student_id'])) {
        $student_id = (int)$_GET['student_id'];
        $stmt = $pdo->prepare("
            SELECT m.*, u.full_name as sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?) 
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$teacher_id, $student_id, $student_id, $teacher_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($messages)) {
            log_error('AJAX get_chat: no messages', ['teacher_id' => $teacher_id, 'student_id' => $student_id]);
        }
        echo json_encode($messages);
        exit;
    }
    if ($action === 'get_notifications') {
        $stmt = $pdo->prepare("
            SELECT n.*, u.full_name as sender_name, u.email as sender_email
            FROM notifications n
            LEFT JOIN users u ON n.sender_id = u.id
            WHERE n.user_id = ? AND n.is_read = 0
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([$teacher_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($action === 'mark_notification_read') {
        $notif_id = (int)$_GET['id'];
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notif_id, $teacher_id]);
        echo json_encode(['status' => 'ok']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Invalid CSRF token.';
        log_error('CSRF validation failed', ['ip' => $_SERVER['REMOTE_ADDR']]);
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'assign_pace') {
                $student_id = (int)($_POST['student_id'] ?? 0);
                $pace = trim($_POST['pace'] ?? '');
                $score_key_id = (int)($_POST['score_key_id'] ?? 0);
                $due_date = $_POST['due_date'] ?? '';
                $expected_pages = (int)($_POST['expected_pages'] ?? 0);

                if ($student_id <= 0 || $pace === '' || $score_key_id <= 0 || $due_date === '') {
                    throw new Exception('All assignment fields are required.');
                }
                if (!preg_match('/^[A-Za-z0-9\-_ ]+$/', $pace)) {
                    throw new Exception('PACE code contains invalid characters.');
                }
                if (!strtotime($due_date)) {
                    throw new Exception('Invalid due date format.');
                }
                if ($expected_pages < 0) {
                    throw new Exception('Expected pages cannot be negative.');
                }

                $chk = $pdo->prepare("SELECT 1 FROM teacher_student WHERE teacher_id = ? AND student_id = ?");
                $chk->execute([$teacher_id, $student_id]);
                if (!$chk->fetch()) throw new Exception('Access Denied: Unassigned student reference.');

                try {
                    $stmt = $pdo->prepare("INSERT INTO assignments (student_id, pace, score_key_id, due_date, expected_pages, status) VALUES (?, ?, ?, ?, ?, 'Assigned')");
                    $stmt->execute([$student_id, $pace, $score_key_id, $due_date, $expected_pages]);
                } catch (PDOException $e) {
                    log_error('assign_pace DB error', ['student_id' => $student_id, 'error' => $e->getMessage()]);
                    throw new Exception('Database error while saving assignment.');
                }

                send_notification($student_id, $teacher_id, 'status_change', "New PACE assignment: $pace");
                $notice = "PACE assignment registered successfully.";
            }

            if ($action === 'update_assignment') {
                $asm_id = (int)($_POST['assignment_id'] ?? 0);
                $pace = trim($_POST['pace'] ?? '');
                $score_key_id = (int)($_POST['score_key_id'] ?? 0);
                $due_date = $_POST['due_date'] ?? '';
                $expected_pages = (int)($_POST['expected_pages'] ?? 0);
                $status = $_POST['status'] ?? 'Assigned';

                if ($asm_id <= 0 || $pace === '' || $score_key_id <= 0 || $due_date === '') {
                    throw new Exception('All fields are required.');
                }
                if (!preg_match('/^[A-Za-z0-9\-_ ]+$/', $pace)) {
                    throw new Exception('PACE contains invalid characters.');
                }
                if (!strtotime($due_date)) {
                    throw new Exception('Invalid due date.');
                }
                if ($expected_pages < 0) {
                    throw new Exception('Expected pages cannot be negative.');
                }
                $allowedStatus = ['Assigned','In Progress','Needs Correction','Completed','Self-Scored'];
                if (!in_array($status, $allowedStatus, true)) {
                    throw new Exception('Invalid status value.');
                }

                $chk = $pdo->prepare("
                    SELECT a.id FROM assignments a
                    JOIN teacher_student ts ON a.student_id = ts.student_id
                    WHERE a.id = ? AND ts.teacher_id = ?
                ");
                $chk->execute([$asm_id, $teacher_id]);
                if (!$chk->fetch()) throw new Exception('Access Denied: Not your assignment.');

                try {
                    $stmt = $pdo->prepare("UPDATE assignments SET pace = ?, score_key_id = ?, due_date = ?, expected_pages = ?, status = ? WHERE id = ?");
                    $stmt->execute([$pace, $score_key_id, $due_date, $expected_pages, $status, $asm_id]);
                } catch (PDOException $e) {
                    log_error('update_assignment DB error', ['asm_id' => $asm_id, 'error' => $e->getMessage()]);
                    throw new Exception('Database error while updating assignment.');
                }

                $stud = $pdo->prepare("SELECT student_id FROM assignments WHERE id = ?");
                $stud->execute([$asm_id]);
                $student = $stud->fetch();
                if ($student) {
                    send_notification($student['student_id'], $teacher_id, 'status_change', "Assignment updated: $pace");
                }
                $notice = "Assignment updated successfully.";
            }

            if ($action === 'upload_score_key') {
                $pace_title = trim($_POST['pace_title'] ?? '');
                if ($pace_title === '') throw new Exception('Please specify a destination PACE target.');

                if (!isset($_FILES['score_key_file'])) {
                    throw new Exception('No file uploaded.');
                }

                $files = normalize_uploaded_files($_FILES['score_key_file']);
                $destPaths = process_score_key_uploads($files);
                // Single PDF is stored as a plain path (backward compatible);
                // one or more images are stored as a JSON array, preserving upload order.
                $storedPath = build_stored_file_path($destPaths);

                $question_structure = [];
                $question_count = 0;
                try {
                    $stmt = $pdo->prepare("INSERT INTO score_keys (pace, file_path, version, is_published, question_count, question_structure) VALUES (?, ?, 'Draft-1.0', 0, ?, ?)");
                    $stmt->execute([$pace_title, $storedPath, $question_count, json_encode($question_structure)]);
                } catch (PDOException $e) {
                    log_error('upload_score_key DB error', ['pace' => $pace_title, 'error' => $e->getMessage()]);
                    throw new Exception('Database error while saving score key.');
                }
                $notice = count($destPaths) > 1
                    ? "Score key uploaded successfully (" . count($destPaths) . " images)."
                    : "Score key uploaded successfully.";
            }

            if ($action === 'reupload_score_key') {
                $sk_id = (int)($_POST['score_key_id'] ?? 0);
                $new_pace_title = trim($_POST['pace_title'] ?? '');
                if ($sk_id <= 0 || $new_pace_title === '') throw new Exception('Missing score key ID or new PACE title.');

                if (!isset($_FILES['score_key_file'])) {
                    throw new Exception('No file uploaded.');
                }

                $files = normalize_uploaded_files($_FILES['score_key_file']);
                $destPaths = process_score_key_uploads($files);
                $storedPath = build_stored_file_path($destPaths);

                $question_structure = [];
                $question_count = 0;
                try {
                    $stmt = $pdo->prepare("UPDATE score_keys SET pace = ?, file_path = ?, version = 'Draft-1.0', is_published = 0, question_count = ?, question_structure = ? WHERE id = ?");
                    $stmt->execute([$new_pace_title, $storedPath, $question_count, json_encode($question_structure), $sk_id]);
                } catch (PDOException $e) {
                    log_error('reupload_score_key DB error', ['sk_id' => $sk_id, 'error' => $e->getMessage()]);
                    throw new Exception('Database error while updating score key.');
                }
                $notice = count($destPaths) > 1
                    ? "Score key re‑uploaded successfully (" . count($destPaths) . " images)."
                    : "Score key re‑uploaded successfully.";
            }

            if ($action === 'publish_score_key') {
                $sk_id = (int)($_POST['score_key_id'] ?? 0);
                if ($sk_id <= 0) throw new Exception('Invalid score key ID.');
                try {
                    $stmt = $pdo->prepare("UPDATE score_keys SET is_published = 1, version = 'Prod-1.0' WHERE id = ?");
                    $stmt->execute([$sk_id]);
                } catch (PDOException $e) {
                    log_error('publish_score_key DB error', ['sk_id' => $sk_id, 'error' => $e->getMessage()]);
                    throw new Exception('Database error while publishing score key.');
                }
                $notice = "Score key status transitioned to published.";
            }

            if ($action === 'update_assignment_status') {
                $asm_id = (int)($_POST['assignment_id'] ?? 0);
                $target_status = $_POST['status'] ?? '';
                $allowedStatus = ['In Progress', 'Needs Correction', 'Completed'];
                if (!in_array($target_status, $allowedStatus, true)) {
                    throw new Exception('Invalid status value.');
                }
                try {
                    $stmt = $pdo->prepare("UPDATE assignments SET status = ? WHERE id = ?");
                    $stmt->execute([$target_status, $asm_id]);
                } catch (PDOException $e) {
                    log_error('update_assignment_status DB error', ['asm_id' => $asm_id, 'error' => $e->getMessage()]);
                    throw new Exception('Database error while updating status.');
                }

                $student_stmt = $pdo->prepare("SELECT student_id FROM assignments WHERE id = ?");
                $student_stmt->execute([$asm_id]);
                $student = $student_stmt->fetch();
                if ($student) {
                    send_notification($student['student_id'], $teacher_id, 'status_change', "Assignment status updated to: $target_status");
                }
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
                }
            }

            if ($action === 'send_msg') {
                $student_id = (int)($_POST['student_id'] ?? 0);
                $msg_body = trim($_POST['message'] ?? '');
                if ($student_id <= 0) throw new Exception('Invalid student.');

                $attachment_path = null;
                $attachment_name = null;
                $attachment_mime = null;
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['attachment'];
                    $maxSize = 5 * 1024 * 1024;
                    if ($file['size'] > $maxSize) throw new Exception('File too large (max 5MB).');
                    $uploadDir = 'uploads/chat/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = uniqid('chat_', true) . '.' . $ext;
                    $dest = $uploadDir . $filename;
                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        throw new Exception('Failed to save file.');
                    }
                    $attachment_path = $dest;
                    $attachment_name = $file['name'];
                    $attachment_mime = $file['type'] ?: mime_content_type($dest);
                }

                if ($msg_body === '' && !$attachment_path) throw new Exception('Message or file required.');

                try {
                    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body, attachment_path, attachment_name, attachment_mime) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$teacher_id, $student_id, $msg_body, $attachment_path, $attachment_name, $attachment_mime]);
                } catch (PDOException $e) {
                    log_error('send_msg DB error', ['student_id' => $student_id, 'error' => $e->getMessage()]);
                    throw new Exception('Database error while sending message.');
                }

                send_notification($student_id, $teacher_id, 'message', $attachment_path ? 'Sent an attachment' : 'Sent a message: ' . mb_strimwidth($msg_body, 0, 80, '...'));
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
                }
            }

        } catch (Throwable $ex) {
            $error = $ex->getMessage();
            log_error('POST action failed', [
                'action' => $action,
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine()
            ]);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $error]);
                exit;
            }
        }
    }
}

$students_stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, 
           COALESCE(COUNT(a.id), 0) as total_assignments,
           SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) as completed_count
    FROM users u
    JOIN teacher_student ts ON u.id = ts.student_id
    LEFT JOIN assignments a ON u.id = a.student_id
    WHERE ts.teacher_id = ?
    GROUP BY u.id ORDER BY u.full_name ASC
");
$students_stmt->execute([$teacher_id]);
$assigned_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

$assignments_stmt = $pdo->prepare("
    SELECT a.*, u.full_name as student_name, u.email as student_email 
    FROM assignments a
    JOIN users u ON a.student_id = u.id
    JOIN teacher_student ts ON u.id = ts.student_id
    WHERE ts.teacher_id = ?
    ORDER BY a.id DESC
");
$assignments_stmt->execute([$teacher_id]);
$all_assignments = $assignments_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_assigned_students = count($assigned_students);
$active_assignments_count = 0; $correction_queue_count = 0;
foreach($all_assignments as $asm) {
    if($asm['status'] === 'In Progress' || $asm['status'] === 'Assigned') $active_assignments_count++;
    if($asm['status'] === 'Needs Correction') $correction_queue_count++;
}

$score_keys = $pdo->query("SELECT * FROM score_keys ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="../WCIS_LOGO-1-removebg-preview.png" type="image/x-icon">
    <title>Teacher Dashboard</title>
    <style>
       :root {
	--bg: #0b1020;
	--bg-soft: #11162b;
	--card: #151b34;
	--muted: #aab1c7;
	--text: #e9ecf8;
	--primary: #6e8bff;
	--primary-700: #3a5dff;
	--danger: #ff5b6e;
	--success: #4cd4a8;
	--radius: 16px;
	--shadow-lg: 0 20px 60px rgba(0, 0, 0, .45);
	--shadow-md: 0 10px 30px rgba(0, 0, 0, .35);
}

* {
	box-sizing: border-box;
}

html,
body {
	height: 100%;
}

body {
	margin: 0;
	font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Helvetica, Arial;
	background:
		radial-gradient(1200px 800px at 80% -10%, rgba(110, 139, 255, .18), transparent),
		radial-gradient(900px 600px at -10% 110%, rgba(76, 212, 168, .08), transparent),
		var(--bg);
	color: var(--text);
}

.container-center {
	min-height: 100vh;
	display: grid;
	place-items: center;
	padding: 40px 16px;
}

.card {
	width: min(1100px, 96vw);
	background: linear-gradient(180deg, rgba(255, 255, 255, .04), rgba(255, 255, 255, .02));
	backdrop-filter: blur(10px);
	border: 1px solid rgba(255, 255, 255, .08);
	border-radius: var(--radius);
	box-shadow: var(--shadow-lg);
}

.card__header {
	padding: 20px 28px 0;
}

.card__body {
	padding: 28px;
}

.card__title {
	margin: 0;
	font-size: 22px;
	letter-spacing: .3px;
}

.card__sub {
	margin: 6px 0 0;
	color: var(--muted);
	font-size: 13px;
}

.header {
	display: flex;
	align-items: center;
	gap: 12px;
}

.logo {
	width: 70px;
	height: 70px;
	border-radius: 12px;
	background: linear-gradient(135deg, var(--primary), #9eaaff);
	box-shadow: 0 6px 24px rgba(110, 139, 255, .45);
	display: grid;
	place-items: center;
	overflow: hidden;
}

.logo img {
	width: 100%;
	height: 90%;
	object-fit: contain;
}

.form {
	display: grid;
	gap: 14px;
	margin-top: 18px;
}

.label {
	font-size: 13px;
	color: var(--muted);
	margin-bottom: 6px;
	display: block;
}

.input,
.select {
	width: 100%;
	padding: 12px 14px;
	border-radius: 12px;
	color: var(--text);
	background: #0f142a;
	border: 1px solid rgba(255, 255, 255, .08);
	outline: none;
}

.input:focus,
.select:focus {
	box-shadow: 0 0 0 3px rgba(110, 139, 255, .25);
	border-color: rgba(110, 139, 255, .6);
}

.btn {
	cursor: pointer;
	user-select: none;
	border: none;
	border-radius: 12px;
	padding: 12px 16px;
	font-weight: 700;
	display: inline-block;
	text-decoration: none;
	text-align: center;
}

.btn--primary {
	background: linear-gradient(180deg, var(--primary), var(--primary-700));
	color: #fff;
	box-shadow: 0 10px 30px rgba(110, 139, 255, .35);
}

.btn--ghost {
	background: transparent;
	color: var(--muted);
	border: 1px solid rgba(255, 255, 255, .12);
}

.btn--danger {
	background: linear-gradient(180deg, #ff7686, #ff475f);
	color: #fff;
}

.btn--success {
	background: linear-gradient(180deg, #67e3b5, #3ccf9e);
	color: #102016;
}

.alert {
	padding: 12px 14px;
	border-radius: 12px;
	font-size: 14px;
}

.alert--error {
	background: rgba(255, 91, 110, .12);
	border: 1px solid rgba(255, 91, 110, .3);
	color: #ffb3bd;
}

.alert--success {
	background: rgba(76, 212, 168, .12);
	border: 1px solid rgba(76, 212, 168, .3);
	color: #b8f3e1;
}

.table-wrap {
	overflow: auto;
	border-radius: 14px;
	border: 1px solid rgba(255, 255, 255, .08);
	margin-bottom: 24px;
}

.table {
	width: 100%;
	border-collapse: collapse;
}

.table th,
.table td {
	padding: 14px 12px;
	border-bottom: 1px solid rgba(255, 255, 255, .06);
	text-align: left;
	font-size: 14px;
}

.table th {
	color: var(--muted);
	font-weight: 600;
	background: rgba(255, 255, 255, .02);
}

.table tr:hover td {
	background: rgba(255, 255, 255, .03);
}

.toolbar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	margin: 16px 0 10px;
}

.badge {
	padding: 6px 10px;
	border-radius: 100px;
	font-size: 12px;
	border: 1px solid rgba(255, 255, 255, .12);
	color: var(--muted);
	display: inline-block;
}

.badge--assigned {
	color: #ffca28;
	border-color: #ffca28;
}

.badge--inprogress {
	color: #29b6f6;
	border-color: #29b6f6;
}

.badge--correction {
	color: #ef5350;
	border-color: #ef5350;
}

.badge--completed {
	color: #66bb6a;
	border-color: #66bb6a;
}

.modal {
	position: fixed;
	inset: 0;
	display: none;
	place-items: center;
	background: rgba(5, 8, 18, .55);
	z-index: 9999;
}

.modal.open {
	display: grid;
}

.modal__content {
	width: min(560px, 94vw);
	background: var(--card);
	border: 1px solid rgba(255, 255, 255, .12);
	border-radius: 18px;
	box-shadow: var(--shadow-md);
}

.modal__head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 18px 20px;
	border-bottom: 1px solid rgba(255, 255, 255, .06);
}

.modal__body {
	padding: 18px 20px;
	max-height: 75vh;
	overflow-y: auto;
}

.modal__title {
	margin: 0;
	font-size: 18px;
}

.logo img {
	transition: transform 0.3s ease, filter 0.3s ease;
}

.logo:hover img {
	transform: scale(1.08);
	filter: drop-shadow(0 0 12px rgba(110, 139, 255, 0.6));
}

@keyframes pulse {
	0% {
		transform: scale(1);
		filter: drop-shadow(0 0 8px rgba(110, 139, 255, 0.4));
	}
	50% {
		transform: scale(1.03);
		filter: drop-shadow(0 0 16px rgba(110, 139, 255, 0.6));
	}
	100% {
		transform: scale(1);
		filter: drop-shadow(0 0 8px rgba(110, 139, 255, 0.4));
	}
}

.logo.pulse img {
	animation: pulse 2.5s infinite ease-in-out;
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.stat-box {
	background: rgba(255, 255, 255, 0.02);
	border: 1px solid rgba(255, 255, 255, 0.06);
	border-radius: 12px;
	padding: 16px;
	text-align: center;
}

.stat-box h4 {
	margin: 0;
	font-size: 12px;
	color: var(--muted);
	text-transform: uppercase;
}

.stat-box p {
	margin: 8px 0 0;
	font-size: 24px;
	font-weight: 700;
	color: var(--primary);
}

.notif-bell {
	position: relative;
	cursor: pointer;
}

.notif-badge {
	position: absolute;
	top: -8px;
	right: -8px;
	background: var(--danger);
	border-radius: 50%;
	padding: 2px 6px;
	font-size: 12px;
	display: none;
}

.chat-day-sep {
	text-align: center;
	margin: 14px 0 10px;
}
.chat-day-sep span {
	background: rgba(255,255,255,.08);
	color: var(--muted);
	font-size: 11px;
	padding: 4px 12px;
	border-radius: 12px;
	letter-spacing: .2px;
}
.chat-row {
	display: flex;
	margin-bottom: 6px;
}
.chat-row.me { justify-content: flex-end; }
.chat-row.them { justify-content: flex-start; }
.chat-bubble {
	position: relative;
	max-width: 78%;
	padding: 7px 56px 18px 10px;
	border-radius: 10px;
	font-size: 13px;
	line-height: 1.4;
	text-align: left;
	word-wrap: break-word;
	box-shadow: 0 1px 2px rgba(0,0,0,.25);
}
.chat-bubble.me {
	background: linear-gradient(180deg, rgba(110,139,255,.28), rgba(110,139,255,.18));
	border-top-right-radius: 2px;
}
.chat-bubble.them {
	background: rgba(255,255,255,.06);
	border-top-left-radius: 2px;
}
.chat-sender {
	font-size: 11px;
	color: var(--muted);
	margin-bottom: 2px;
	font-weight: 600;
}
.chat-time {
	position: absolute;
	bottom: 4px;
	right: 10px;
	font-size: 10px;
	color: rgba(233,236,248,.55);
	white-space: nowrap;
}
.chat-bubble img {
	max-width: 220px;
	max-height: 160px;
	border-radius: 8px;
	margin-top: 4px;
	display: block;
}
.chat-bubble audio {
	max-width: 230px;
	margin-top: 4px;
	display: block;
	height: 36px;
}
.chat-bubble a.btn {
	margin-top: 4px;
}

.voice-recorder-bar {
	display: flex;
	align-items: center;
	gap: 10px;
	background: rgba(255,255,255,.04);
	border: 1px solid rgba(255,255,255,.08);
	border-radius: 24px;
	padding: 7px 10px 7px 6px;
}
.voice-rec-cancel {
	background: none;
	border: none;
	color: var(--danger);
	font-size: 16px;
	cursor: pointer;
	padding: 4px 8px;
	line-height: 1;
}
.voice-rec-indicator {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: var(--text);
	min-width: 52px;
}
.voice-rec-pause {
	background: none;
	border: none;
	cursor: pointer;
	padding: 2px 4px;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--text);
	font-size: 11px;
	line-height: 1;
}
.voice-rec-dot {
	width: 9px;
	height: 9px;
	border-radius: 50%;
	background: var(--danger);
	animation: voiceRecPulse 1s infinite;
	flex-shrink: 0;
}
@keyframes voiceRecPulse {
	0%, 100% { opacity: 1; }
	50% { opacity: .25; }
}
.voice-rec-wave {
	flex: 1;
	display: flex;
	align-items: center;
	gap: 3px;
	height: 22px;
	overflow: hidden;
}
.voice-rec-wave span {
	width: 3px;
	min-height: 4px;
	border-radius: 2px;
	background: var(--primary);
	animation: voiceRecWave 1.1s ease-in-out infinite;
}
.voice-rec-wave span:nth-child(1) { height: 30%; animation-delay: 0s; }
.voice-rec-wave span:nth-child(2) { height: 70%; animation-delay: .1s; }
.voice-rec-wave span:nth-child(3) { height: 45%; animation-delay: .2s; }
.voice-rec-wave span:nth-child(4) { height: 90%; animation-delay: .3s; }
.voice-rec-wave span:nth-child(5) { height: 55%; animation-delay: .4s; }
.voice-rec-wave span:nth-child(6) { height: 80%; animation-delay: .5s; }
.voice-rec-wave span:nth-child(7) { height: 35%; animation-delay: .6s; }
.voice-rec-wave span:nth-child(8) { height: 65%; animation-delay: .7s; }
@keyframes voiceRecWave {
	0%, 100% { transform: scaleY(.35); }
	50% { transform: scaleY(1); }
}
.voice-rec-wave.paused span {
	animation-play-state: paused;
}
.voice-rec-replay {
	background: rgba(255,255,255,.12);
	border: none;
	color: var(--text);
	width: 26px;
	height: 26px;
	min-width: 26px;
	border-radius: 50%;
	cursor: pointer;
	font-size: 11px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.voice-rec-replay:hover {
	background: rgba(255,255,255,.2);
}
.voice-rec-send {
	background: var(--primary);
	border: none;
	color: #fff;
	width: 32px;
	height: 32px;
	min-width: 32px;
	border-radius: 50%;
	cursor: pointer;
	font-size: 14px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.voice-msg-player {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 4px;
	min-width: 200px;
}
.vmp-btn {
	background: rgba(255,255,255,.12);
	border: none;
	color: var(--text);
	width: 28px;
	height: 28px;
	min-width: 28px;
	border-radius: 50%;
	cursor: pointer;
	font-size: 12px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.vmp-btn:hover { background: rgba(255,255,255,.2); }
.vmp-progress {
	flex: 1;
	height: 4px;
	background: rgba(255,255,255,.15);
	border-radius: 2px;
	cursor: pointer;
	position: relative;
}
.vmp-progress-fill {
	height: 100%;
	width: 0%;
	background: var(--primary);
	border-radius: 2px;
}
.vmp-time {
	font-size: 10px;
	color: rgba(233,236,248,.6);
	min-width: 32px;
	text-align: right;
	flex-shrink: 0;
}

.attach-media-wrap {
	position: relative;
	display: inline-block;
	max-width: 100%;
}
.attach-download-icon {
	position: absolute;
	top: 6px;
	right: 6px;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	background: rgba(0,0,0,.55);
	color: #fff;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 11px;
	text-decoration: none;
	line-height: 1;
}
.attach-download-icon:hover {
	background: rgba(0,0,0,.75);
}
.vmp-download {
	width: 22px;
	height: 22px;
	min-width: 22px;
	border-radius: 50%;
	background: rgba(255,255,255,.12);
	color: var(--text);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 10px;
	text-decoration: none;
	flex-shrink: 0;
}
.vmp-download:hover {
	background: rgba(255,255,255,.2);
}

.hidden {
	display: none;
}
    
/* ============ Responsive & Fun Animations (added) ============ */
@keyframes fadeInUp { from { opacity:0; transform:translateY(14px);} to { opacity:1; transform:translateY(0);} }
@keyframes modalPop { from { opacity:0; transform:scale(.92);} to { opacity:1; transform:scale(1);} }
@keyframes badgePop { 0%{transform:scale(.8);} 60%{transform:scale(1.08);} 100%{transform:scale(1);} }
@keyframes shakeX { 10%,90%{transform:translateX(-1px);} 20%,80%{transform:translateX(2px);} 30%,50%,70%{transform:translateX(-4px);} 40%,60%{transform:translateX(4px);} }
@keyframes rowIn { from { opacity:0; transform:translateX(-6px);} to { opacity:1; transform:translateX(0);} }
@keyframes bellRing { 0%,100%{transform:rotate(0);} 10%,30%{transform:rotate(-12deg);} 20%,40%{transform:rotate(12deg);} 50%{transform:rotate(0);} }

.card { animation: fadeInUp .5s ease both; }
.btn { transition: transform .18s ease, box-shadow .18s ease, filter .18s ease; }
.btn:hover { transform: translateY(-2px); filter: brightness(1.08); }
.btn:active { transform: translateY(0) scale(.96); }
.input, .select { transition: box-shadow .25s ease, border-color .25s ease, transform .15s ease; }
.input:focus, .select:focus { transform: translateY(-1px); }
.badge { animation: badgePop .35s ease; }
.alert--error { animation: shakeX .4s ease; }
.modal__content { animation: modalPop .25s ease; }
.table-wrap { -webkit-overflow-scrolling: touch; }
.table tbody tr { animation: rowIn .35s ease both; transition: background .2s ease; }
.logo { transition: transform .3s ease, filter .3s ease; }
.notif-bell:hover { animation: bellRing .5s ease; }
.chat-bubble { transition: transform .15s ease; }
.chat-row:hover .chat-bubble { transform: translateY(-1px); }

/* ============ Responsive breakpoints (added) ============ */
@media (max-width: 900px) {
    .container-center { padding: 20px 12px; }
    .card { width: 100%; }
    .card__body, .card__header { padding: 18px; }
    .row { grid-template-columns: 1fr; }
    .toolbar { flex-wrap: wrap; gap: 10px; }
    .header { flex-wrap: wrap; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 640px) {
    .card__title { font-size: 19px; }
    .card__sub { font-size: 12px; }
    .btn { padding: 10px 14px; font-size: 13.5px; }
    .modal__content { width: 96vw; max-height: 88vh; overflow: auto; }
    .modal__body { padding: 14px 16px; }
    .table th, .table td { padding: 10px 8px; font-size: 12.5px; }
    .logo { width: 56px; height: 56px; }
    .chat-bubble { max-width: 88%; }
    header .header > div[style*="display:flex"] { flex-wrap: wrap; }
}

@media (max-width: 420px) {
    .card__body { padding: 14px; }
    .toolbar { flex-direction: column; align-items: stretch; }
    .toolbar .btn { width: 100%; }
    .modal__head { padding: 14px 16px; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
}

</style>
</head>
<body>
<main class="container-center">
<section class="card">
    <header class="card__header">
        <div class="header" style="justify-content: space-between; width:100%">
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="logo pulse"><img src="../WCIS_LOGO-1-removebg-preview.png" alt="WCIS Logo"></div>
                <div>
                    <h1 class="card__title">Teacher Dashboard</h1>
                    <p class="card__sub">Academic Workflow, OCR Extraction & Student Assignments</p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <span class="notif-bell" onclick="openNotifications()">
                    🔔 <span id="notifCount" class="notif-badge">0</span>
                </span>
                <a class="btn btn--ghost" href="../auth/logout.php">Logout</a>
            </div>
        </div>
    </header>
    <div class="card__body">
        <?php if($notice): ?>
        <div class="alert alert--success" style="margin-bottom:16px;"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>
        <?php if($error): ?>
        <div class="alert alert--error" style="margin-bottom:16px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <div class="stats-grid">
            <div class="stat-box"><h4>Assigned Students</h4><p><?= $total_assigned_students ?></p></div>
            <div class="stat-box"><h4>Active Tasks</h4><p><?= $active_assignments_count ?></p></div>
            <div class="stat-box"><h4>Corrections Required</h4><p><?= $correction_queue_count ?></p></div>
        </div>
        <div class="toolbar"><h3 style="margin:0;">Assigned Students</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>ID</th><th>Student Name</th><th>Email</th><th>Total Tasks</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($assigned_students as $st): ?>
                <tr>
                    <td><?= $st['id'] ?></td>
                    <td><?= htmlspecialchars($st['full_name']) ?></td>
                    <td><?= htmlspecialchars($st['email']) ?></td>
                    <td><?= $st['total_assignments'] ?> registered</td>
                    <td>
                        <button class="btn btn--primary" onclick="openPaceModal(<?= $st['id'] ?>, '<?= htmlspecialchars($st['full_name'], ENT_QUOTES) ?>')">Assign PACE</button>
                        <button class="btn btn--ghost" onclick="openChatModal(<?= $st['id'] ?>, '<?= htmlspecialchars($st['full_name'], ENT_QUOTES) ?>')">Message</button>
                    </td>
                </tr>
                <?php endforeach; if(empty($assigned_students)): ?>
                <tr><td colspan="5" style="text-align:center; color:var(--muted);">No mapped student connections detected.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="toolbar"><h3 style="margin:0;">Active Academic Assignments</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>ID</th><th>Student</th><th>PACE Code</th><th>Due Date</th><th>Workflow Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($all_assignments as $asm): ?>
                <tr>
                    <td><?= $asm['id'] ?></td>
                    <td><?= htmlspecialchars($asm['student_name']) ?></td>
                    <td><?= htmlspecialchars($asm['pace']) ?></td>
                    <td><?= htmlspecialchars($asm['due_date']) ?></td>
                    <td><span class="badge badge--<?= strtolower(str_replace(' ', '', $asm['status'])) ?>"><?= $asm['status'] ?></span></td>
                    <td>
                        <button class="btn btn--ghost" onclick="openAssignmentViewer(<?= $asm['id'] ?>)">Review</button>
                        <button class="btn btn--ghost" onclick="openEditAssignmentModal(<?= $asm['id'] ?>)">Edit</button>
                    </td>
                </tr>
                <?php endforeach; if(empty($all_assignments)): ?>
                <tr><td colspan="6" style="text-align:center; color:var(--muted);">No active curriculum assignments mapped.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="toolbar">
            <h3 style="margin:0;">Score Key Configuration</h3>
            <button class="btn btn--primary" onclick="openUploadKeyModal()">+ Upload Score Key Document</button>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>ID</th><th>Score Key Name</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($score_keys as $sk): ?>
                <tr>
                    <td><?= $sk['id'] ?></td>
                    <td><?= htmlspecialchars($sk['pace']) ?></td>
                    <td>
                        <?php if(!$sk['is_published']): ?>
                        <form method="POST" style="margin:0; display:inline;">
                            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="publish_score_key">
                            <input type="hidden" name="score_key_id" value="<?= $sk['id'] ?>">
                            <button type="submit" class="btn btn--success">Approve & Publish</button>
                        </form>
                        <?php endif; ?>
                        <button class="btn btn--ghost" onclick="openReuploadKeyModal(<?= $sk['id'] ?>, '<?= htmlspecialchars($sk['pace'], ENT_QUOTES) ?>')">Edit</button>
                    </td>
                </tr>
                <?php endforeach; if(empty($score_keys)): ?>
                <tr><td colspan="6" style="text-align:center; color:var(--muted);">No structural templates compiled yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal" id="paceModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title" id="paceModalTitle">Curriculum Assignment</h3>
            <button class="btn btn--ghost" onclick="closeModal('paceModal')">✕</button>
        </div>
        <div class="modal__body">
            <form method="POST" class="form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="assign_pace">
                <input type="hidden" name="student_id" id="pace_student_id">
                <label class="label">PACE Name</label>
                <input class="input" name="pace" placeholder="e.g. PACE-1082" required>
                <label class="label">Score Key</label>
                <select class="select" name="score_key_id" required>
                    <option value="">-- Assign Score Key --</option>
                    <?php foreach($score_keys as $key): if($key['is_published']): ?>
                    <option value="<?= $key['id'] ?>"><?= htmlspecialchars($key['pace']) ?> (<?= htmlspecialchars($key['version']) ?>)</option>
                    <?php endif; endforeach; ?>
                </select>
                <label class="label">Academic Deadline Milestone</label>
                <input class="input" type="date" name="due_date" required>
                <label class="label">Expected Number of Answer Pages (optional)</label>
                <input class="input" type="number" name="expected_pages" min="0" value="0">
                <button type="submit" class="btn btn--success" style="margin-top:12px">Register Assignment Parameters</button>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="editAssignmentModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Edit Assignment</h3>
            <button class="btn btn--ghost" onclick="closeModal('editAssignmentModal')">✕</button>
        </div>
        <div class="modal__body">
            <form method="POST" class="form" id="editAssignmentForm">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_assignment">
                <input type="hidden" name="assignment_id" id="edit_asm_id">
                <label class="label">PACE Name</label>
                <input class="input" name="pace" id="edit_pace" required>
                <label class="label">Score Key</label>
                <select class="select" name="score_key_id" id="edit_score_key_id" required>
                    <option value="">-- Select Score Key --</option>
                    <?php foreach($score_keys as $key): if($key['is_published']): ?>
                    <option value="<?= $key['id'] ?>"><?= htmlspecialchars($key['pace']) ?> (<?= htmlspecialchars($key['version']) ?>)</option>
                    <?php endif; endforeach; ?>
                </select>
                <label class="label">Due Date</label>
                <input class="input" type="date" name="due_date" id="edit_due_date" required>
                <label class="label">Expected Pages</label>
                <input class="input" type="number" name="expected_pages" id="edit_expected_pages" min="0">
                <label class="label">Status</label>
                <select class="select" name="status" id="edit_status">
                    <option value="Assigned">Assigned</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Needs Correction">Needs Correction</option>
                    <option value="Completed">Completed</option>
                    <option value="Self-Scored">Self-Scored</option>
                </select>
                <button type="submit" class="btn btn--success" style="margin-top:12px">Update Assignment</button>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="uploadKeyModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Upload Score Key Document</h3>
            <button class="btn btn--ghost" onclick="closeModal('uploadKeyModal')">✕</button>
        </div>
        <div class="modal__body">
            <form method="POST" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="upload_score_key">
                <label class="label">PACE Title</label>
                <input class="input" name="pace_title" placeholder="e.g. Mathematics 1021" required>
                <label class="label">Score Key (Supported: PDF, or multiple PNG/JPG images)</label>
                <input type="file" class="input" name="score_key_file[]" accept=".pdf,image/*" required multiple>
                <p class="helper-text">Select a single PDF, or select multiple images (they will be stored and shown to the student in the order you select them).</p>
                <button type="submit" class="btn btn--primary" style="margin-top:12px">Upload Score Key</button>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="reuploadKeyModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Re‑upload Score Key</h3>
            <button class="btn btn--ghost" onclick="closeModal('reuploadKeyModal')">✕</button>
        </div>
        <div class="modal__body">
            <form method="POST" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reupload_score_key">
                <input type="hidden" name="score_key_id" id="reupload_sk_id">
                <label class="label">PACE Title</label>
                <input class="input" name="pace_title" id="reupload_pace_title" placeholder="e.g. Mathematics 1021" required>
                <label class="label">New Score Key File (PDF, or multiple PNG/JPG images)</label>
                <input type="file" class="input" name="score_key_file[]" accept=".pdf,image/*" required multiple>
                <p class="helper-text">Select a single PDF, or select multiple images (they will be stored and shown to the student in the order you select them).</p>
                <p class="helper-text">Uploading will replace the existing file.</p>
                <button type="submit" class="btn btn--primary" style="margin-top:12px">Re‑upload </button>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="assignmentViewModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Assignment Progress Manifest</h3>
            <button class="btn btn--ghost" onclick="closeModal('assignmentViewModal')">✕</button>
        </div>
        <div class="modal__body">
            <div id="assignmentDetailsContent" style="display:grid; gap:12px; font-size:14px;"></div>
            <hr style="border:0; border-top:1px solid rgba(255,255,255,0.08); margin:16px 0;">
            <form id="assignmentLifecycleForm" class="form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_assignment_status">
                <input type="hidden" name="assignment_id" id="lifecycle_assignment_id">
                <label class="label">Update Status</label>
                <select class="select" name="status" required>
                    <option value="In Progress">In Progress</option>
                    <option value="Needs Correction">Needs Correction (Flag Error Loop)</option>
                    <option value="Completed">Approve & Mark Completed</option>
                </select>
                <button type="submit" class="btn btn--success" style="margin-top:8px">Commit State Change</button>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="chatModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title" id="chatModalTitle">Channel Messages Terminal</h3>
            <button class="btn btn--ghost" onclick="closeModal('chatModal')">✕</button>
        </div>
        <div class="modal__body" style="display:flex; flex-direction:column; gap:12px;">
            <div id="chatBoxContainer" style="height:220px; overflow-y:auto; background:#0f142a; padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,0.06);"></div>
            <form id="chatDispatchForm" class="form" enctype="multipart/form-data" method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="send_msg">
                <input type="hidden" name="student_id" id="chat_student_id">
                <div id="chatComposerNormal" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <input class="input" name="message" placeholder="Type a message..." style="flex:1; min-width:150px;">
                    <label class="btn btn--ghost" style="cursor:pointer; padding:8px 12px;"><img src="paper-clip.png" style="width:16px; height:16px; vertical-align:middle;">
                        <input type="file" name="attachment" style="display:none;" onchange="this.form.querySelector('input[name=message]').placeholder='File attached'">
                    </label>
                    <button type="button" class="btn btn--ghost" id="teacherVoiceRecordBtn" style="padding:8px 12px;">🎤</button>
                    <button type="submit" class="btn btn--primary">Send</button>
                </div>
                <div id="chatComposerRecording" class="voice-recorder-bar" style="display:none;">
                    <button type="button" class="voice-rec-cancel" id="teacherVoiceCancelBtn" title="Cancel">🗑️</button>
                    <div class="voice-rec-indicator">
                        <button type="button" class="voice-rec-pause" id="teacherVoicePauseBtn" title="Pause"><span class="voice-rec-dot" id="teacherVoiceRecDot"></span></button>
                        <span class="voice-rec-timer" id="teacherVoiceTimer">0:00</span>
                    </div>
                    <div class="voice-rec-wave" id="teacherVoiceRecWave">
                        <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                    </div>
                    <button type="button" class="voice-rec-replay" id="teacherVoiceReplayBtn" title="Play recording" style="display:none;">▶</button>
                    <button type="button" class="voice-rec-send" id="teacherVoiceSendBtn" title="Send">➤</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="notifModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Notifications</h3>
            <button class="btn btn--ghost" onclick="closeModal('notifModal')">✕</button>
        </div>
        <div class="modal__body">
            <div id="notifList" style="display:grid; gap:10px;"></div>
        </div>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openPaceModal(studentId, name) {
    document.getElementById('pace_student_id').value = studentId;
    document.getElementById('paceModalTitle').innerText = "Assign PACE to " + name;
    openModal('paceModal');
}
function openUploadKeyModal() {
    openModal('uploadKeyModal');
}

function openReuploadKeyModal(skId, currentPace) {
    document.getElementById('reupload_sk_id').value = skId;
    document.getElementById('reupload_pace_title').value = currentPace;
    openModal('reuploadKeyModal');
}

async function openEditAssignmentModal(asmId) {
    let r = await fetch('?action=get_assignment_for_edit&assignment_id=' + asmId);
    let data = await r.json();
    if (data.error) { alert(data.error); return; }
    document.getElementById('edit_asm_id').value = asmId;
    document.getElementById('edit_pace').value = data.pace || '';
    document.getElementById('edit_score_key_id').value = data.score_key_id || '';
    document.getElementById('edit_due_date').value = data.due_date || '';
    document.getElementById('edit_expected_pages').value = data.expected_pages || 0;
    document.getElementById('edit_status').value = data.status || 'Assigned';
    openModal('editAssignmentModal');
}
document.getElementById('editAssignmentForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    let fd = new FormData(e.target);
    let r = await fetch('', {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    let res = await r.json();
    if (res.status === 'success') { location.reload(); }
    else { alert(res.message || 'Update failed'); }
});

async function openAssignmentViewer(asmId) {
    let r = await fetch('?action=get_assignment_details&assignment_id=' + asmId);
    let data = await r.json();
    if(!data.error) {
        document.getElementById('lifecycle_assignment_id').value = asmId;
        let container = document.getElementById('assignmentDetailsContent');
        container.innerHTML = `
            <div><strong>Student Target:</strong> ${data.student_name}</div>
            <div><strong>PACE Name:</strong> ${data.pace}</div>
            <div><strong>Due Milestone:</strong> ${data.due_date}</div>
            <div><strong>Score Key:</strong> ${data.score_key_version || 'No Score Key Attached'}</div>
            <div><strong>Current Status:</strong> <span class="badge">${data.status}</span></div>
        `;
        openModal('assignmentViewModal');
    }
}
document.getElementById('assignmentLifecycleForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    let d = new FormData(e.target);
    let r = await fetch('', {method: 'POST', body: d, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    let res = await r.json();
    if(res.status === 'success') location.reload();
    else alert(res.message || 'Operation Failed');
});

let teacherMediaRecorder;
let teacherAudioChunks = [];
let teacherVoiceStream = null;
let teacherVoiceTimerInterval = null;
let teacherVoiceStartTime = 0;
let teacherVoiceElapsedBeforePause = 0;
let teacherVoiceCancelled = false;
let teacherVoicePaused = false;
let teacherVoicePreviewAudio = null;

function teacherVoiceFormatTimer(totalSeconds) {
    let m = Math.floor(totalSeconds / 60);
    let s = totalSeconds % 60;
    return m + ':' + String(s).padStart(2, '0');
}

function teacherVoiceResetUI() {
    document.getElementById('teacherVoicePauseBtn').innerHTML = '<span class="voice-rec-dot" id="teacherVoiceRecDot"></span>';
    document.getElementById('teacherVoicePauseBtn').title = 'Pause';
    document.getElementById('teacherVoiceRecWave').classList.remove('paused');
    document.getElementById('teacherVoiceReplayBtn').style.display = 'none';
    document.getElementById('teacherVoiceReplayBtn').textContent = '▶';
    teacherVoicePaused = false;
    teacherVoiceElapsedBeforePause = 0;
    if (teacherVoicePreviewAudio) {
        teacherVoicePreviewAudio.pause();
        teacherVoicePreviewAudio = null;
    }
}

async function startTeacherVoiceRecording() {
    try {
        teacherVoiceStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (err) {
        alert('Microphone access denied: ' + err.message);
        return;
    }
    teacherVoiceCancelled = false;
    teacherAudioChunks = [];
    teacherVoiceResetUI();
    teacherMediaRecorder = new MediaRecorder(teacherVoiceStream);
    teacherMediaRecorder.ondataavailable = event => { if (event.data && event.data.size > 0) teacherAudioChunks.push(event.data); };
    teacherMediaRecorder.onstop = () => {
        teacherVoiceStream.getTracks().forEach(track => track.stop());
        clearInterval(teacherVoiceTimerInterval);
        document.getElementById('chatComposerRecording').style.display = 'none';
        document.getElementById('chatComposerNormal').style.display = 'flex';
        teacherVoiceResetUI();
        if (teacherVoiceCancelled || teacherAudioChunks.length === 0) return;
        const audioBlob = new Blob(teacherAudioChunks, { type: 'audio/webm' });
        sendTeacherVoiceMessage(audioBlob);
    };
    teacherMediaRecorder.start();

    document.getElementById('chatComposerNormal').style.display = 'none';
    document.getElementById('chatComposerRecording').style.display = 'flex';
    teacherVoiceStartTime = Date.now();
    document.getElementById('teacherVoiceTimer').textContent = '0:00';
    teacherVoiceTimerInterval = setInterval(() => {
        const sec = Math.floor((teacherVoiceElapsedBeforePause + (Date.now() - teacherVoiceStartTime)) / 1000);
        document.getElementById('teacherVoiceTimer').textContent = teacherVoiceFormatTimer(sec);
    }, 250);
}

function toggleTeacherVoicePause() {
    if (!teacherMediaRecorder) return;
    if (!teacherVoicePaused) {
        if (teacherMediaRecorder.state === 'recording') teacherMediaRecorder.pause();
        teacherVoicePaused = true;
        teacherVoiceElapsedBeforePause += Date.now() - teacherVoiceStartTime;
        clearInterval(teacherVoiceTimerInterval);
        document.getElementById('teacherVoicePauseBtn').innerHTML = '▶';
        document.getElementById('teacherVoicePauseBtn').title = 'Resume';
        document.getElementById('teacherVoiceRecWave').classList.add('paused');
        document.getElementById('teacherVoiceReplayBtn').style.display = 'inline-flex';
    } else {
        if (teacherVoicePreviewAudio) { teacherVoicePreviewAudio.pause(); teacherVoicePreviewAudio = null; }
        document.getElementById('teacherVoiceReplayBtn').textContent = '▶';
        if (teacherMediaRecorder.state === 'paused') teacherMediaRecorder.resume();
        teacherVoicePaused = false;
        teacherVoiceStartTime = Date.now();
        teacherVoiceTimerInterval = setInterval(() => {
            const sec = Math.floor((teacherVoiceElapsedBeforePause + (Date.now() - teacherVoiceStartTime)) / 1000);
            document.getElementById('teacherVoiceTimer').textContent = teacherVoiceFormatTimer(sec);
        }, 250);
        document.getElementById('teacherVoicePauseBtn').innerHTML = '<span class="voice-rec-dot" id="teacherVoiceRecDot"></span>';
        document.getElementById('teacherVoicePauseBtn').title = 'Pause';
        document.getElementById('teacherVoiceRecWave').classList.remove('paused');
        document.getElementById('teacherVoiceReplayBtn').style.display = 'none';
    }
}

function replayTeacherVoiceRecording() {
    if (!teacherVoicePaused || !teacherMediaRecorder) return;
    const replayBtn = document.getElementById('teacherVoiceReplayBtn');
    if (teacherVoicePreviewAudio && !teacherVoicePreviewAudio.paused) {
        teacherVoicePreviewAudio.pause();
        teacherVoicePreviewAudio = null;
        replayBtn.textContent = '▶';
        return;
    }
    const flushAndPlay = () => {
        if (teacherAudioChunks.length === 0) return;
        const blob = new Blob(teacherAudioChunks, { type: 'audio/webm' });
        const url = URL.createObjectURL(blob);
        teacherVoicePreviewAudio = new Audio(url);
        replayBtn.textContent = '⏸';
        teacherVoicePreviewAudio.play();
        teacherVoicePreviewAudio.onended = () => { replayBtn.textContent = '▶'; URL.revokeObjectURL(url); teacherVoicePreviewAudio = null; };
    };
    if (teacherMediaRecorder.state === 'paused') {
        try { teacherMediaRecorder.requestData(); } catch (e) {}
        setTimeout(flushAndPlay, 60);
    } else {
        flushAndPlay();
    }
}

function stopTeacherVoiceRecording(cancelled) {
    teacherVoiceCancelled = cancelled;
    if (teacherMediaRecorder && (teacherMediaRecorder.state === 'recording' || teacherMediaRecorder.state === 'paused')) {
        teacherMediaRecorder.stop();
    }
}

async function sendTeacherVoiceMessage(blob) {
    const file = new File([blob], 'voice_message.webm', { type: 'audio/webm' });
    const fd = new FormData();
    fd.append('csrf', document.querySelector('#chatDispatchForm input[name="csrf"]').value);
    fd.append('action', 'send_msg');
    fd.append('student_id', document.getElementById('chat_student_id').value);
    fd.append('message', '');
    fd.append('attachment', file);
    try {
        const r = await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const res = await r.json();
        if (res.status === 'success') {
            const sId = document.getElementById('chat_student_id').value;
            const studentName = document.getElementById('chatModalTitle').innerText.replace("Chat: ", "");
            openChatModal(sId, studentName);
        } else {
            alert(res.message || 'Failed to send voice message.');
        }
    } catch (err) {
        alert('Failed to send voice message.');
    }
}

document.getElementById('teacherVoiceRecordBtn').addEventListener('click', startTeacherVoiceRecording);
document.getElementById('teacherVoiceCancelBtn').addEventListener('click', () => stopTeacherVoiceRecording(true));
document.getElementById('teacherVoiceSendBtn').addEventListener('click', () => stopTeacherVoiceRecording(false));
document.getElementById('teacherVoicePauseBtn').addEventListener('click', toggleTeacherVoicePause);
document.getElementById('teacherVoiceReplayBtn').addEventListener('click', replayTeacherVoiceRecording);

function chatParseDate(ts) {
    return new Date(String(ts).replace(' ', 'T'));
}
function chatFormatTime(ts) {
    let d = chatParseDate(ts);
    let h = d.getHours(), m = d.getMinutes();
    let ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    return h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
}
function chatFormatDaySeparator(ts) {
    let d = chatParseDate(ts);
    let today = new Date();
    let yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    let sameDay = (a, b) => a.toDateString() === b.toDateString();
    if (sameDay(d, today)) return 'Today';
    if (sameDay(d, yesterday)) return 'Yesterday';
    let opts = { day: 'numeric', month: 'short' };
    if (d.getFullYear() !== today.getFullYear()) opts.year = 'numeric';
    return d.toLocaleDateString(undefined, opts);
}

function vmpFormatTime(sec) {
    if (!isFinite(sec) || sec < 0) sec = 0;
    let m = Math.floor(sec / 60), s = Math.floor(sec % 60);
    return m + ':' + String(s).padStart(2, '0');
}

function initVoiceMessagePlayers(root) {
    root.querySelectorAll('.voice-msg-player').forEach(player => {
        const audio = player.querySelector('.vmp-audio');
        const playBtn = player.querySelector('.vmp-playpause');
        const replayBtn = player.querySelector('.vmp-replay');
        const progress = player.querySelector('.vmp-progress');
        const fill = player.querySelector('.vmp-progress-fill');
        const timeEl = player.querySelector('.vmp-time');

        audio.addEventListener('loadedmetadata', () => {
            if (isFinite(audio.duration)) timeEl.textContent = vmpFormatTime(audio.duration);
        });
        audio.addEventListener('timeupdate', () => {
            if (audio.duration) fill.style.width = (audio.currentTime / audio.duration * 100) + '%';
            timeEl.textContent = vmpFormatTime(audio.currentTime);
        });
        audio.addEventListener('ended', () => {
            playBtn.textContent = '▶';
            fill.style.width = '0%';
            timeEl.textContent = vmpFormatTime(audio.duration || 0);
        });

        playBtn.addEventListener('click', () => {
            document.querySelectorAll('.vmp-audio').forEach(a => {
                if (a !== audio && !a.paused) {
                    a.pause();
                    const otherPlayer = a.closest('.voice-msg-player');
                    if (otherPlayer) otherPlayer.querySelector('.vmp-playpause').textContent = '▶';
                }
            });
            if (audio.paused) {
                audio.play();
                playBtn.textContent = '⏸';
            } else {
                audio.pause();
                playBtn.textContent = '▶';
            }
        });

        replayBtn.addEventListener('click', () => {
            audio.currentTime = 0;
            audio.play();
            playBtn.textContent = '⏸';
        });

        progress.addEventListener('click', (e) => {
            if (!audio.duration) return;
            const rect = progress.getBoundingClientRect();
            const ratio = Math.min(Math.max((e.clientX - rect.left) / rect.width, 0), 1);
            audio.currentTime = ratio * audio.duration;
        });
    });
}

async function openChatModal(studentId, studentName) {
    document.getElementById('chat_student_id').value = studentId;
    document.getElementById('chatModalTitle').innerText = "Chat: " + studentName;
    openModal('chatModal');                       

    let box = document.getElementById('chatBoxContainer');
    box.innerHTML = 'Loading conversations thread...';
    let r = await fetch('?action=get_chat&student_id=' + studentId);
    let messages = await r.json();
    let markup = '';
    let lastDay = null;
    messages.forEach(m => {
        let day = chatFormatDaySeparator(m.created_at);
        if (day !== lastDay) {
            markup += `<div class="chat-day-sep"><span>${day}</span></div>`;
            lastDay = day;
        }
        let isMe = m.sender_id == <?= $teacher_id ?>;
        let attachmentHtml = '';
        if (m.attachment_path) {
            const ext = m.attachment_path.split('.').pop().toLowerCase();
            const fileName = m.attachment_name || 'download';
            let downloadIcon = `<a href="${m.attachment_path}" download="${fileName}" class="attach-download-icon" title="Download">⬇</a>`;
            if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
                attachmentHtml = `<div class="attach-media-wrap"><img src="${m.attachment_path}" alt="attachment">${downloadIcon}</div>`;
            } else if (['mp3','wav','ogg','webm'].includes(ext) || (m.attachment_mime && m.attachment_mime.startsWith('audio/'))) {
                attachmentHtml = `<div class="voice-msg-player">
                    <button type="button" class="vmp-btn vmp-playpause" title="Play">▶</button>
                    <div class="vmp-progress"><div class="vmp-progress-fill"></div></div>
                    <span class="vmp-time">0:00</span>
                    <button type="button" class="vmp-btn vmp-replay" title="Replay">⟲</button>
                    <audio class="vmp-audio" src="${m.attachment_path}" preload="metadata" style="display:none;"></audio>
                    <a href="${m.attachment_path}" download="${fileName}" class="vmp-download" title="Download">⬇</a>
                </div>`;
            } else {
                attachmentHtml = `<div><a href="${m.attachment_path}" download="${fileName}" class="btn btn--ghost">📎 ${fileName}</a></div>`;
            }
        }
        markup += `<div class="chat-row ${isMe ? 'me' : 'them'}">
            <div class="chat-bubble ${isMe ? 'me' : 'them'}">
                ${isMe ? '' : `<div class="chat-sender">${m.sender_name}</div>`}
                ${m.body || ''}
                ${attachmentHtml}
                <span class="chat-time">${chatFormatTime(m.created_at)}</span>
            </div>
        </div>`;
    });
    box.innerHTML = markup || '<div style="color:var(--muted); font-size:12px; text-align:center;">No direct thread interaction logged.</div>';
    initVoiceMessagePlayers(box);

    void box.offsetHeight;                          
    requestAnimationFrame(() => {
        box.scrollTop = box.scrollHeight;
    });
}

document.getElementById('chatDispatchForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    const sId = document.getElementById('chat_student_id').value;
    const r = await fetch('', {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    const res = await r.json();
    if (res.status === 'success') {
        form.querySelector('input[name="message"]').value = '';
        form.querySelector('input[name="attachment"]').value = '';
        form.querySelector('input[name="message"]').placeholder = 'Type a message...';
        const studentName = document.getElementById('chatModalTitle').innerText.replace("Chat: ", "");
        openChatModal(sId, studentName);
    } else {
        alert(res.message || 'Transmission failed');
    }
});

let lastNotifCount = 0;
async function fetchNotifications() {
    let r = await fetch('?action=get_notifications');
    let notifs = await r.json();
    let badge = document.getElementById('notifCount');
    if (notifs.length > 0) {
        badge.textContent = notifs.length;
        badge.style.display = 'inline';
        if (notifs.length > lastNotifCount) {
            if (Notification.permission === 'granted') {
                let from = notifs[0].sender_name || notifs[0].sender_email || 'Unknown';
                new Notification('Notification from ' + from, {
                    body: notifs[0].message,
                    icon: '../WCIS_LOGO-1-removebg-preview.png'
                });
            }
        }
        lastNotifCount = notifs.length;
    } else {
        badge.style.display = 'none';
        lastNotifCount = 0;
    }
}

async function openNotifications() {
    openModal('notifModal');
    let container = document.getElementById('notifList');
    container.innerHTML = 'Loading...';
    let r = await fetch('?action=get_notifications');
    let notifs = await r.json();
    if (notifs.length === 0) {
        container.innerHTML = '<div style="color:var(--muted); text-align:center;">No new notifications.</div>';
    } else {
        let html = '';
        notifs.forEach(n => {
            let from = n.sender_name || n.sender_email || 'Unknown';
            html += `<div style="padding:10px; background:rgba(255,255,255,0.03); border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
                <div style="font-size:11px; color:var(--primary); font-weight:600; margin-bottom:2px;">${from}</div>
                <div style="font-size:13px;">${n.message}</div>
                <div style="font-size:11px; color:var(--muted);">${n.created_at}</div>
            </div>`;
            fetch('?action=mark_notification_read&id=' + n.id);
        });
        container.innerHTML = html;
    }
    document.getElementById('notifCount').style.display = 'none';
    lastNotifCount = 0;
}

if ('Notification' in window) {
    Notification.requestPermission();
}
setInterval(fetchNotifications, 10000);
fetchNotifications();
document.querySelectorAll('input[name="score_key_file[]"]').forEach(function(input) {
    input.addEventListener('change', function() {
        const files = this.files;
        const maxSize = 40 * 1024 * 1024;

        const hasPdf = Array.from(files).some(f => f.name.toLowerCase().endsWith('.pdf'));
        if (hasPdf && files.length > 1) {
            alert('When uploading a PDF, please select only one file. To upload multiple pages, select multiple image files (PNG/JPG) instead.');
            this.value = "";
            return;
        }

        for (let i = 0; i < files.length; i++) {
            if (files[i].size > maxSize) {
                alert(`The file "${files[i].name}" is too large. Max file size is 40 MB.`);
                this.value = "";
                break;
            }
        }
    });
});
</script>
</body>
</html>