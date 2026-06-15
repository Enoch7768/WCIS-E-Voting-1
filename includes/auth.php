<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function require_role(string $role): void {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== $role) {
        header('Location: ../access_denied.html');
        exit;
    }
    
}

function require_admin(): void { require_role('admin'); }
function require_teacher(): void { require_role('teacher'); }
function require_student(): void { require_role('student'); }
?>
