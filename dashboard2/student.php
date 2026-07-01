<?php
session_start();
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/csrf.php';
require '../includes/config.php';
require_student();

function log_error($message, $context = []) {
    $logEntry = date('Y-m-d H:i:s') . " | " . $message;
    if (!empty($context)) {
        $logEntry .= " | " . json_encode($context);
    }
    error_log($logEntry . "\n", 3, __DIR__ . '/logs/student_errors.log');
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
        echo json_encode(['status' => 'error', 'message' => 'Internal server error.']);
    } else {
        echo "<h1>Server Error</h1><p>Please try again later.</p>";
    }
    exit;
});

$student_id = $_SESSION['user_id'] ?? 0;
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
            SELECT a.*, u.full_name as teacher_name, u.email as teacher_email, sk.version as key_version, sk.question_structure
            FROM assignments a
            JOIN teacher_student ts ON a.student_id = ts.student_id
            JOIN users u ON ts.teacher_id = u.id
            LEFT JOIN score_keys sk ON a.score_key_id = sk.id
            WHERE a.id = ? AND a.student_id = ?
            LIMIT 1
        ");
        $stmt->execute([$asm_id, $student_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            log_error('get_assignment_details not found', ['assignment_id' => $asm_id, 'student_id' => $student_id]);
            echo json_encode(['error' => 'Assignment not found.']);
        } else {
            echo json_encode($result);
        }
        exit;
    }
    if ($action === 'get_score_key_details' && isset($_GET['assignment_id'])) {
        $asm_id = (int)$_GET['assignment_id'];
        $stmt = $pdo->prepare("
            SELECT sk.file_path, sk.question_structure 
            FROM assignments a
            LEFT JOIN score_keys sk ON a.score_key_id = sk.id
            WHERE a.id = ? AND a.student_id = ?
        ");
        $stmt->execute([$asm_id, $student_id]);
        $data = $stmt->fetch();
        if ($data && $data['question_structure']) {
            $structure = json_decode($data['question_structure'], true);

            // file_path may be a single path (PDF/legacy single image) or a JSON-encoded
            // array of image paths uploaded by the teacher, stored in upload order.
            $filePaths = [];
            $rawPath = $data['file_path'];
            if ($rawPath) {
                $looksLikeJsonArray = strlen($rawPath) > 0 && $rawPath[0] === '[';
                $decoded = json_decode($rawPath, true);
                if (is_array($decoded)) {
                    $filePaths = array_values($decoded);
                } elseif ($looksLikeJsonArray) {
                    // Starts like a JSON array but failed to decode (e.g. truncated by a
                    // too-narrow DB column) — don't treat the broken string as a real path.
                    log_error('get_score_key_details: corrupted file_path JSON', ['assignment_id' => $asm_id, 'raw_length' => strlen($rawPath)]);
                    echo json_encode(['error' => 'Score key files are corrupted on the server. Please ask your teacher to re-upload the score key.']);
                    exit;
                } else {
                    $filePaths = [$rawPath];
                }
            }

            echo json_encode([
                'file_path' => $data['file_path'],
                'file_paths' => $filePaths,
                'question_structure' => $structure
            ]);
        } else {
            echo json_encode(['error' => 'Score key not available for this assignment.']);
        }
        exit;
    }
    if ($action === 'get_chat_stream') {
        $teacher_id = (int)($_GET['teacher_id'] ?? 0);
        $t_stmt = $pdo->prepare("SELECT teacher_id FROM teacher_student WHERE student_id = ? AND teacher_id = ?");
        $t_stmt->execute([$student_id, $teacher_id]);
        if (!$t_stmt->fetch()) {
            echo json_encode([]);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT m.*, u.full_name as sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$student_id, $teacher_id, $teacher_id, $student_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
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
        $stmt->execute([$student_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($action === 'mark_notification_read') {
        $notif_id = (int)$_GET['id'];
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notif_id, $student_id]);
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
            if ($action === 'submit_self_score') {
                $asm_id = (int)($_POST['assignment_id'] ?? 0);
                $scores = $_POST['scores'] ?? [];
                if (!$asm_id || empty($scores)) throw new Exception('No scores provided.');

                $chk = $pdo->prepare("SELECT id FROM assignments WHERE id = ? AND student_id = ?");
                $chk->execute([$asm_id, $student_id]);
                if (!$chk->fetch()) throw new Exception('Invalid assignment.');

                $pdo->prepare("DELETE FROM self_scores WHERE assignment_id = ? AND student_id = ?")->execute([$asm_id, $student_id]);

                $insert = $pdo->prepare("INSERT INTO self_scores (assignment_id, student_id, question_number, is_correct) VALUES (?, ?, ?, ?)");
                foreach ($scores as $qnum => $correct) {
                    $insert->execute([$asm_id, $student_id, $qnum, (int)$correct]);
                }

                $pdo->prepare("UPDATE assignments SET status = 'Self-Scored' WHERE id = ?")->execute([$asm_id]);

                echo json_encode(['status' => 'success']);
                exit;
            }

            if ($action === 'send_chat_msg') {
                $teacher_id = (int)($_POST['teacher_id'] ?? 0);
                $t_stmt = $pdo->prepare("SELECT teacher_id FROM teacher_student WHERE student_id = ? AND teacher_id = ?");
                $t_stmt->execute([$student_id, $teacher_id]);
                if (!$t_stmt->fetch()) throw new Exception('Invalid or unlinked instructor.');
                $msg_body = trim($_POST['message'] ?? '');

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

                $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body, attachment_path, attachment_name, attachment_mime) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$student_id, $teacher_id, $msg_body, $attachment_path, $attachment_name, $attachment_mime]);

                send_notification($teacher_id, $student_id, 'message', $attachment_path ? 'Sent an attachment' : 'Sent a message: ' . mb_strimwidth($msg_body, 0, 80, '...'));
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

$asm_stmt = $pdo->prepare("
    SELECT a.*, sk.question_count 
    FROM assignments a
    LEFT JOIN score_keys sk ON a.score_key_id = sk.id
    WHERE a.student_id = ? 
    ORDER BY a.due_date ASC
");
$asm_stmt->execute([$student_id]);
$my_assignments = $asm_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_assigned = count($my_assignments);
$active_paces = 0; $completed_paces = 0; $requires_correction = 0;
foreach ($my_assignments as $item) {
    if ($item['status'] === 'Assigned' || $item['status'] === 'In Progress') $active_paces++;
    if ($item['status'] === 'Completed') $completed_paces++;
    if ($item['status'] === 'Needs Correction') $requires_correction++;
}

$teacher_stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email 
    FROM users u
    JOIN teacher_student ts ON u.id = ts.teacher_id
    WHERE ts.student_id = ? ORDER BY u.full_name ASC
");
$teacher_stmt->execute([$student_id]);
$my_teachers = $teacher_stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($my_teachers)) {
    $my_teachers = [['id' => 0, 'full_name' => 'Unallocated Staff Node', 'email' => 'system@wcis.edu']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="../WCIS_LOGO-1-removebg-preview.png" type="image/x-icon">
    <title>Student Portal</title>
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

.page-section {
	background: rgba(255, 255, 255, 0.02);
	border: 1px solid rgba(255, 255, 255, 0.06);
	border-radius: 8px;
	padding: 12px;
	margin-bottom: 16px;
}

.page-section img,
.page-section iframe {
	max-width: 100%;
	border-radius: 8px;
	margin-top: 8px;
}

.question-item {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 6px 0;
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

.question-item label {
	display: inline-flex;
	align-items: center;
	gap: 4px;
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
                    <h1 class="card__title">Student Workstation</h1>
                    <p class="card__sub">Self-Scoring and Progress Tracking</p>
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
            <div class="stat-box"><h4>Assigned PACEs</h4><p><?= $total_assigned ?></p></div>
            <div class="stat-box"><h4>Active Modules</h4><p><?= $active_paces ?></p></div>
            <div class="stat-box"><h4>Completed Tasks</h4><p><?= $completed_paces ?></p></div>
        </div>
        <div class="toolbar" style="margin-top: 12px; padding: 14px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid rgba(255,255,255,0.06); align-items: center; flex-direction:column; gap:10px;">
            <h4 style="margin:0; align-self:flex-start; font-size:14px; color: var(--text);">Assigned Teacher<?= count($my_teachers) > 1 ? 's' : '' ?></h4>
            <?php foreach ($my_teachers as $t): ?>
            <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                <p style="margin:0; font-size:13px; color: var(--muted);"><?= htmlspecialchars($t['full_name']) ?> — <code><?= htmlspecialchars($t['email']) ?></code></p>
                <button class="btn btn--primary" onclick="openChatModal(<?= (int)$t['id'] ?>, '<?= htmlspecialchars($t['full_name'], ENT_QUOTES) ?>')">Open Chat</button>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="toolbar" style="margin-top:24px;"><h3 style="margin:0;">My Assigned PACE Modules</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>PACE Target</th><th>Due Deadline Date</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($my_assignments as $asm): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($asm['pace']) ?></strong></td>
                    <td><?= htmlspecialchars($asm['due_date']) ?></td>
                    <td><span class="badge badge--<?= strtolower(str_replace(' ', '', $asm['status'])) ?>"><?= $asm['status'] ?></span></td>
                    <td>
                        <button class="btn btn--ghost" onclick="openAssignmentDetails(<?= $asm['id'] ?>)">Review Framework</button>
                        <?php if($asm['status'] !== 'Completed' && $asm['status'] !== 'Self-Scored'): ?>
                        <button class="btn btn--success" onclick="openSelfScoreModal(<?= $asm['id'] ?>)">Self-Score</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; if(empty($my_assignments)): ?>
                <tr><td colspan="4" style="text-align:center; color:var(--muted);">No ongoing curriculum trajectories mapped.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal" id="assignmentModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">PACE Curriculum Blueprint</h3>
            <button class="btn btn--ghost" onclick="closeModal('assignmentModal')">✕</button>
        </div>
        <div class="modal__body">
            <div id="assignmentDetailsContent" style="display:grid; gap:14px; font-size:14px;"></div>
        </div>
    </div>
</div>

<div class="modal" id="selfScoreModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Self-Score Your Work</h3>
            <button class="btn btn--ghost" onclick="closeModal('selfScoreModal')">✕</button>
        </div>
        <div class="modal__body">
            <div id="selfScoreContent"></div>
        </div>
    </div>
</div>

<div class="modal" id="chatModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Chat — <span id="chatWithName">Teacher</span></h3>
            <button class="btn btn--ghost" onclick="closeModal('chatModal')">✕</button>
        </div>
        <div class="modal__body" style="display:flex; flex-direction:column; gap:12px;">
            <div id="chatMessagesContainer" style="height:240px; overflow-y:auto; background:#0f142a; padding:14px; border-radius:12px; border:1px solid rgba(255,255,255,0.06);"></div>
            <form id="chatSubmissionForm" class="form" enctype="multipart/form-data" method="POST">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="send_chat_msg">
                <input type="hidden" name="teacher_id" id="chatTeacherId" value="">
                <div id="chatComposerNormal" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <input class="input" name="message" placeholder="Type a message..." style="flex:1; min-width:150px;">
                    <label class="btn btn--ghost" style="cursor:pointer; padding:8px 12px;"><img src="paper-clip.png" alt="Attach File" style="width:20px; height:20px; filter:invert(1);">    
                        <input type="file" name="attachment" style="display:none;" onchange="this.form.querySelector('input[name=message]').placeholder='File attached'">
                    </label>
                    <button type="button" class="btn btn--ghost" id="voiceRecordBtn" style="padding:8px 12px;">🎤</button>
                    <button type="submit" class="btn btn--primary">Send</button>
                </div>
                <div id="chatComposerRecording" class="voice-recorder-bar" style="display:none;">
                    <button type="button" class="voice-rec-cancel" id="voiceCancelBtn" title="Cancel">🗑️</button>
                    <div class="voice-rec-indicator">
                        <button type="button" class="voice-rec-pause" id="voicePauseBtn" title="Pause"><span class="voice-rec-dot" id="voiceRecDot"></span></button>
                        <span class="voice-rec-timer" id="voiceTimer">0:00</span>
                    </div>
                    <div class="voice-rec-wave" id="voiceRecWave">
                        <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                    </div>
                    <button type="button" class="voice-rec-replay" id="voiceReplayBtn" title="Play recording" style="display:none;">▶</button>
                    <button type="button" class="voice-rec-send" id="voiceSendBtn" title="Send">➤</button>
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

async function openAssignmentDetails(asmId) {
    let r = await fetch('?action=get_assignment_details&assignment_id=' + asmId);
    let data = await r.json();
    if(!data.error) {
        let container = document.getElementById('assignmentDetailsContent');
        container.innerHTML = `
            <div><strong>PACE:</strong> ${data.pace}</div>
            <div><strong>Assigned Supervisory Teacher:</strong> ${data.teacher_name} (${data.teacher_email})</div>
            <div><strong>Milestone Deadline Cap:</strong> ${data.due_date}</div>
            <div><strong>Status:</strong> <span class="badge">${data.status}</span></div>
        `;
        openModal('assignmentModal');
    } else {
        alert(data.error);
    }
}

async function openSelfScoreModal(asmId) {
    let r = await fetch('?action=get_score_key_details&assignment_id=' + asmId);
    let data = await r.json();
    if (data.error) { alert(data.error); return; }

    let filePaths = data.file_paths && data.file_paths.length ? data.file_paths : (data.file_path ? [data.file_path] : []);
    let structure = data.question_structure || { pages: [] };
    let pages = structure.pages || [];

    let html = '<div style="margin-bottom:16px;">';
    if (filePaths.length > 1) {
        // Multiple images uploaded by the teacher: show them stacked, in the exact order uploaded.
        html += '<div class="score-key-gallery" style="display:flex; flex-direction:column; gap:12px;">';
        filePaths.forEach((p, idx) => {
            html += `<div class="score-key-page">
                <div style="font-size:11px; color:var(--muted); margin-bottom:4px;">Page ${idx + 1} of ${filePaths.length}</div>
                <img src="${p}" alt="Score Key page ${idx + 1}" style="max-width:100%; border-radius:8px;">
            </div>`;
        });
        html += '</div>';
    } else if (filePaths.length === 1) {
        let filePath = filePaths[0];
        let ext = filePath.split('.').pop().toLowerCase();
        if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
            html += `<img src="${filePath}" alt="Score Key" style="max-width:100%; max-height:400px; border-radius:8px; margin-bottom:12px;">`;
        } else if (ext === 'pdf') {
            html += `<iframe src="${filePath}" style="width:100%; height:400px; border-radius:8px; margin-bottom:12px;"></iframe>`;
        } else {
            html += `<a href="${filePath}" target="_blank" class="btn btn--primary">Download Score Key</a>`;
        }
    } else {
        html += '<div style="color:var(--muted);">No score key file attached.</div>';
    }
    html += '</div>';

    html += '<form id="selfScoreForm">';
    html += '<input type="hidden" name="assignment_id" value="' + asmId + '">';
    html += '<input type="hidden" name="csrf" value="<?= csrf_token() ?>">';
    html += '<input type="hidden" name="action" value="submit_self_score">';

    document.getElementById('selfScoreContent').innerHTML = html;

    document.getElementById('selfScoreForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        let fd = new FormData(e.target);
        let resp = await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        let result = await resp.json();
        if (result.status === 'success') {
            alert('Self-score saved!');
            location.reload();
        } else {
            alert(result.message || 'Error saving scores.');
        }
    });

    openModal('selfScoreModal');
}

let mediaRecorder;
let audioChunks = [];
let voiceStream = null;
let voiceTimerInterval = null;
let voiceStartTime = 0;
let voiceElapsedBeforePause = 0;
let voiceCancelled = false;
let voicePaused = false;
let voicePreviewAudio = null;

function voiceFormatTimer(totalSeconds) {
    let m = Math.floor(totalSeconds / 60);
    let s = totalSeconds % 60;
    return m + ':' + String(s).padStart(2, '0');
}

function voiceResetUI() {
    document.getElementById('voicePauseBtn').innerHTML = '<span class="voice-rec-dot" id="voiceRecDot"></span>';
    document.getElementById('voicePauseBtn').title = 'Pause';
    document.getElementById('voiceRecWave').classList.remove('paused');
    document.getElementById('voiceReplayBtn').style.display = 'none';
    document.getElementById('voiceReplayBtn').textContent = '▶';
    voicePaused = false;
    voiceElapsedBeforePause = 0;
    if (voicePreviewAudio) {
        voicePreviewAudio.pause();
        voicePreviewAudio = null;
    }
}

async function startVoiceRecording() {
    try {
        voiceStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (err) {
        alert('Microphone access denied: ' + err.message);
        return;
    }
    voiceCancelled = false;
    audioChunks = [];
    voiceResetUI();
    mediaRecorder = new MediaRecorder(voiceStream);
    mediaRecorder.ondataavailable = event => { if (event.data && event.data.size > 0) audioChunks.push(event.data); };
    mediaRecorder.onstop = () => {
        voiceStream.getTracks().forEach(track => track.stop());
        clearInterval(voiceTimerInterval);
        document.getElementById('chatComposerRecording').style.display = 'none';
        document.getElementById('chatComposerNormal').style.display = 'flex';
        voiceResetUI();
        if (voiceCancelled || audioChunks.length === 0) return;
        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
        sendVoiceMessage(audioBlob);
    };
    mediaRecorder.start();

    document.getElementById('chatComposerNormal').style.display = 'none';
    const recBar = document.getElementById('chatComposerRecording');
    recBar.style.display = 'flex';
    voiceStartTime = Date.now();
    document.getElementById('voiceTimer').textContent = '0:00';
    voiceTimerInterval = setInterval(() => {
        const sec = Math.floor((voiceElapsedBeforePause + (Date.now() - voiceStartTime)) / 1000);
        document.getElementById('voiceTimer').textContent = voiceFormatTimer(sec);
    }, 250);
}

function toggleVoicePause() {
    if (!mediaRecorder) return;
    if (!voicePaused) {
        if (mediaRecorder.state === 'recording') mediaRecorder.pause();
        voicePaused = true;
        voiceElapsedBeforePause += Date.now() - voiceStartTime;
        clearInterval(voiceTimerInterval);
        document.getElementById('voicePauseBtn').innerHTML = '▶';
        document.getElementById('voicePauseBtn').title = 'Resume';
        document.getElementById('voiceRecWave').classList.add('paused');
        document.getElementById('voiceReplayBtn').style.display = 'inline-flex';
    } else {
        if (voicePreviewAudio) { voicePreviewAudio.pause(); voicePreviewAudio = null; }
        document.getElementById('voiceReplayBtn').textContent = '▶';
        if (mediaRecorder.state === 'paused') mediaRecorder.resume();
        voicePaused = false;
        voiceStartTime = Date.now();
        voiceTimerInterval = setInterval(() => {
            const sec = Math.floor((voiceElapsedBeforePause + (Date.now() - voiceStartTime)) / 1000);
            document.getElementById('voiceTimer').textContent = voiceFormatTimer(sec);
        }, 250);
        document.getElementById('voicePauseBtn').innerHTML = '<span class="voice-rec-dot" id="voiceRecDot"></span>';
        document.getElementById('voicePauseBtn').title = 'Pause';
        document.getElementById('voiceRecWave').classList.remove('paused');
        document.getElementById('voiceReplayBtn').style.display = 'none';
    }
}

function replayVoiceRecording() {
    if (!voicePaused || !mediaRecorder) return;
    const replayBtn = document.getElementById('voiceReplayBtn');
    if (voicePreviewAudio && !voicePreviewAudio.paused) {
        voicePreviewAudio.pause();
        voicePreviewAudio = null;
        replayBtn.textContent = '▶';
        return;
    }
    const flushAndPlay = () => {
        if (audioChunks.length === 0) return;
        const blob = new Blob(audioChunks, { type: 'audio/webm' });
        const url = URL.createObjectURL(blob);
        voicePreviewAudio = new Audio(url);
        replayBtn.textContent = '⏸';
        voicePreviewAudio.play();
        voicePreviewAudio.onended = () => { replayBtn.textContent = '▶'; URL.revokeObjectURL(url); voicePreviewAudio = null; };
    };
    if (mediaRecorder.state === 'paused') {
        try { mediaRecorder.requestData(); } catch (e) {}
        setTimeout(flushAndPlay, 60);
    } else {
        flushAndPlay();
    }
}

function stopVoiceRecording(cancelled) {
    voiceCancelled = cancelled;
    if (mediaRecorder && (mediaRecorder.state === 'recording' || mediaRecorder.state === 'paused')) {
        mediaRecorder.stop();
    }
}

async function sendVoiceMessage(blob) {
    const file = new File([blob], 'voice_message.webm', { type: 'audio/webm' });
    const fd = new FormData();
    fd.append('csrf', document.querySelector('#chatSubmissionForm input[name="csrf"]').value);
    fd.append('action', 'send_chat_msg');
    fd.append('teacher_id', document.getElementById('chatTeacherId').value);
    fd.append('message', '');
    fd.append('attachment', file);
    try {
        const r = await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const res = await r.json();
        if (res.status === 'success') {
            openChatModal(document.getElementById('chatTeacherId').value, document.getElementById('chatWithName').textContent);
        } else {
            alert(res.message || 'Failed to send voice message.');
        }
    } catch (err) {
        alert('Failed to send voice message.');
    }
}

document.getElementById('voiceRecordBtn').addEventListener('click', startVoiceRecording);
document.getElementById('voiceCancelBtn').addEventListener('click', () => stopVoiceRecording(true));
document.getElementById('voiceSendBtn').addEventListener('click', () => stopVoiceRecording(false));
document.getElementById('voicePauseBtn').addEventListener('click', toggleVoicePause);
document.getElementById('voiceReplayBtn').addEventListener('click', replayVoiceRecording);

function chatParseDate(ts) {
    // MySQL datetime "YYYY-MM-DD HH:MM:SS" -> treat as local time
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
async function openChatModal(teacherId, teacherName) {
    document.getElementById('chatTeacherId').value = teacherId;
    document.getElementById('chatWithName').textContent = teacherName || 'Teacher';
    openModal('chatModal');
    let container = document.getElementById('chatMessagesContainer');
    container.innerHTML = 'Retrieving conversation logs...';
    let r = await fetch('?action=get_chat_stream&teacher_id=' + encodeURIComponent(teacherId));
    let logs = await r.json();
    let markup = '';
    let lastDay = null;
    logs.forEach(m => {
        let day = chatFormatDaySeparator(m.created_at);
        if (day !== lastDay) {
            markup += `<div class="chat-day-sep"><span>${day}</span></div>`;
            lastDay = day;
        }
        let isMe = m.sender_id == <?= $student_id ?>;
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
                attachmentHtml = `<div><a href="${m.attachment_path}" target="_blank" class="btn btn--ghost">📎 ${m.attachment_name || 'Attachment'}</a></div>`;
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
    container.innerHTML = markup || '<div style="color:var(--muted); text-align:center; font-size:12px; margin-top:20px;">No communication logs found.</div>';
    initVoiceMessagePlayers(container);
    container.scrollTop = container.scrollHeight;
}

document.getElementById('chatSubmissionForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    const fd = new FormData(f);
    const r = await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const res = await r.json();
    if (res.status === 'success') {
        f.querySelector('input[name="message"]').value = '';
        f.querySelector('input[name="attachment"]').value = '';
        f.querySelector('input[name="message"]').placeholder = 'Type a message...';
        openChatModal(document.getElementById('chatTeacherId').value, document.getElementById('chatWithName').textContent);
    } else {
        alert(res.message || 'Transmission exception flagged');
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
</script>
</body>
</html>