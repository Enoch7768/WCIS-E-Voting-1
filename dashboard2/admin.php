<?php
session_start();
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/csrf.php';
require_admin();

$notice = null; $error = null;

function valid_role($r){ return in_array($r, ['admin','teacher','student'], true); }

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['teacher_id'])) {
    $teacher_id = (int)$_GET['teacher_id'];
    $stmt = $pdo->prepare('
        SELECT u.id, u.full_name, u.email 
        FROM users u 
        JOIN teacher_student ts ON u.id = ts.student_id 
        WHERE ts.teacher_id = ? 
        ORDER BY u.id ASC
    ');
    $stmt->execute([$teacher_id]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) { 
        $error = 'Invalid CSRF token.'; 
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create' || $action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $role = $_POST['role'] ?? 'student';
                $password = $_POST['password'] ?? '';
                
                if ($full_name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !valid_role($role)) {
                    throw new Exception('Invalid input.');
                }
                
				if ($action === 'create') {
					if (!preg_match('/[A-Z]/', $password)) {
						throw new Exception('Weak password.');
					}
					$hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password, role, is_verified) VALUES (?, ?, ?, ?, 1)');
                    $stmt->execute([$full_name, $email, $hash, $role]);
                } else {
                    if ($password !== '') {
                        if (!preg_match('/[A-Z]/', $password)) {
                            throw new Exception('Weak password.');
                        }
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET full_name=?, email=?, role=?, password=? WHERE id=?');
                        $stmt->execute([$full_name, $email, $role, $hash, $id]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE users SET full_name=?, email=?, role=? WHERE id=?');
                        $stmt->execute([$full_name, $email, $role, $id]);
                    }
                }
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
                }
            }
            
            if ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0 || $id === $_SESSION['user_id']) throw new Exception('Invalid delete.');
                $stmt = $pdo->prepare('DELETE FROM users WHERE id=?'); 
                $stmt->execute([$id]);
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
                }
            }

            if ($action === 'assign_student') {
                $teacher_id = (int)($_POST['teacher_id'] ?? 0);
                $student_id = (int)($_POST['student_id'] ?? 0);
                if ($teacher_id <= 0 || $student_id <= 0) throw new Exception('Invalid assignment configuration.');

                $chk = $pdo->prepare('SELECT 1 FROM teacher_student WHERE teacher_id = ? AND student_id = ?');
                $chk->execute([$teacher_id, $student_id]);
                if ($chk->fetch()) throw new Exception('This student is already assigned to this teacher.');

                $stmt = $pdo->prepare('INSERT INTO teacher_student (teacher_id, student_id) VALUES (?, ?)');
                $stmt->execute([$teacher_id, $student_id]);
                echo json_encode(['status' => 'success']); exit;
            }

            if ($action === 'remove_assignment') {
                $teacher_id = (int)($_POST['teacher_id'] ?? 0);
                $student_id = (int)($_POST['student_id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM teacher_student WHERE teacher_id = ? AND student_id = ?');
                $stmt->execute([$teacher_id, $student_id]);
                echo json_encode(['status' => 'success']); exit;
            }
            
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json'); echo json_encode(['status' => 'error', 'message' => $error]); exit;
            }
        }
    }
}

$users = $pdo->query('SELECT id, full_name, email, role, is_verified, created_at FROM users ORDER BY id DESC')->fetchAll();
$teachers = $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'teacher' ORDER BY full_name ASC")->fetchAll();
$students = $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'student' ORDER BY full_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="shortcut icon" href="../WCIS_LOGO-1-removebg-preview.png" type="image/x-icon">
<title>Admin Dashboard</title>
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

