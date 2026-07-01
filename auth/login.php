<?php
session_start();
require '../includes/db.php';

if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ../dashboard/admin.php');
    exit;
}
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'teacher') {
    header('Location: ../dashboard/teacher.php');
    exit;
}
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student') {
    header('Location: ../dashboard/student.php');
    exit;
}

$error = null;
$email_value = '';
$role_value = '';
$service_value = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $service = $_POST['service'] ?? ''; 

    $email_value = $email;
    $role_value = $role;
    $service_value = $service;

    $stmt = $pdo->prepare('SELECT id, full_name, email, password, role, is_verified FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        $error = 'Invalid email or password.';
    } elseif ((int)$user['is_verified'] !== 1) {
        $error = 'Account is not verified.';
    } elseif ($user['role'] !== $role) {
        $error = 'Selected role does not match your account.';
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['service'] = $service; 

        if ($service === 'voting') {
            if ($user['role'] === 'admin') {
                header('Location: ../dashboard/admin.php');
            } elseif ($user['role'] === 'teacher') {
                header('Location: ../dashboard/teacher.php');
            } else {
                header('Location: ../dashboard/student.php');
            }
        } else {

            if ($user['role'] === 'admin') {
                header('Location: ../dashboard2/admin.php');
            } elseif ($user['role'] === 'teacher') {
                header('Location: ../dashboard2/teacher.php');
            } else {
                header('Location: ../dashboard2/student.php');
            }
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — School Portal</title>
<link rel="shortcut icon" href="../WCIS_LOGO-1-removebg-preview.png" type="image/x-icon">
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
   box-sizing: border-box
}

html,
body {
   height: 100%;
   margin: 0;
   font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Helvetica, Arial;
   background:
      radial-gradient(1200px 800px at 80% -10%, rgba(110, 139, 255, .18), transparent),
      radial-gradient(900px 600px at -10% 110%, rgba(76, 212, 168, .08), transparent),
      var(--bg);
   color: var(--text);
   display: flex;
   align-items: center;
   justify-content: center;
}

.container-center {
   width: 800px;
   max-width: 1400px;
   padding: 60px
}

.card {
   background: linear-gradient(180deg, rgba(255, 255, 255, .04), rgba(255, 255, 255, .02));
   backdrop-filter: blur(10px);
   border: 1px solid rgba(255, 255, 255, .08);
   border-radius: var(--radius);
   box-shadow: var(--shadow-lg);
   padding: 48px;
   text-align: center;
}

.logo {
   width: 100px;
   height: 100px;
   margin: 0 auto 20px;
   border-radius: 12px;
   background: linear-gradient(135deg, var(--primary), #9eaaff);
   display: grid;
   place-items: center;
   overflow: hidden;
   transition: transform 0.3s ease, filter 0.3s ease;
}

.logo img {
   width: 100%;
   height: 90%;
   object-fit: contain;
}

.logo.pulse img {
   animation: pulse 2.5s infinite ease-in-out;
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

.card h1 {
   margin: 16px 0 12px;
   font-size: 28px;
}

.card p {
   margin: 0 0 24px;
   color: var(--muted);
}

.form {
   display: grid;
   gap: 18px;
   margin-top: 16px;
   text-align: left;
}

.label {
   font-size: 14px;
   color: var(--muted);
}

.input,
.select {
   width: 100%;
   padding: 16px 18px;
   border-radius: 12px;
   color: var(--text);
   background: linear-gradient(135deg, rgba(110, 139, 255, .2), rgba(76, 212, 168, .1));
   border: 1px solid rgba(255, 255, 255, .08);
   outline: none;
   transition: all 0.3s ease;
}

.input:focus,
.select:focus {
   background: linear-gradient(135deg, rgba(110, 139, 255, .35), rgba(76, 212, 168, .2));
   box-shadow: 0 0 16px rgba(110, 139, 255, .5);
   border-color: rgba(110, 139, 255, .6);
}

.select {
   appearance: none;
   background-image: linear-gradient(135deg, rgba(110, 139, 255, .2), rgba(76, 212, 168, .1)),
      url('data:image/svg+xml;utf8,<svg fill="%23e9ecf8" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/></svg>');
   background-repeat: no-repeat;
   background-position: right 16px center;
   background-size: 24px 24px;
}

.btn {
   cursor: pointer;
   user-select: none;
   border: none;
   border-radius: 12px;
   padding: 16px 20px;
   font-weight: 700;
   transition: all 0.3s ease;
}

.btn--primary {
   background: linear-gradient(270deg, #6e8bff, #3a5dff, #6e8bff);
   background-size: 600% 600%;
   animation: gradientBG 4s ease infinite;
   color: #fff;
   box-shadow: 0 10px 35px rgba(110, 139, 255, .35);
}

@keyframes gradientBG {
   0% {
      background-position: 0% 50%;
   }

   50% {
      background-position: 100% 50%;
   }

   100% {
      background-position: 0% 50%;
   }
}

.btn--primary:hover {
   transform: translateY(-2px);
   box-shadow: 0 15px 40px rgba(110, 139, 255, .45);
}

.alert {
   padding: 16px 18px;
   border-radius: 12px;
   font-size: 15px;
   margin-bottom: 16px;
}

.alert--error {
   background: rgba(255, 91, 110, .12);
   border: 1px solid rgba(255, 91, 110, .3);
   color: #ffb3bd;
}

.footer {
   margin-top: 32px;
   font-size: 14px;
   color: var(--muted);
}

/* ============ Responsive & Fun Animations (added) ============ */
@keyframes fadeInUp { from { opacity:0; transform:translateY(16px);} to { opacity:1; transform:translateY(0);} }
@keyframes shakeX { 10%,90%{transform:translateX(-1px);} 20%,80%{transform:translateX(2px);} 30%,50%,70%{transform:translateX(-4px);} 40%,60%{transform:translateX(4px);} }

.card { animation: fadeInUp .55s ease both; }
.alert--error { animation: shakeX .4s ease; }
.input, .select { transition: box-shadow .25s ease, border-color .25s ease, transform .15s ease; }
.input:focus, .select:focus { transform: translateY(-1px); }
.btn--primary { transition: transform .18s ease, box-shadow .18s ease; }
.btn--primary:hover { transform: translateY(-2px); }
.btn--primary:active { transform: translateY(0) scale(.97); }

/* ============ Responsive breakpoints (added) ============ */
@media (max-width: 900px) {
    .container-center { width: 100%; max-width: 100%; padding: 32px 16px; }
    .card { padding: 36px 28px; }
}

@media (max-width: 480px) {
    .container-center { padding: 20px 12px; }
    .card { padding: 24px 18px; border-radius: 14px; }
    .card h1 { font-size: 22px; }
    .card p { font-size: 14px; margin-bottom: 18px; }
    .logo { width: 76px; height: 76px; }
    .input, .select, .btn { padding: 13px 14px; font-size: 15px; }
}

</style>
</head>
<body>
<div class="container-center">
    <div class="card">
        <div class="logo pulse">
            <img src="../WCIS_LOGO-1-removebg-preview.png" alt="Logo">
        </div>
        <h1>School Portal Login</h1>
        <p>Sign in to access your dashboard</p>

        <?php if($error): ?>
            <div class="alert alert--error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="form" autocomplete="off">
            <label class="label" for="email">Email</label>
            <input class="input" type="email" id="email" name="email" placeholder="you@school.local" value="<?= htmlspecialchars($email_value) ?>" required>

            <label class="label" for="password">Password</label>
            <input class="input" type="password" id="password" name="password" placeholder="••••••••" required>

            <label class="label" for="service">Service</label>
            <select class="select" id="service" name="service" required>
                <option value="" disabled <?= $service_value === '' ? 'selected' : '' ?>>Select service</option>
                <option value="online" <?= $service_value === 'online' ? 'selected' : '' ?>>WCIS Online</option>
                <option value="voting" <?= $service_value === 'voting' ? 'selected' : '' ?>>Voting</option>
            </select>

            <label class="label" for="role">Role</label>
            <select class="select" id="role" name="role" required>
                <option value="" disabled <?= $role_value === '' ? 'selected' : '' ?>>Select your role</option>
                <option value="admin" <?= $role_value === 'admin' ? 'selected' : '' ?>>Admin</option>
                <option value="teacher" <?= $role_value === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                <option value="student" <?= $role_value === 'student' ? 'selected' : '' ?>>Student</option>
            </select>

            <button type="submit" class="btn btn--primary">Sign In</button>
        </form>

        <div class="footer">© <?= date('Y') ?> Watoto Christian International School</div>
    </div>
</div>
</body>
</html>