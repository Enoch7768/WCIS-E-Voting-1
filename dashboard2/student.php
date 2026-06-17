<?php
session_start();
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/csrf.php';
require '../includes/config.php';
require_student();

$student_id = $_SESSION['user_id'] ?? 0;
$notice = null; $error = null;

// ---------- Helper functions ----------
function send_notification($user_id, $sender_id, $type, $message, $link = null) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, sender_id, type, message, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $sender_id, $type, $message, $link]);
}

function gemini_extract_answers($image_path, $questions_json) {
    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
    if (empty($api_key)) throw new Exception('Gemini API key is not configured.');
    $image_data = base64_encode(file_get_contents($image_path));
    $mime = mime_content_type($image_path);
    $prompt = "Extract student answers from this exam page. The questions are: " . json_encode($questions_json) .
              " Return a JSON array with objects: {'question_number': string, 'extracted_answer': string, 'confidence': float (0-1)}. Only return valid JSON.";
    $payload = ['contents' => [[ 'parts' => [ ['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => $image_data]] ] ]]];
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=" . $api_key);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code !== 200) throw new Exception("Gemini API error: $response");
    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    preg_match('/\[.*\]/s', $text, $matches);
    $json = $matches[0] ?? '[]';
    return json_decode($json, true);
}

function gemini_explain_answer($question_number, $student_answer, $correct_answer, $image_path = null) {
    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
    if (empty($api_key)) throw new Exception('Gemini API key not configured.');
    $parts = [];
    $prompt = "The student answered '$student_answer' for question $question_number. The correct answer is '$correct_answer'. Explain the concept and why the student's answer is incorrect. Provide a helpful, concise explanation. If you have the image, use it for context.";
    $parts[] = ['text' => $prompt];
    if ($image_path && file_exists($image_path)) {
        $image_data = base64_encode(file_get_contents($image_path));
        $mime = mime_content_type($image_path);
        $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $image_data]];
    }
    $payload = ['contents' => [[ 'parts' => $parts ]]];
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=" . $api_key);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code !== 200) throw new Exception("Gemini API error: $response");
    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Explanation not available.';
}

function find_correct_answer($qnum, $question_structure) {
    foreach ($question_structure['questions'] as $q) {
        if ((string)$q['question_number'] === (string)$qnum) {
            return $q['correct_answer'] ?? '';
        }
    }
    return '';
}

// ---------- AJAX GET handlers ----------
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
        ");
        $stmt->execute([$asm_id, $student_id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: ['error' => 'Data record inaccessible']);
        exit;
    }
    if ($action === 'get_ocr_results' && isset($_GET['assignment_id'])) {
        $asm_id = (int)$_GET['assignment_id'];
        $stmt = $pdo->prepare("
            SELECT o.* FROM ocr_results o
            JOIN assignments a ON o.assignment_id = a.id
            WHERE a.id = ? AND a.student_id = ?
            ORDER BY o.page_number ASC, o.question_number ASC
        ");
        $stmt->execute([$asm_id, $student_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($action === 'get_chat_stream') {
        $stmt = $pdo->prepare("
            SELECT m.*, u.full_name as sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = ? OR m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$student_id, $student_id]);
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
    if ($action === 'get_failed_questions' && isset($_GET['assignment_id'])) {
        $asm_id = (int)$_GET['assignment_id'];
        // Get assignment details to retrieve correct answers from score key
        $stmt = $pdo->prepare("SELECT sk.question_structure FROM assignments a LEFT JOIN score_keys sk ON a.score_key_id = sk.id WHERE a.id = ? AND a.student_id = ?");
        $stmt->execute([$asm_id, $student_id]);
        $row = $stmt->fetch();
        if (!$row || empty($row['question_structure'])) {
            echo json_encode(['error' => 'Score key not available']);
            exit;
        }
        $questions = json_decode($row['question_structure'], true);
        // Get incorrect OCR results for this assignment
        $stmt = $pdo->prepare("SELECT * FROM ocr_results WHERE assignment_id = ? AND is_correct = 0 ORDER BY page_number, question_number");
        $stmt->execute([$asm_id]);
        $failed = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Add correct answer and question text if available
        foreach ($failed as &$f) {
            $f['correct_answer'] = find_correct_answer($f['question_number'], $questions);
            foreach ($questions['questions'] as $q) {
                if ((string)$q['question_number'] === (string)$f['question_number']) {
                    $f['question_text'] = $q['question_text'] ?? '';
                    break;
                }
            }
        }
        echo json_encode($failed);
        exit;
    }
    if ($action === 'get_help_explanation' && isset($_GET['ocr_id'])) {
        $ocr_id = (int)$_GET['ocr_id'];
        // Fetch OCR record including image_path
        $stmt = $pdo->prepare("SELECT o.*, a.score_key_id FROM ocr_results o JOIN assignments a ON o.assignment_id = a.id WHERE o.id = ? AND a.student_id = ?");
        $stmt->execute([$ocr_id, $student_id]);
        $ocr = $stmt->fetch();
        if (!$ocr) { echo json_encode(['error' => 'Record not found']); exit; }
        // Get correct answer from score key
        $stmt = $pdo->prepare("SELECT question_structure FROM score_keys WHERE id = ?");
        $stmt->execute([$ocr['score_key_id']]);
        $sk = $stmt->fetch();
        if (!$sk) { echo json_encode(['error' => 'Score key missing']); exit; }
        $questions = json_decode($sk['question_structure'], true);
        $correct = find_correct_answer($ocr['question_number'], $questions);
        // Check if help request already exists
        $stmt = $pdo->prepare("SELECT * FROM help_requests WHERE student_id = ? AND assignment_id = ? AND question_number = ?");
        $stmt->execute([$student_id, $ocr['assignment_id'], $ocr['question_number']]);
        $existing = $stmt->fetch();
        if ($existing && $existing['status'] === 'answered') {
            echo json_encode(['explanation' => $existing['gemini_explanation']]);
            exit;
        }
        // Generate explanation with image if available
        $image_path = $ocr['image_path'] ?? null;
        $explanation = gemini_explain_answer($ocr['question_number'], $ocr['extracted_answer'], $correct, $image_path);
        // Store in help_requests
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE help_requests SET gemini_explanation = ?, status = 'answered' WHERE id = ?");
            $stmt->execute([$explanation, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO help_requests (student_id, assignment_id, question_number, extracted_answer, correct_answer, gemini_explanation, status) VALUES (?, ?, ?, ?, ?, ?, 'answered')");
            $stmt->execute([$student_id, $ocr['assignment_id'], $ocr['question_number'], $ocr['extracted_answer'], $correct, $explanation]);
        }
        // Notify teacher (optional)
        echo json_encode(['explanation' => $explanation]);
        exit;
    }
}

// ---------- POST handlers ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Invalid CSRF security signature token.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'upload_submission' || $action === 'upload_correction') {
                $asm_id = (int)($_POST['assignment_id'] ?? 0);
                $chk = $pdo->prepare("SELECT a.status, a.score_key_id, sk.question_structure, ts.teacher_id FROM assignments a LEFT JOIN score_keys sk ON a.score_key_id = sk.id JOIN teacher_student ts ON a.student_id = ts.student_id WHERE a.id = ? AND a.student_id = ?");
                $chk->execute([$asm_id, $student_id]);
                $asm_data = $chk->fetch();
                if (!$asm_data) throw new Exception('Assignment context mismatch.');
                if (empty($asm_data['question_structure'])) throw new Exception('Score key not published or missing question structure.');
                $questions = json_decode($asm_data['question_structure'], true);
                $teacher_id = $asm_data['teacher_id'];

                if (isset($_FILES['submission_file'])) {
                    $files = $_FILES['submission_file'];
                    // Sort files by name to assign page numbers in correct order
                    $file_count = count($files['name']);
                    $sorted_indices = range(0, $file_count - 1);
                    usort($sorted_indices, function($a, $b) use ($files) {
                        return strcasecmp($files['name'][$a], $files['name'][$b]);
                    });

                    $uploaded_paths = [];
                    $allowed = ['jpg','jpeg','png','webp'];
                    if (!is_dir('uploads/submissions')) mkdir('uploads/submissions', 0755, true);

                    // Process files in sorted order
                    for ($i = 0; $i < $file_count; $i++) {
                        $idx = $sorted_indices[$i];
                        if ($files['error'][$idx] !== 0) continue;
                        $ext = strtolower(pathinfo($files['name'][$idx], PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowed)) continue;
                        $destPath = 'uploads/submissions/' . uniqid('sub_', true) . '.' . $ext;
                        move_uploaded_file($files['tmp_name'][$idx], $destPath);
                        $uploaded_paths[] = $destPath;
                    }

                    if (empty($uploaded_paths)) throw new Exception('No valid images uploaded.');

                    $pdo->prepare("DELETE FROM ocr_results WHERE assignment_id = ?")->execute([$asm_id]);

                    $all_extracted = [];
                    $page = 1;
                    foreach ($uploaded_paths as $path) {
                        $extracted = gemini_extract_answers($path, $questions);
                        foreach ($extracted as &$item) {
                            $item['page_number'] = $page;
                            $item['image_path'] = $path; // store image path for each answer
                        }
                        $all_extracted = array_merge($all_extracted, $extracted);
                        $page++;
                    }

                    $insert = $pdo->prepare("INSERT INTO ocr_results (assignment_id, question_number, extracted_answer, confidence, status, page_number, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    foreach ($all_extracted as $item) {
                        $confidence = isset($item['confidence']) ? (float)$item['confidence'] : 0.85;
                        $status = ($confidence >= 0.80) ? 'Approved' : 'Pending Review';
                        $insert->execute([$asm_id, $item['question_number'], $item['extracted_answer'], $confidence, $status, $item['page_number'] ?? 1, $item['image_path'] ?? null]);
                    }

                    // Compare with score key and grade
                    $update = $pdo->prepare("UPDATE ocr_results SET is_correct = ?, points_earned = ? WHERE id = ?");
                    $ocr_rows = $pdo->prepare("SELECT id, question_number, extracted_answer FROM ocr_results WHERE assignment_id = ?");
                    $ocr_rows->execute([$asm_id]);
                    while ($row = $ocr_rows->fetch(PDO::FETCH_ASSOC)) {
                        $correct = find_correct_answer($row['question_number'], $questions);
                        $is_correct = (strcasecmp(trim($row['extracted_answer']), trim($correct)) === 0);
                        $points = $is_correct ? 1 : 0;
                        $update->execute([$is_correct, $points, $row['id']]);
                    }

                    $new_status = ($action === 'upload_correction') ? 'In Progress' : 'In Progress';
                    $pdo->prepare("UPDATE assignments SET status = ? WHERE id = ?")->execute([$new_status, $asm_id]);

                    send_notification($teacher_id, $student_id, 'submission', "Student submitted assignment #$asm_id (".count($uploaded_paths)." pages)");

                    $notice = "Upload successful. Extracted answers from ".count($uploaded_paths)." pages. Grading completed.";
                } else {
                    throw new Exception('File upload missing.');
                }
            }

            if ($action === 'send_chat_msg') {
                $t_stmt = $pdo->prepare("SELECT teacher_id FROM teacher_student WHERE student_id = ? LIMIT 1");
                $t_stmt->execute([$student_id]);
                $t_data = $t_stmt->fetch();
                if (!$t_data) throw new Exception('No certified instructor linked.');
                $teacher_id = $t_data['teacher_id'];
                $msg_body = trim($_POST['message'] ?? '');
                if ($msg_body === '') throw new Exception('Message cannot be blank.');
                $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)");
                $stmt->execute([$student_id, $teacher_id, $msg_body]);
                send_notification($teacher_id, $student_id, 'message', "New message from student");
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
                }
            }

        } catch (Throwable $ex) {
            $error = $ex->getMessage();
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'message' => $error]); exit;
            }
        }
    }
}