.card--narrow {
	width: min(420px, 94vw);
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
	font-size: 13px
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

.logo--placeholder::after {
	content: "LOGO";
	font-size: 10px;
	letter-spacing: .6px;
}

.brand {
	font-weight: 700;
	letter-spacing: .6px;
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

.row {
	display: grid;
	gap: 12px;
	grid-template-columns: 1fr 1fr;
}

@media (max-width:640px) {
	.row {
		grid-template-columns: 1fr
	}
}

.helper-text {
	font-size: 12px;
	color: var(--muted)
}

.alert {
	padding: 12px 14px;
	border-radius: 12px;
	font-size: 14px
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
	border: 1px solid rgba(255, 255, 255, .08)
}

.table {
	width: 100%;
	border-collapse: collapse
}

.table th,
.table td {
	padding: 14px 12px;
	border-bottom: 1px solid rgba(255, 255, 255, .06);
	text-align: left;
	font-size: 14px
}

.table th {
	color: var(--muted);
	font-weight: 600;
	background: rgba(255, 255, 255, .02);
	position: sticky;
	top: 0;
	backdrop-filter: blur(6px)
}

.table tr:hover td {
	background: rgba(255, 255, 255, .03)
}

.toolbar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	margin: 16px 0 10px
}

.search {
	display: flex;
	gap: 10px;
	align-items: center;
	background: #0f142a;
	border: 1px solid rgba(255, 255, 255, .08);
	border-radius: 12px;
	padding: 8px 12px
}

.search input {
	background: transparent;
	border: none;
	outline: none;
	color: var(--text)
}

.badge {
	padding: 6px 10px;
	border-radius: 100px;
	font-size: 12px;
	border: 1px solid rgba(255, 255, 255, .12);
	color: var(--muted)
}

.badge--admin {
	color: #ffd8b1;
	border-color: #ffd8b1
}

.badge--teacher {
	color: #b1d9ff;
	border-color: #b1d9ff
}

.badge--student {
	color: #b1ffcf;
	border-color: #b1ffcf
}

.modal {
	position: fixed;
	inset: 0;
	display: none;
	place-items: center;
	background: rgba(5, 8, 18, .55)
}

.modal.open {
	display: grid
}

.modal__content {
	width: min(560px, 94vw);
	background: var(--card);
	border: 1px solid rgba(255, 255, 255, .12);
	border-radius: 18px;
	box-shadow: var(--shadow-md)
}

.modal__head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 18px 20px;
	border-bottom: 1px solid rgba(255, 255, 255, .06)
}

.modal__body {
	padding: 18px 20px
}

.modal__title {
	margin: 0;
	font-size: 18px
}

.footer {
	margin-top: 24px;
	display: flex;
	justify-content: center;
	gap: 12px;
	color: var(--muted);
	font-size: 12px
}

.footer a {
	color: var(--muted)
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
</style>
</head>
<body>
  <main class="container-center">
    <section class="card">
    <header class="card__header">
    <div class="header" style="justify-content: space-between; width:100%">
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="logo"><img src="../WCIS_LOGO-1-removebg-preview.png"></div>
        <div>
      <h1 class="card__title">Admin Dashboard</h1>
      <p class="card__sub">User & Academic Relationship Management</p>
    </div>
  </div>
  <a class="btn btn--ghost" href="../auth/logout.php">Logout</a>
</div>
</header>
<div class="card__body">

<?php if($error): ?>
    <div class="alert alert--error" style="margin-bottom:16px;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="toolbar"><button class="btn btn--primary" onclick="openUserModal()">+ Add User</button></div>
<div class="table-wrap"><table class="table">
<thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Verified</th><th>Created</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
<td><?= $u['id'] ?></td>
<td><?= htmlspecialchars($u['full_name']) ?></td>
<td><?= htmlspecialchars($u['email']) ?></td>
<td>
  <span class="badge badge--<?= $u['role'] ?>"><?= htmlspecialchars($u['role']) ?></span>
</td>
<td><?= $u['is_verified']?'Yes':'No' ?></td>
<td><?= htmlspecialchars($u['created_at']) ?></td>
<td>
<button class="btn btn--ghost" onclick="openUserModal(<?= $u['id'] ?>,'<?= htmlspecialchars($u['full_name'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['email'],ENT_QUOTES) ?>','<?= $u['role'] ?>')">Edit</button>
<button class="btn btn--danger" onclick="deleteUser(<?= $u['id'] ?>)">Delete</button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>

<h3>Teacher Assignments</h3>
<p class="card__sub" style="margin-bottom: 12px;">Manage academic clusters by allocating students to their respective instructors.</p>

<div class="table-wrap" style="margin-top:12px"><table class="table">
<thead><tr><th>ID</th><th>Teacher Name</th><th>Email</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($teachers as $t): ?>
<tr>
<td><?= $t['id'] ?></td>
<td><?= htmlspecialchars($t['full_name']) ?></td>
<td><?= htmlspecialchars($t['email']) ?></td>
<td>
<button class="btn btn--primary" onclick="openAssignmentModal(<?= $t['id'] ?>, '<?= htmlspecialchars($t['full_name'], ENT_QUOTES) ?>')">Manage Students</button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
    </tbody>
  </table>
</div>

</div></section>

<div class="modal" id="userModal">
<div class="modal__content">
<div class="modal__head"><h3 class="modal__title">User Accounts</h3><button class="btn btn--ghost" onclick="closeUserModal()">✕</button></div>
<div class="modal__body">
<form id="userForm" class="form">
<input type="hidden" name="csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="id" id="user_id">
<label class="label">Full Name</label><input class="input" name="full_name" id="user_full_name" required>
<label class="label">Email</label><input class="input" name="email" id="user_email" type="email" required>
<label class="label">Role</label><select class="select" name="role" id="user_role"><option value="student">student</option><option value="teacher">teacher</option><option value="admin">admin</option></select>
<label class="label">Password (leave blank to keep current)</label><input class="input" type="password" name="password">
<div style="margin-top: 12px; display:flex; gap: 8px;">
  <button type="submit" class="btn btn--success">Save</button>
  <button type="button" class="btn btn--ghost" onclick="closeUserModal()">Cancel</button>
</div>
</form>
</div></div></div>

<div class="modal" id="assignmentModal">
  <div class="modal__content">
    <div class="modal__head">
      <h3 class="modal__title" id="assignmentModalTitle">Assigned Students</h3>
      <button class="btn btn--ghost" onclick="closeAssignmentModal()">✕</button>
    </div>
    <div class="modal__body">
      <form id="assignmentForm" class="form">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="teacher_id" id="assign_teacher_id">

        <label class="label">Select Student to Assign</label>
        <select class="select" name="student_id" id="assign_student_select" required>
            <option value="">-- Choose Student --</option>
            <?php foreach($students as $st): ?>
                <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['full_name']) ?> (<?= htmlspecialchars($st['email']) ?>)</option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn--success" style="margin-top:12px">Link Student</button>
      </form>

      <div id="assignmentStudentList" style="margin-top:16px"></div>
    </div>
  </div>
