<?php
   session_start();
   require '../includes/db.php';
   require '../includes/auth.php';
   require '../includes/csrf.php';
   require '../includes/config.php'; 
   require_teacher();
   
   $teacher_id = $_SESSION['user_id'] ?? 0;
   $notice = null; $error = null;
   
   function valid_role($r){ return in_array($r, ['admin','teacher','student'], true); }
   
   function gemini_extract_score_key($file_path) {
       $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : (getenv('GEMINI_API_KEY') ?: '');
       if (empty($api_key)) {
           error_log('Gemini API key not defined (GEMINI_API_KEY)');
           return null;
       }
       $image_data = base64_encode(file_get_contents($file_path));
       $mime = mime_content_type($file_path);
       $prompt = "Extract the answer key from this exam document. Return a JSON object with the following structure:
   {
     \"pace\": { \"subject\": \"string\", \"pace_number\": \"string\", \"title\": \"string\" },
     \"version\": 1,
     \"questions\": [
       { \"question_number\": \"1\", \"question_type\": \"multiple_choice|fill_blank\", \"correct_answer\": \"A\", \"acceptable_answers\": [\"A\"], \"points\": 1 }
     ]
   }
   Only return valid JSON.";
       $payload = [
           'contents' => [[
               'parts' => [
                   ['text' => $prompt],
                   ['inline_data' => ['mime_type' => $mime, 'data' => $image_data]]
               ]
           ]]
       ];
       $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $api_key);
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
       preg_match('/\{.*\}/s', $text, $matches);
       $json = $matches[0] ?? '{}';
       return json_decode($json, true);
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
           echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: ['error' => 'Not found']);
           exit;
       }
   
       if ($action === 'get_ocr_details' && isset($_GET['ocr_id'])) {
           $ocr_id = (int)$_GET['ocr_id'];
           $stmt = $pdo->prepare("SELECT * FROM ocr_results WHERE id = ?");
           $stmt->execute([$ocr_id]);
           echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: ['error' => 'Not found']);
           exit;
       }
       
       if ($action === 'get_chat' && isset($_GET['student_id'])) {
           $student_id = (int)$_GET['student_id'];
           $stmt = $pdo->prepare("
               SELECT * FROM messages 
               WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) 
               ORDER BY created_at ASC
           ");
           $stmt->execute([$teacher_id, $student_id, $student_id, $teacher_id]);
           echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
           exit;
       }
   }

   
   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
       if (!csrf_check($_POST['csrf'] ?? '')) { 
           $error = 'Invalid CSRF token.'; 
       } else {
           $action = $_POST['action'] ?? '';
           try {
               if ($action === 'assign_pace') {
                   $student_id = (int)($_POST['student_id'] ?? 0);
                   $pace = trim($_POST['pace'] ?? '');
                   $score_key_id = (int)($_POST['score_key_id'] ?? 0);
                   $due_date = $_POST['due_date'] ?? '';
                   if ($student_id <= 0 || $pace === '' || $score_key_id <= 0 || $due_date === '') {
                       throw new Exception('All assignment fields are required.');
                   }
                   $chk = $pdo->prepare("SELECT 1 FROM teacher_student WHERE teacher_id = ? AND student_id = ?");
                   $chk->execute([$teacher_id, $student_id]);
                   if (!$chk->fetch()) throw new Exception('Access Denied: Unassigned student reference.');
                   $stmt = $pdo->prepare("INSERT INTO assignments (student_id, pace, score_key_id, due_date, status) VALUES (?, ?, ?, ?, 'Assigned')");
                   $stmt->execute([$student_id, $pace, $score_key_id, $due_date]);
                   $notice = "PACE assignment registered successfully.";
               }

               if ($action === 'upload_score_key') {
                   $pace_title = trim($_POST['pace_title'] ?? '');
                   if ($pace_title === '') throw new Exception('Please specify a destination PACE target.');
                   if (isset($_FILES['score_key_file']) && $_FILES['score_key_file']['error'] === 0) {
                       $fileName = $_FILES['score_key_file']['name'];
                       $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                       $allowed = ['pdf', 'png', 'jpg', 'jpeg'];
                       if (!in_array($ext, $allowed, true)) throw new Exception('Invalid score key document format.');
                       if (!is_dir('uploads/score_keys')) mkdir('uploads/score_keys', 0755, true);
                       $destPath = 'uploads/score_keys/' . uniqid('sk_', true) . '.' . $ext;
                       move_uploaded_file($_FILES['score_key_file']['tmp_name'], $destPath);

                       $extracted = gemini_extract_score_key($destPath);
                       if (empty($extracted['questions'])) throw new Exception('Could not extract questions from document.');
                       $question_structure = json_encode($extracted);
                       $question_count = count($extracted['questions']);
                       
                       $stmt = $pdo->prepare("INSERT INTO score_keys (pace, file_path, version, is_published, question_count, question_structure) VALUES (?, ?, 'Draft-1.0', 0, ?, ?)");
                       $stmt->execute([$pace_title, $destPath, $question_count, $question_structure]);
                       $notice = "Document successfully parsed by Gemini AI. Draft version initialized.";
                   } else {
                       throw new Exception('File upload token missing or corrupt.');
                   }
               }

               if ($action === 'publish_score_key') {
                   $sk_id = (int)($_POST['score_key_id'] ?? 0);
                   $stmt = $pdo->prepare("UPDATE score_keys SET is_published = 1, version = 'Prod-1.0' WHERE id = ?");
                   $stmt->execute([$sk_id]);
                   $notice = "Score key status transitioned to published.";
               }

               if ($action === 'process_ocr') {
                   $ocr_id = (int)($_POST['ocr_id'] ?? 0);
                   $review_status = $_POST['review_action'] ?? '';
                   $edited_val = trim($_POST['extracted_answer'] ?? '');
                   if ($review_status === 'Approve') {
                       $stmt = $pdo->prepare("UPDATE ocr_results SET status = 'Approved', confidence = 1.00 WHERE id = ?");
                       $stmt->execute([$ocr_id]);
                   } elseif ($review_status === 'Edit') {
                       $stmt = $pdo->prepare("UPDATE ocr_results SET extracted_answer = ?, status = 'Approved', confidence = 1.00 WHERE id = ?");
                       $stmt->execute([$edited_val, $ocr_id]);
                   } else {
                       $stmt = $pdo->prepare("UPDATE ocr_results SET status = 'Rejected' WHERE id = ?");
                       $stmt->execute([$ocr_id]);
                   }
                   if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                       header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
                   }
               }
   
               if ($action === 'update_assignment_status') {
                   $asm_id = (int)($_POST['assignment_id'] ?? 0);
                   $target_status = $_POST['status'] ?? '';
                   $stmt = $pdo->prepare("UPDATE assignments SET status = ? WHERE id = ?");
                   $stmt->execute([$target_status, $asm_id]);
                   if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                       header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
                   }
               }
   
               if ($action === 'send_msg') {
                   $student_id = (int)($_POST['student_id'] ?? 0);
                   $msg_body = trim($_POST['message'] ?? '');
                   if($student_id <= 0 || $msg_body === '') throw new Exception('Message content empty.');
                   $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)");
                   $stmt->execute([$teacher_id, $student_id, $msg_body]);
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
   
   $total_assigned_students = count($assigned_students);
   $active_assignments_count = 0;
   $correction_queue_count = 0;
   
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
   
   foreach($all_assignments as $asm) {
       if($asm['status'] === 'In Progress' || $asm['status'] === 'Assigned') $active_assignments_count++;
       if($asm['status'] === 'Needs Correction') $correction_queue_count++;
   }
   
   $score_keys = $pdo->query("SELECT * FROM score_keys ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
   
   $ocr_queue = $pdo->query("
       SELECT o.*, u.full_name as student_name, a.pace 
       FROM ocr_results o
       JOIN assignments a ON o.assignment_id = a.id
       JOIN users u ON a.student_id = u.id
       WHERE o.status = 'Pending Review' OR o.confidence < 0.80
       ORDER BY o.id DESC
   ")->fetchAll(PDO::FETCH_ASSOC);
   $pending_ocr_count = count($ocr_queue);
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
	color: var(--text)
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
	width: 38px;
	height: 38px;
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
      </style>
   </head>
   <body>
      <main class="container-center">
      <section class="card">
         <header class="card__header">
            <div class="header" style="justify-content: space-between; width:100%">
               <div style="display:flex; align-items:center; gap:12px;">
                  <div class="logo pulse"><img src="../WCIS_LOGO-1-removebg-preview.png"></div>
                  <div>
                     <h1 class="card__title">Teacher Dashboard</h1>
                     <p class="card__sub">Academic Workflow, OCR Extraction & Student Assignments</p>
                  </div>
               </div>
               <a class="btn btn--ghost" href="../auth/logout.php">Logout</a>
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
               <div class="stat-box">
                  <h4>Assigned Students</h4>
                  <p><?= $total_assigned_students ?></p>
               </div>
               <div class="stat-box">
                  <h4>Active Tasks</h4>
                  <p><?= $active_assignments_count ?></p>
               </div>
               <div class="stat-box">
                  <h4>OCR Review Queue</h4>
                  <p><?= $pending_ocr_count ?></p>
               </div>
               <div class="stat-box">
                  <h4>Corrections Required</h4>
                  <p><?= $correction_queue_count ?></p>
               </div>
            </div>
            <div class="toolbar">
               <h3 style="margin:0;">Assigned Students Roster</h3>
            </div>
            <div class="table-wrap">
               <table class="table">
                  <thead>
                     <tr>
                        <th>ID</th>
                        <th>Student Name</th>
                        <th>Email</th>
                        <th>Total Tasks</th>
                        <th>Actions</th>
                     </tr>
                  </thead>
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
                     <tr>
                        <td colspan="5" style="text-align:center; color:var(--muted);">No mapped student connections detected.</td>
                     </tr>
                     <?php endif; ?>
                  </tbody>
               </table>
            </div>
            <div class="toolbar">
               <h3 style="margin:0;">Active Academic Assignments Pipeline</h3>
            </div>
            <div class="table-wrap">
               <table class="table">
                  <thead>
                     <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>PACE Code</th>
                        <th>Due Date</th>
                        <th>Workflow Status</th>
                        <th>Actions</th>
                     </tr>
                  </thead>
                  <tbody>
                     <?php foreach ($all_assignments as $asm): ?>
                     <tr>
                        <td><?= $asm['id'] ?></td>
                        <td><?= htmlspecialchars($asm['student_name']) ?></td>
                        <td><?= htmlspecialchars($asm['pace']) ?></td>
                        <td><?= htmlspecialchars($asm['due_date']) ?></td>
                        <td>
                           <span class="badge badge--<?= strtolower(str_replace(' ', '', $asm['status'])) ?>"><?= $asm['status'] ?></span>
                        </td>
                        <td>
                           <button class="btn btn--ghost" onclick="openAssignmentViewer(<?= $asm['id'] ?>)">Review Parameters</button>
                        </td>
                     </tr>
                     <?php endforeach; if(empty($all_assignments)): ?>
                     <tr>
                        <td colspan="6" style="text-align:center; color:var(--muted);">No active curriculum assignments mapped.</td>
                     </tr>
                     <?php endif; ?>
                  </tbody>
               </table>
            </div>
            <div class="toolbar">
               <h3 style="margin:0;">Score Key Configuration & OCR Engine Manifest</h3>
               <button class="btn btn--primary" onclick="openUploadKeyModal()">+ Upload Score Key Document</button>
            </div>
            <div class="table-wrap">
               <table class="table">
                  <thead>
                     <tr>
                        <th>ID</th>
                        <th>PACE Target</th>
                        <th>Version Token</th>
                        <th>Extracted Key Questions</th>
                        <th>State</th>
                        <th>Action</th>
                     </tr>
                  </thead>
                  <tbody>
                     <?php foreach ($score_keys as $sk): ?>
                     <tr>
                        <td><?= $sk['id'] ?></td>
                        <td><?= htmlspecialchars($sk['pace']) ?></td>
                        <td><code><?= htmlspecialchars($sk['version']) ?></code></td>
                        <td><?= $sk['question_count'] ?> steps verified</td>
                        <td><?= $sk['is_published'] ? '✅ Immutable' : '🛠️ Draft Review Pending' ?></td>
                        <td>
                           <?php if(!$sk['is_published']): ?>
                           <form method="POST" style="margin:0; display:inline;">
                              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="publish_score_key">
                              <input type="hidden" name="score_key_id" value="<?= $sk['id'] ?>">
                              <button type="submit" class="btn btn--success">Approve & Publish</button>
                           </form>
                           <?php else: ?>
                           <button class="btn btn--ghost" disabled>Locked</button>
                           <?php endif; ?>
                        </td>
                     </tr>
                     <?php endforeach; if(empty($score_keys)): ?>
                     <tr>
                        <td colspan="6" style="text-align:center; color:var(--muted);">No structural templates compiled yet.</td>
                     </tr>
                     <?php endif; ?>
                  </tbody>
               </table>
            </div>
            <div class="toolbar">
               <h3 style="margin:0;">OCR Review Verification Queue (Low Confidence Flags)</h3>
            </div>
            <div class="table-wrap">
               <table class="table">
                  <thead>
                     <tr>
                        <th>ID</th>
                        <th>Student Base</th>
                        <th>Target Assignment Code</th>
                        <th>Machine Value Result</th>
                        <th>Confidence Threshold</th>
                        <th>Action</th>
                     </tr>
                  </thead>
                  <tbody>
                     <?php foreach ($ocr_queue as $oq): ?>
                     <tr>
                        <td><?= $oq['id'] ?></td>
                        <td><?= htmlspecialchars($oq['student_name']) ?></td>
                        <td><?= htmlspecialchars($oq['pace']) ?></td>
                        <td><code><?= htmlspecialchars($oq['extracted_answer']) ?></code></td>
                        <td>
                           <strong style="color: <?= $oq['confidence'] < 0.80 ? 'var(--danger)' : 'var(--success)' ?>">
                           <?= number_format($oq['confidence'] * 100, 2) ?>%
                           </strong>
                        </td>
                        <td>
                           <button class="btn btn--primary" onclick="openOcrReviewModal(<?= $oq['id'] ?>)">Audit</button>
                        </td>
                     </tr>
                     <?php endforeach; if(empty($ocr_queue)): ?>
                     <tr>
                        <td colspan="6" style="text-align:center; color:var(--muted);">No anomalies awaiting verification processing.</td>
                     </tr>
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
                  <label class="label">PACE Code Identity</label>
                  <input class="input" name="pace" placeholder="e.g. PACE-1082" required>
                  <label class="label">Reference Target Score Key Dataset Mapping</label>
                  <select class="select" name="score_key_id" required>
                     <option value="">-- Assign Answer Map Context --</option>
                     <?php foreach($score_keys as $key): if($key['is_published']): ?>
                     <option value="<?= $key['id'] ?>"><?= htmlspecialchars($key['pace']) ?> (<?= htmlspecialchars($key['version']) ?>)</option>
                     <?php endif; endforeach; ?>
                  </select>
                  <label class="label">Academic Deadline Milestone</label>
                  <input class="input" type="date" name="due_date" required>
                  <button type="submit" class="btn btn--success" style="margin-top:12px">Register Assignment Parameters</button>
               </form>
            </div>
         </div>
      </div>
      <div class="modal" id="uploadKeyModal">
         <div class="modal__content">
            <div class="modal__head">
               <h3 class="modal__title">Ingest Original Source Score Key</h3>
               <button class="btn btn--ghost" onclick="closeModal('uploadKeyModal')">✕</button>
            </div>
            <div class="modal__body">
               <form method="POST" enctype="multipart/form-data" class="form">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="upload_score_key">
                  <label class="label">Target Subject Component (PACE Reference)</label>
                  <input class="input" name="pace_title" placeholder="e.g. Mathematics 1021" required>
                  <label class="label">Document Resource Package (Supported: PDF, PNG, JPG)</label>
                  <input type="file" class="input" name="score_key_file" accept=".pdf,image/*" required>
                  <p class="helper-text">Uploading initializes the engine pipeline to auto-generate answer matching structures automatically.</p>
                  <button type="submit" class="btn btn--primary" style="margin-top:12px">Engage Extraction Engine</button>
               </form>
            </div>
         </div>
      </div>
      <div class="modal" id="ocrReviewModal">
         <div class="modal__content">
            <div class="modal__head">
               <h3 class="modal__title">OCR Confidence Resolution Panel</h3>
               <button class="btn btn--ghost" onclick="closeModal('ocrReviewModal')">✕</button>
            </div>
            <div class="modal__body">
               <form id="ocrReviewForm" class="form">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="process_ocr">
                  <input type="hidden" name="ocr_id" id="review_ocr_id">
                  <label class="label">Engine Read Parsing Output</label>
                  <input class="input" name="extracted_answer" id="ocr_extracted_answer">
                  <label class="label">Review Action Selection</label>
                  <select class="select" name="review_action" id="ocr_review_action" required>
                     <option value="Approve">Verify & Accept Confidence Readout</option>
                     <option value="Edit">Apply Correction Override Entry</option>
                     <option value="Reject">Invalidate Extraction: Force Re-scan Loop</option>
                  </select>
                  <button type="submit" class="btn btn--success" style="margin-top:12px">Commit Integrity Review Action</button>
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
                  <label class="label">Update Operational Pipeline Status</label>
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
               <form id="chatDispatchForm" class="form">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="send_msg">
                  <input type="hidden" name="student_id" id="chat_student_id">
                  <div style="display:flex; gap:8px;">
                     <input class="input" name="message" placeholder="Type text alert instruction..." required>
                     <button type="submit" class="btn btn--primary">Send</button>
                  </div>
               </form>
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
         
         function openUploadKeyModal() { openModal('uploadKeyModal'); }
         
         async function openOcrReviewModal(ocrId) {
             let r = await fetch('?action=get_ocr_details&ocr_id=' + ocrId);
             let data = await r.json();
             if(!data.error) {
                 document.getElementById('review_ocr_id').value = ocrId;
                 document.getElementById('ocr_extracted_answer').value = data.extracted_answer;
                 openModal('ocrReviewModal');
             }
         }
         
         document.getElementById('ocrReviewForm').addEventListener('submit', async (e) => {
             e.preventDefault();
             let d = new FormData(e.target);
             let r = await fetch('', {method: 'POST', body: d, headers: {'X-Requested-With': 'XMLHttpRequest'}});
             let res = await r.json();
             if(res.status === 'success') location.reload();
             else alert(res.message || 'Operation Failed');
         });
         
         async function openAssignmentViewer(asmId) {
             let r = await fetch('?action=get_assignment_details&assignment_id=' + asmId);
             let data = await r.json();
             if(!data.error) {
                 document.getElementById('lifecycle_assignment_id').value = asmId;
                 let container = document.getElementById('assignmentDetailsContent');
                 container.innerHTML = `
                     <div><strong>Student Target:</strong> ${data.student_name}</div>
                     <div><strong>Target PACE Code:</strong> ${data.pace}</div>
                     <div><strong>Due Milestone:</strong> ${data.due_date}</div>
                     <div><strong>Assigned System Map Vector:</strong> ${data.score_key_version || 'None Matrix Linked'}</div>
                     <div><strong>Current Stage Status Badge:</strong> <span class="badge">${data.status}</span></div>
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
         
         async function openChatModal(studentId, studentName) {
             document.getElementById('chat_student_id').value = studentId;
             document.getElementById('chatModalTitle').innerText = "Direct Line: " + studentName;
             openModal('chatModal');
             let box = document.getElementById('chatBoxContainer');
             box.innerHTML = 'Loading conversations thread...';
             let r = await fetch('?action=get_chat&student_id=' + studentId);
             let messages = await r.json();
             let markup = '';
             messages.forEach(m => {
                 let isMe = m.sender_id == <?= $teacher_id ?>;
                 let alignment = isMe ? 'text-align:right;' : 'text-align:left;';
                 let background = isMe ? 'rgba(110,139,255,0.1)' : 'rgba(255,255,255,0.04)';
                 markup += `<div style="${alignment} margin-bottom:8px;">
                     <div style="display:inline-block; padding:8px 12px; background:${background}; border-radius:8px; max-width:80%; font-size:13px; text-align:left;">
                         ${m.body}
                     </div>
                 </div>`;
             });
             box.innerHTML = markup || '<div style="color:var(--muted); font-size:12px; text-align:center;">No direct thread interaction logged.</div>';
         }
         
         document.getElementById('chatDispatchForm').addEventListener('submit', async (e) => {
             e.preventDefault();
             let form = e.target;
             let d = new FormData(form);
             let sId = document.getElementById('chat_student_id').value;
             let r = await fetch('', {method: 'POST', body: d, headers: {'X-Requested-With': 'XMLHttpRequest'}});
             let res = await r.json();
             if(res.status === 'success') {
                 form.querySelector('input[name="message"]').value = '';
                 openChatModal(sId, document.getElementById('chatModalTitle').innerText.replace("Direct Line: ", ""));
             }
         });
      </script>
   </body>
</html>