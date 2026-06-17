<?php
   session_start();
   require '../includes/db.php';
   require '../includes/auth.php';
   require '../includes/csrf.php';
   require '../includes/config.php'; 
   require_student();
   
   $student_id = $_SESSION['user_id'] ?? 0;
   $notice = null; $error = null;
   function gemini_extract_answers($image_path, $questions_json) {
       $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
       if (empty($api_key)) {
           throw new Exception('Gemini API key is not configured.');
       }
       $image_data = base64_encode(file_get_contents($image_path));
       $mime = mime_content_type($image_path);
       
       $prompt = "Extract student answers from this exam page. The questions are: " . json_encode($questions_json) . 
                 " Return a JSON array with objects: {'question_number': string, 'extracted_answer': string, 'confidence': float (0-1)}. Only return valid JSON.";
       
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
       // Extract JSON from response
       preg_match('/\[.*\]/s', $text, $matches);
       $json = $matches[0] ?? '[]';
       return json_decode($json, true);
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
               ORDER BY o.question_number ASC
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
   }

   
   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
       if (!csrf_check($_POST['csrf'] ?? '')) { 
           $error = 'Invalid CSRF security signature token.'; 
       } else {
           $action = $_POST['action'] ?? '';
           try {
               if ($action === 'upload_submission' || $action === 'upload_correction') {
                   $asm_id = (int)($_POST['assignment_id'] ?? 0);
                   
                   $chk = $pdo->prepare("SELECT a.status, a.score_key_id, sk.question_structure FROM assignments a LEFT JOIN score_keys sk ON a.score_key_id = sk.id WHERE a.id = ? AND a.student_id = ?");
                   $chk->execute([$asm_id, $student_id]);
                   $asm_data = $chk->fetch();
                   if (!$asm_data) throw new Exception('Assignment context mismatch verification error.');
                   if (empty($asm_data['question_structure'])) throw new Exception('Score key not published or missing question structure.');
                   $questions = json_decode($asm_data['question_structure'], true);
                   
                   if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === 0) {
                       $fileName = $_FILES['submission_file']['name'];
                       $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                       $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                       if (!in_array($ext, $allowed, true)) throw new Exception('Unsupported visual payload file format.');
                       
                       if (!is_dir('uploads/submissions')) mkdir('uploads/submissions', 0755, true);
                       $destPath = 'uploads/submissions/' . uniqid('sub_', true) . '.' . $ext;
                       move_uploaded_file($_FILES['submission_file']['tmp_name'], $destPath);
                       
                       $pdo->prepare("DELETE FROM ocr_results WHERE assignment_id = ?")->execute([$asm_id]);
                       
                       $extracted = gemini_extract_answers($destPath, $questions);
                       
                       $insert = $pdo->prepare("INSERT INTO ocr_results (assignment_id, question_number, extracted_answer, confidence, status) VALUES (?, ?, ?, ?, ?)");
                       foreach ($extracted as $item) {
                           $confidence = isset($item['confidence']) ? (float)$item['confidence'] : 0.85;
                           $status = ($confidence >= 0.80) ? 'Approved' : 'Pending Review';
                           $insert->execute([$asm_id, $item['question_number'], $item['extracted_answer'], $confidence, $status]);
                       }
                       
                       $new_status = 'In Progress';
                       $pdo->prepare("UPDATE assignments SET status = ? WHERE id = ?")->execute([$new_status, $asm_id]);
                       $notice = "Visual payload ingested. Answers extracted via Gemini AI. Review pending for low-confidence items.";
                   } else {
                       throw new Exception('File upload payload missing or corrupted.');
                   }
               }
   
               if ($action === 'send_chat_msg') {
                   $t_stmt = $pdo->prepare("SELECT teacher_id FROM teacher_student WHERE student_id = ? LIMIT 1");
                   $t_stmt->execute([$student_id]);
                   $t_data = $t_stmt->fetch();
                   if (!$t_data) throw new Exception('No certified instructor link detected.');
                   $teacher_id = $t_data['teacher_id'];
                   $msg_body = trim($_POST['message'] ?? '');
                   if ($msg_body === '') throw new Exception('Message cannot be blank.');
                   $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)");
                   $stmt->execute([$student_id, $teacher_id, $msg_body]);
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
   $active_paces = 0;
   $completed_paces = 0;
   $requires_correction = 0;
   
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
                     <h1 class="card__title">Student Workstation</h1>
                     <p class="card__sub">PACE Evaluation Engine, Automated Submissions & Progress Trackers</p>
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
                  <h4>Assigned PACEs</h4>
                  <p><?= $total_assigned ?></p>
               </div>
               <div class="stat-box">
                  <h4>Active Modules</h4>
                  <p><?= $active_paces ?></p>
               </div>
               <div class="stat-box">
                  <h4>Completed Tasks</h4>
                  <p><?= $completed_paces ?></p>
               </div>
               <div class="stat-box">
                  <h4>Correction Pipeline</h4>
                  <p><?= $requires_correction ?></p>
               </div>
            </div>
            <div class="toolbar" style="margin-top: 12px; padding: 14px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid rgba(255,255,255,0.06); align-items: center;">
               <div>
                  <h4 style="margin:0; font-size:14px; color: var(--text);">Assigned Primary Academic Instructor</h4>
                  <p style="margin:4px 0 0; font-size:13px; color: var(--muted);"><?= htmlspecialchars($my_teacher['full_name']) ?> — <code><?= htmlspecialchars($my_teacher['email']) ?></code></p>
               </div>
               <button class="btn btn--primary" onclick="openChatModal()">Open Direct Line Messages</button>
            </div>
            <div class="toolbar" style="margin-top:24px;">
               <h3 style="margin:0;">My Assigned PACE Modules</h3>
            </div>
            <div class="table-wrap">
               <table class="table">
                  <thead>
                     <tr>
                        <th>PACE Target</th>
                        <th>Due Deadline Date</th>
                        <th>Pipeline Evaluation Status</th>
                        <th>Actions</th>
               </table>
               </thead>
               <tbody>
                  <?php foreach ($my_assignments as $asm): ?>
                  <tr>
                     <td><strong><?= htmlspecialchars($asm['pace']) ?></strong></td>
                     <td><?= htmlspecialchars($asm['due_date']) ?></td>
                     <td>
                        <span class="badge badge--<?= strtolower(str_replace(' ', '', $asm['status'])) ?>"><?= $asm['status'] ?></span>
                     </td>
                     <td>
                        <button class="btn btn--ghost" onclick="openAssignmentDetails(<?= $asm['id'] ?>)">Review Framework</button>
                        <?php if($asm['status'] === 'Needs Correction'): ?>
                        <button class="btn btn--danger" onclick="openUploadModal(<?= $asm['id'] ?>, 'upload_correction')">Upload Corrections</button>
                        <?php else: ?>
                        <button class="btn btn--primary" onclick="openUploadModal(<?= $asm['id'] ?>, 'upload_submission')">Upload Work Pages</button>
                        <?php endif; ?>
                        <button class="btn btn--ghost" onclick="openOcrVerificationView(<?= $asm['id'] ?>)">View OCR Output</button>
                     </td>
                  </tr>
                  <?php endforeach; if(empty($my_assignments)): ?>
                  <tr>
                     <td colspan="4" style="text-align:center; color:var(--muted);">No ongoing curriculum trajectories mapped.</td>
                  </tr>
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
                  <label class="label">Select Scanned Answer Sheet Page (Supported: JPG, JPEG, PNG, WEBP)</label>
                  <input type="file" class="input" name="submission_file" accept="image/*" required>
                  <p class="helper-text">Uploading will trigger Gemini AI to extract answers automatically.</p>
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
                     <div><strong>Active State Pipeline Phase:</strong> <span class="badge">${data.status}</span></div>
                     <div><strong>Active Framework Key Blueprint Version:</strong> <code>${data.key_version || 'Draft Matrice Processing Context'}</code></div>
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
                 markup += `<div style="padding:10px; background:rgba(255,255,255,0.02); border-radius:8px; border:1px solid rgba(255,255,255,0.04); display:flex; justify-content:space-between; align-items:center;">
                     <div>
                         <span style="color:var(--muted); font-size:12px;">Question ${item.question_number}</span>
                         <div style="font-size:14px; font-weight:600; margin-top:2px;">Answer: "${item.extracted_answer}"</div>
                     </div>
                     <div style="text-align:right;">
                         <span class="badge">${item.status}</span>
                         <div style="font-size:11px; color:var(--primary); margin-top:4px;">Confidence: ${(item.confidence * 100).toFixed(1)}%</div>
                     </div>
                 </div>`;
             });
             container.innerHTML = markup || '<div style="color:var(--muted); text-align:center; font-size:13px; padding:12px;">No engine scans logged for this block configuration yet.</div>';
             openModal('ocrModal');
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
      </script>
   </body>
</html>