</div>

<script>
function openUserModal(id='',name='',email='',role='student'){document.getElementById('user_id').value=id;document.getElementById('user_full_name').value=name;document.getElementById('user_email').value=email;document.getElementById('user_role').value=role;document.getElementById('userModal').classList.add('open');}
function closeUserModal(){document.getElementById('userModal').classList.remove('open');}
document.getElementById('userForm').addEventListener('submit',async e=>{e.preventDefault();let d=new FormData(e.target);d.set('action',d.get('id')?'update':'create');let r=await fetch('',{method:'POST',body:d,headers:{'X-Requested-With':'XMLHttpRequest'}});let j=await r.json();if(j.status==='success')location.reload();else alert(j.message||'Error');});
function deleteUser(id){if(!confirm('Delete this user?'))return;fetch('',{method:'POST',body:new URLSearchParams({csrf:'<?= csrf_token() ?>',action:'delete',id:id}),headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(j=>{if(j.status==='success')location.reload();else alert(j.message||'Error');});}

function openAssignmentModal(teacherId, teacherName){
    document.getElementById('assign_teacher_id').value = teacherId;
    document.getElementById('assignmentModalTitle').innerText = "Students of " + teacherName;
    document.getElementById('assignmentModal').classList.add('open');
    loadAssignedStudents(teacherId);
}
function closeAssignmentModal(){document.getElementById('assignmentModal').classList.remove('open');}

async function loadAssignedStudents(teacherId){
    let list = document.getElementById('assignmentStudentList');
    list.innerHTML = '<div class="helper-text">Loading relations...</div>';
    let res = await fetch('?teacher_id=' + teacherId);
    let j = await res.json();
    let h = '';
    if(j.length === 0){
        h = '<div class="helper-text" style="padding:8px 0;">No students mapped to this instructor yet.</div>';
    } else {
        j.forEach(s => {
            h += `<div style="display:flex;align-items:center;margin-bottom:8px;padding:6px;background:rgba(255,255,255,0.02);border-radius:8px;">
                    <div>
                        <div style="font-size:14px;font-weight:600;">${s.full_name}</div>
                        <div style="font-size:12px;color:var(--muted);">${s.email}</div>
                    </div>
                    <button style="margin-left:auto" onclick="removeAssignment(${teacherId},${s.id})" type="button" class="btn btn--danger">Unlink</button>
                  </div>`;
        });
    }
    list.innerHTML = h;
}

document.getElementById('assignmentForm').addEventListener('submit', async e => {
    e.preventDefault();
    let d = new FormData(e.target);
    d.set('action', 'assign_student');
    let r = await fetch('', {method: 'POST', body: d, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    let j = await r.json();
    if(j.status === 'success') {
        loadAssignedStudents(d.get('teacher_id'));
        document.getElementById('assign_student_select').value = '';
    } else {
        alert(j.message || 'Error occurred');
    }
});

function removeAssignment(teacherId, studentId){
    if(!confirm('Break this student-teacher connection?')) return;
    fetch('', {
        method: 'POST',
        body: new URLSearchParams({csrf: '<?= csrf_token() ?>', action: 'remove_assignment', teacher_id: teacherId, student_id: studentId}),
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).then(r => r.json()).then(j => {
        if(j.status === 'success') loadAssignedStudents(teacherId);
        else alert(j.message || 'Error');
    });
}
</script>
</body>
</html>