// ---------- Page data ----------
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
    SELECT u.full_name, u.email 
    FROM users u
    JOIN teacher_student ts ON u.id = ts.teacher_id
    WHERE ts.student_id = ? LIMIT 1
");
$teacher_stmt->execute([$student_id]);
$my_teacher = $teacher_stmt->fetch(PDO::FETCH_ASSOC) ?: ['full_name' => 'Unallocated Staff Node', 'email' => 'system@wcis.edu'];
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
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Helvetica, Arial;
            background:
                radial-gradient(1200px 800px at 80% -10%, rgba(110, 139, 255, .18), transparent),
                radial-gradient(900px 600px at -10% 110%, rgba(76, 212, 168, .08), transparent),
                var(--bg);
            color: var(--text);
        }
        .container-center { min-height: 100vh; display: grid; place-items: center; padding: 40px 16px; }
        .card {
            width: min(1100px, 96vw);
            background: linear-gradient(180deg, rgba(255, 255, 255, .04), rgba(255, 255, 255, .02));
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
        }
        .card__header { padding: 20px 28px 0; }
        .card__body { padding: 28px; }
        .card__title { margin: 0; font-size: 22px; letter-spacing: .3px; }
        .card__sub { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
        .header { display: flex; align-items: center; gap: 12px; }
        .logo {
            width: 70px; height: 70px; border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), #9eaaff);
            box-shadow: 0 6px 24px rgba(110, 139, 255, .45);
            display: grid; place-items: center; overflow: hidden;
        }
        .logo img { width: 100%; height: 90%; object-fit: contain; }
        .form { display: grid; gap: 14px; margin-top: 18px; }
        .label { font-size: 13px; color: var(--muted); margin-bottom: 6px; display: block; }
        .input, .select {
            width: 100%; padding: 12px 14px; border-radius: 12px;
            color: var(--text); background: #0f142a;
            border: 1px solid rgba(255, 255, 255, .08); outline: none;
        }
        .input:focus, .select:focus {
            box-shadow: 0 0 0 3px rgba(110, 139, 255, .25);
            border-color: rgba(110, 139, 255, .6);
        }
        .btn {
            cursor: pointer; user-select: none; border: none; border-radius: 12px;
            padding: 12px 16px; font-weight: 700; display: inline-block;
            text-decoration: none; text-align: center;
        }
        .btn--primary { background: linear-gradient(180deg, var(--primary), var(--primary-700)); color: #fff; box-shadow: 0 10px 30px rgba(110, 139, 255, .35); }
        .btn--ghost { background: transparent; color: var(--muted); border: 1px solid rgba(255, 255, 255, .12); }
        .btn--danger { background: linear-gradient(180deg, #ff7686, #ff475f); color: #fff; }
        .btn--success { background: linear-gradient(180deg, #67e3b5, #3ccf9e); color: #102016; }
        .alert { padding: 12px 14px; border-radius: 12px; font-size: 14px; }
        .alert--error { background: rgba(255, 91, 110, .12); border: 1px solid rgba(255, 91, 110, .3); color: #ffb3bd; }
        .alert--success { background: rgba(76, 212, 168, .12); border: 1px solid rgba(76, 212, 168, .3); color: #b8f3e1; }
        .table-wrap { overflow: auto; border-radius: 14px; border: 1px solid rgba(255, 255, 255, .08); margin-bottom: 24px; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 14px 12px; border-bottom: 1px solid rgba(255, 255, 255, .06); text-align: left; font-size: 14px; }
        .table th { color: var(--muted); font-weight: 600; background: rgba(255, 255, 255, .02); }
        .table tr:hover td { background: rgba(255, 255, 255, .03); }
        .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 16px 0 10px; }
        .badge { padding: 6px 10px; border-radius: 100px; font-size: 12px; border: 1px solid rgba(255, 255, 255, .12); color: var(--muted); display: inline-block; }
        .badge--assigned { color: #ffca28; border-color: #ffca28; }
        .badge--inprogress { color: #29b6f6; border-color: #29b6f6; }
        .badge--correction { color: #ef5350; border-color: #ef5350; }
        .badge--completed { color: #66bb6a; border-color: #66bb6a; }
        .modal {
            position: fixed; inset: 0; display: none; place-items: center;
            background: rgba(5, 8, 18, .55); z-index: 9999;
        }
        .modal.open { display: grid; }
        .modal__content {
            width: min(560px, 94vw); background: var(--card);
            border: 1px solid rgba(255, 255, 255, .12); border-radius: 18px;
            box-shadow: var(--shadow-md);
        }
        .modal__head { display: flex; justify-content: space-between; align-items: center; padding: 18px 20px; border-bottom: 1px solid rgba(255, 255, 255, .06); }
        .modal__body { padding: 18px 20px; max-height: 75vh; overflow-y: auto; }
        .modal__title { margin: 0; font-size: 18px; }
        .logo img { transition: transform 0.3s ease, filter 0.3s ease; }
        .logo:hover img { transform: scale(1.08); filter: drop-shadow(0 0 12px rgba(110, 139, 255, 0.6)); }
        @keyframes pulse {
            0% { transform: scale(1); filter: drop-shadow(0 0 8px rgba(110, 139, 255, 0.4)); }
            50% { transform: scale(1.03); filter: drop-shadow(0 0 16px rgba(110, 139, 255, 0.6)); }
            100% { transform: scale(1); filter: drop-shadow(0 0 8px rgba(110, 139, 255, 0.4)); }
        }
        .logo.pulse img { animation: pulse 2.5s infinite ease-in-out; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-box { background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; padding: 16px; text-align: center; }
        .stat-box h4 { margin: 0; font-size: 12px; color: var(--muted); text-transform: uppercase; }
        .stat-box p { margin: 8px 0 0; font-size: 24px; font-weight: 700; color: var(--primary); }
        .notif-bell { position: relative; cursor: pointer; }
        .notif-badge { position: absolute; top: -8px; right: -8px; background: var(--danger); border-radius: 50%; padding: 2px 6px; font-size: 12px; display: none; }
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
                    <p class="card__sub">PACE Evaluation Engine, Automated Submissions & Progress Trackers</p>
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
            <div class="stat-box"><h4>Corrections Required</h4><p><?= $requires_correction ?></p></div>
        </div>
        <div class="toolbar" style="margin-top: 12px; padding: 14px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid rgba(255,255,255,0.06); align-items: center;">
            <div>
                <h4 style="margin:0; font-size:14px; color: var(--text);">Assigned Primary Academic Instructor</h4>
                <p style="margin:4px 0 0; font-size:13px; color: var(--muted);"><?= htmlspecialchars($my_teacher['full_name']) ?> — <code><?= htmlspecialchars($my_teacher['email']) ?></code></p>
            </div>
            <button class="btn btn--primary" onclick="openChatModal()">Open Direct Line Messages</button>
        </div>
        <div class="toolbar" style="margin-top:24px;"><h3 style="margin:0;">My Assigned PACE Modules</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>PACE Target</th><th>Due Deadline Date</th><th>Completion Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($my_assignments as $asm): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($asm['pace']) ?></strong></td>
                    <td><?= htmlspecialchars($asm['due_date']) ?></td>
                    <td><span class="badge badge--<?= strtolower(str_replace(' ', '', $asm['status'])) ?>"><?= $asm['status'] ?></span></td>
                    <td>
                        <button class="btn btn--ghost" onclick="openAssignmentDetails(<?= $asm['id'] ?>)">Review Framework</button>
                        <?php if($asm['status'] === 'Needs Correction'): ?>
                        <button class="btn btn--danger" onclick="openUploadModal(<?= $asm['id'] ?>, 'upload_correction')">Upload Corrections</button>
                        <?php else: ?>
                        <button class="btn btn--primary" onclick="openUploadModal(<?= $asm['id'] ?>, 'upload_submission')">Upload Work Pages</button>
                        <?php endif; ?>
                        <button class="btn btn--ghost" onclick="openOcrVerificationView(<?= $asm['id'] ?>)">View OCR Output</button>
                        <button class="btn btn--ghost" onclick="openFailedQuestions(<?= $asm['id'] ?>)">Review Incorrect</button>
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

<!-- Modals -->
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

<div class="modal" id="uploadModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title" id="uploadModalTitle">Ingest Material Pages</h3>
            <button class="btn btn--ghost" onclick="closeModal('uploadModal')">✕</button>
        </div>
        <div class="modal__body">
            <form method="POST" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" id="upload_form_action" value="upload_submission">
                <input type="hidden" name="assignment_id" id="upload_assignment_id">
                <label class="label">Select Scanned Answer Sheet Pages (Supported: JPG, JPEG, PNG, WEBP)</label>
                <input type="file" class="input" name="submission_file[]" accept="image/*" required multiple>
                <button type="submit" class="btn btn--success" style="margin-top:12px;">Deliver Work Payload</button>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="ocrModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Machine OCR Reading Output Manifest</h3>
            <button class="btn btn--ghost" onclick="closeModal('ocrModal')">✕</button>
        </div>
        <div class="modal__body">
            <p class="card__sub" style="margin-bottom:14px;">Review raw detected output fields extracted by the parsing system below.</p>
            <div id="ocrVerificationContent" style="display:grid; gap:12px;"></div>
        </div>
    </div>
</div>

<div class="modal" id="chatModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Direct Academic Line Workspace</h3>
            <button class="btn btn--ghost" onclick="closeModal('chatModal')">✕</button>
        </div>
        <div class="modal__body" style="display:flex; flex-direction:column; gap:12px;">
            <div id="chatMessagesContainer" style="height:240px; overflow-y:auto; background:#0f142a; padding:14px; border-radius:12px; border:1px solid rgba(255,255,255,0.06);"></div>
            <form id="chatSubmissionForm" class="form">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="send_chat_msg">
                <div style="display:flex; gap:8px;">
                    <input class="input" name="message" placeholder="Type notification or flag issue..." required>
                    <button type="submit" class="btn btn--primary">Send</button>
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

<!-- Modal for failed questions -->
<div class="modal" id="failedModal">
    <div class="modal__content">
        <div class="modal__head">
            <h3 class="modal__title">Incorrect Answers – Get AI Help</h3>
            <button class="btn btn--ghost" onclick="closeModal('failedModal')">✕</button>
        </div>
        <div class="modal__body">
            <div id="failedContent" style="display:grid; gap:16px;"></div>
        </div>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openUploadModal(asmId, type) {
    document.getElementById('upload_assignment_id').value = asmId;
    document.getElementById('upload_form_action').value = type;
    document.getElementById('uploadModalTitle').innerText = (type === 'upload_correction') ? 'Deliver Corrected Sheet Pages' : 'Deliver Completed Sheet Pages';
    openModal('uploadModal');
}

async function openAssignmentDetails(asmId) {
    let r = await fetch('?action=get_assignment_details&assignment_id=' + asmId);
    let data = await r.json();
    if(!data.error) {
        let container = document.getElementById('assignmentDetailsContent');
        container.innerHTML = `
            <div><strong>PACE Module Identifier:</strong> ${data.pace}</div>
            <div><strong>Assigned Supervisory Teacher:</strong> ${data.teacher_name} (${data.teacher_email})</div>
            <div><strong>Milestone Deadline Cap:</strong> ${data.due_date}</div>
            <div><strong>Completion Status Phase:</strong> <span class="badge">${data.status}</span></div>
        `;
        openModal('assignmentModal');
    }
}

async function openOcrVerificationView(asmId) {
    let r = await fetch('?action=get_ocr_results&assignment_id=' + asmId);
    let logs = await r.json();
    let container = document.getElementById('ocrVerificationContent');
    let markup = '';
    logs.forEach(item => {
        let correct = (item.is_correct === 1) ? '✅' : (item.is_correct === 0 ? '❌' : '⏳');
        markup += `<div style="padding:10px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04); display:flex; justify-content:space-between; align-items:center;">
            <div>
                <span style="color:var(--muted); font-size:12px;">Page ${item.page_number} – Q${item.question_number}</span>
                <div style="font-size:14px; font-weight:600; margin-top:2px;">Answer: "${item.extracted_answer}" ${correct}</div>
            </div>
            <div style="text-align:right;">
                <span class="badge">${item.status}</span>
                <div style="font-size:11px; color:var(--primary); margin-top:4px;">Confidence: ${(item.confidence * 100).toFixed(1)}%</div>
                <div style="font-size:11px; color:var(--success);">Points: ${item.points_earned ?? '-'}</div>
            </div>
        </div>`;
    });
    container.innerHTML = markup || '<div style="color:var(--muted); text-align:center; font-size:13px; padding:12px;">No engine scans logged for this block configuration yet.</div>';
    openModal('ocrModal');
}

async function openFailedQuestions(asmId) {
    let r = await fetch('?action=get_failed_questions&assignment_id=' + asmId);
    let data = await r.json();
    if(data.error) {
        alert(data.error);
        return;
    }
    let container = document.getElementById('failedContent');
    if(data.length === 0) {
        container.innerHTML = '<div style="color:var(--muted); text-align:center;">🎉 No incorrect answers found! Great job!</div>';
    } else {
        let markup = '';
        data.forEach(item => {
            markup += `
                <div style="padding:12px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.06);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <strong>Question ${item.question_number}</strong>
                    </div>
                    <div style="font-size:13px; color:var(--muted);">Your answer: <span style="color:var(--danger);">${item.extracted_answer}</span></div>
                    <div style="font-size:13px; color:var(--muted);">Correct answer: <span style="color:var(--success);">${item.correct_answer}</span></div>
                    <div id="help_explanation_${item.id}" style="margin-top:8px; font-size:13px; color:var(--text); display:none;"></div>
                </div>
            `;
        });
        container.innerHTML = markup;
    }
    openModal('failedModal');
}

async function requestHelp(ocrId) {
    let btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Loading...';
    let r = await fetch('?action=get_help_explanation&ocr_id=' + ocrId);
    let data = await r.json();
    btn.remove();
    let div = document.getElementById('help_explanation_' + ocrId);
    if(data.explanation) {
        div.style.display = 'block';
        div.innerHTML = '<strong>AI Explanation:</strong> ' + data.explanation;
    } else {
        div.style.display = 'block';
        div.innerHTML = '<span style="color:var(--danger);">Sorry, could not retrieve explanation.</span>';
    }
}

async function openChatModal() {
    openModal('chatModal');
    let container = document.getElementById('chatMessagesContainer');
    container.innerHTML = 'Retrieving conversation logs...';
    let r = await fetch('?action=get_chat_stream');
    let logs = await r.json();
    let markup = '';
    logs.forEach(m => {
        let isMe = m.sender_id == <?= $student_id ?>;
        let alignment = isMe ? 'text-align:right;' : 'text-align:left;';
        let background = isMe ? 'rgba(110,139,255,0.1)' : 'rgba(255,255,255,0.04)';
        markup += `<div style="${alignment} margin-bottom:10px;">
            <div style="font-size:11px; color:var(--muted); margin-bottom:2px;">${m.sender_name}</div>
            <div style="display:inline-block; padding:8px 12px; background:${background}; border-radius:8px; max-width:80%; font-size:13px; text-align:left;">
                ${m.body}
            </div>
        </div>`;
    });
    container.innerHTML = markup || '<div style="color:var(--muted); text-align:center; font-size:12px; margin-top:20px;">No communication logs found.</div>';
    container.scrollTop = container.scrollHeight;
}

document.getElementById('chatSubmissionForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    let f = e.target;
    let d = new FormData(f);
    let r = await fetch('', {method: 'POST', body: d, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    let res = await r.json();
    if(res.status === 'success') {
        f.querySelector('input[name="message"]').value = '';
        openChatModal();
    } else {
        alert(res.message || 'Transmission exception flagged');
    }
});

// Notification functions with browser push
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