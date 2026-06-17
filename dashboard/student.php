<?php
session_start();
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/csrf.php';
require_student();

$notice = null;
$error = null;

$positions = $pdo->query('SELECT * FROM positions ORDER BY id ASC')->fetchAll();

$candidates_by_position = [];
foreach ($positions as $pos) {
    $stmt = $pdo->prepare('SELECT id, name AS full_name, image FROM candidates WHERE position_id=? ORDER BY id ASC');
    $stmt->execute([$pos['id']]);
    $candidates_by_position[$pos['id']] = $stmt->fetchAll();
}


$user_votes_stmt = $pdo->prepare('
    SELECT c.position_id, v.candidate_id 
    FROM votes v
    JOIN candidates c ON v.candidate_id = c.id
    WHERE v.user_id = ?
');
$user_votes_stmt->execute([$_SESSION['user_id']]);
$user_votes = $user_votes_stmt->fetchAll(PDO::FETCH_KEY_PAIR); 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        $candidate_id = (int)($_POST['candidate_id'] ?? 0);
        $position_id  = (int)($_POST['position_id'] ?? 0);
        if ($candidate_id <= 0 || $position_id <= 0) {
            $error = 'Invalid candidate.';
        } elseif (isset($user_votes[$position_id])) {
            $error = 'You have already voted for this position.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO votes (user_id, candidate_id) VALUES (?, ?)');
            $stmt->execute([$_SESSION['user_id'], $candidate_id]);
            $notice = 'Your vote has been recorded.';
            $user_votes[$position_id] = $candidate_id;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard</title>
<link rel="shortcut icon" href="../WCIS_LOGO-1-removebg-preview.png" type="image/x-icon">
<style>
*{box-sizing:border-box}
body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,Helvetica,Arial;background:#0b1020;color:#fff;min-height:100vh;}
.container-center{max-width:1100px;margin:auto;padding:20px;}
.card{background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02));border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:28px;margin-bottom:20px;box-shadow:0 6px 24px rgba(110,139,255,.25);}
.card h1,.card h2{margin-top:0;}
.alert{padding:12px 14px;border-radius:12px;font-size:14px;margin-bottom:12px;}
.alert--error{background:rgba(255,91,110,.12);border:1px solid rgba(255,91,110,.3);color:#ffb3bd;}
.alert--success{background:rgba(76,212,168,.12);border:1px solid rgba(76,212,168,.3);color:#b8f3e1;}
.candidates{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;}
.candidate{background:#0f142a;padding:16px;border-radius:16px;border:1px solid rgba(255,255,255,.08);display:flex;flex-direction:column;align-items:center;transition: transform 0.3s ease, box-shadow 0.3s ease;text-align:center;}
.candidate img{width:120px;height:120px;object-fit:cover;border-radius:12px;margin-bottom:12px;transition: transform 0.3s ease, filter 0.3s ease;}
.candidate:hover{transform: translateY(-4px);box-shadow:0 12px 24px rgba(110,139,255,.35);}
.candidate:hover img{transform: scale(1.05);filter: drop-shadow(0 0 12px rgba(110,139,255,0.6));}
.candidate-info{margin-bottom:12px;}
.btn{cursor:pointer;user-select:none;border:none;border-radius:12px;padding:10px 16px;font-weight:700;}
.btn--primary{background:linear-gradient(180deg,#6e8bff,#5f73ff);color:#fff;}
.btn--disabled{background:rgba(255,255,255,.12);color:#888;cursor:not-allowed;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.header a{color:#fff;text-decoration:none;padding:8px 12px;border:1px solid rgba(255,255,255,.12);border-radius:12px;}
.position-title{margin-top:0;margin-bottom:12px;border-bottom:1px solid rgba(255,255,255,.08);padding-bottom:4px;}
</style>
</head>
<body>
<div class="container-center">
  <div class="header">
    <h1>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h1>
    <a href="../auth/logout.php">Logout</a>
  </div>

  <?php if($notice): ?><div class="alert alert--success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
  <?php if($error): ?><div class="alert alert--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php foreach($positions as $pos): ?>
  <div class="card">
    <h2 class="position-title"><?= htmlspecialchars($pos['name']) ?></h2>
    <div class="candidates">
      <?php foreach($candidates_by_position[$pos['id']] as $c): 
        $candidate_img = $c['image']; 
        if (!file_exists('' . $candidate_img) || empty($candidate_img)) {
            $candidate_img = '../WCIS_LOGO-1-removebg-preview.png';
        }
        $voted = isset($user_votes[$pos['id']]);
      ?>
      <form method="POST" class="candidate">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="candidate_id" value="<?= $c['id'] ?>">
        <input type="hidden" name="position_id" value="<?= $pos['id'] ?>">
        <img src="<?= htmlspecialchars($candidate_img) ?>" alt="Candidate">
        <div class="candidate-info">
          <!-- Keeps working smoothly because of our custom query alias 'name AS full_name' -->
          <strong><?= htmlspecialchars($c['full_name']) ?></strong>
        </div>
        <button class="btn <?= $voted ? 'btn--disabled' : 'btn--primary' ?>" type="submit" <?= $voted ? 'disabled' : '' ?>>
          <?= $voted ? 'Voted' : 'Vote' ?>
        </button>
      </form>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
</body>
</html>