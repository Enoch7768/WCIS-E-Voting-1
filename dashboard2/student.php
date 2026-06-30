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
            echo json_encode([
                'file_path' => $data['file_path'],
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
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
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

                send_notification($teacher_id, $student_id, 'message', "New message from student with " . ($attachment_path ? 'attachment' : 'text'));
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

.question-item label {
	display: inline-flex;
	align-items: center;
	gap: 4px;
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
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <input class="input" name="message" placeholder="Type a message..." style="flex:1; min-width:150px;">
                    <label class="btn btn--ghost" style="cursor:pointer; padding:8px 12px;"><img src="paper-clip.png" alt="Attach File" style="width:20px; height:20px; filter:invert(1);">    
                        <input type="file" name="attachment" style="display:none;" onchange="this.form.querySelector('input[name=message]').placeholder='File attached'">
                    </label>
                    <button type="button" class="btn btn--ghost" id="voiceRecordBtn" style="padding:8px 12px;">🎤</button>
                    <button type="submit" class="btn btn--primary">Send</button>
                </div>
                <div id="recordingStatus" style="font-size:12px; color:var(--muted); display:none;">🔴 Recording...</div>
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

    let filePath = data.file_path;
    let structure = data.question_structure || { pages: [] };
    let pages = structure.pages || [];

    let html = '<div style="margin-bottom:16px;">';
    if (filePath) {
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

document.getElementById('voiceRecordBtn').addEventListener('click', async function() {
    const status = document.getElementById('recordingStatus');
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        this.textContent = '🎤';
        status.style.display = 'none';
        return;
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];
        mediaRecorder.ondataavailable = event => audioChunks.push(event.data);
        mediaRecorder.onstop = () => {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const file = new File([audioBlob], 'voice_message.webm', { type: 'audio/webm' });
            const form = document.getElementById('chatSubmissionForm');
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            const fileInput = form.querySelector('input[name="attachment"]');
            fileInput.files = dataTransfer.files;
            form.querySelector('input[name="message"]').placeholder = 'Voice message attached';
            stream.getTracks().forEach(track => track.stop());
        };
        mediaRecorder.start();
        this.textContent = '⏹️';
        status.style.display = 'block';
        status.textContent = '🔴 Recording...';
    } catch (err) {
        alert('Microphone access denied: ' + err.message);
    }
});

async function openChatModal(teacherId, teacherName) {
    document.getElementById('chatTeacherId').value = teacherId;
    document.getElementById('chatWithName').textContent = teacherName || 'Teacher';
    openModal('chatModal');
    let container = document.getElementById('chatMessagesContainer');
    container.innerHTML = 'Retrieving conversation logs...';
    let r = await fetch('?action=get_chat_stream&teacher_id=' + encodeURIComponent(teacherId));
    let logs = await r.json();
    let markup = '';
    logs.forEach(m => {
        let isMe = m.sender_id == <?= $student_id ?>;
        let alignment = isMe ? 'text-align:right;' : 'text-align:left;';
        let background = isMe ? 'rgba(110,139,255,0.1)' : 'rgba(255,255,255,0.04)';
        let attachmentHtml = '';
        if (m.attachment_path) {
            const ext = m.attachment_path.split('.').pop().toLowerCase();
            if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
                attachmentHtml = `<div><img src="${m.attachment_path}" style="max-width:200px; max-height:150px; border-radius:8px;"></div>`;
            } else if (['mp3','wav','ogg','webm'].includes(ext) || (m.attachment_mime && m.attachment_mime.startsWith('audio/'))) {
                attachmentHtml = `<div><audio controls src="${m.attachment_path}" style="max-width:200px;"></audio></div>`;
            } else {
                attachmentHtml = `<div><a href="${m.attachment_path}" target="_blank" class="btn btn--ghost">📎 ${m.attachment_name || 'Attachment'}</a></div>`;
            }
        }
        markup += `<div style="${alignment} margin-bottom:10px;">
            <div style="font-size:11px; color:var(--muted); margin-bottom:2px;">${m.sender_name}</div>
            <div style="display:inline-block; padding:8px 12px; background:${background}; border-radius:8px; max-width:80%; font-size:13px; text-align:left;">
                ${m.body || ''}
                ${attachmentHtml}
            </div>
        </div>`;
    });
    container.innerHTML = markup || '<div style="color:var(--muted); text-align:center; font-size:12px; margin-top:20px;">No communication logs found.</div>';
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
                new Notification('New Notification', {
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
            html += `<div style="padding:10px; background:rgba(255,255,255,0.03); border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
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