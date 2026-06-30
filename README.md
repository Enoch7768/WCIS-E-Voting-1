# WCIS School Portal

A PHP-based web portal for Watoto Christian International School (WCIS) that serves two distinct services from a single login: a **Voting** system (student council elections) and an academic **Online** service (assignment pacing, score keys, and teacher-student messaging). Access is role-based across three roles — Admin, Teacher, and Student.

## Features

### Authentication (`login.php`, `logout.php`)
- Single login form with three selectors: email/password, **service** (`online` or `voting`), and **role** (`admin`, `teacher`, `student`).
- Validates credentials with `password_verify()`, checks account verification status, and confirms the selected role matches the account's actual role.
- Routes authenticated users to the correct dashboard set (`/dashboard` for voting, `/dashboard2` for the online service) based on the chosen service.
- Session-based auth with `session_regenerate_id()` on login/logout to mitigate session fixation.

### Admin Dashboard (`admin.php`)
- Full user management: create, update, and delete users (admin/teacher/student) with email validation and password strength checks.
- Assigns and unassigns students to teachers (`teacher_student` relationship table).
- AJAX endpoints (`teacher_id` query, JSON responses) for dynamically loading a teacher's assigned students.

### Teacher Dashboard (`teacher.php`)
- Manages student assignments/pacing (`assign_pace`, `update_assignment`, `update_assignment_status`).
- Uploads and republishes score keys for assignments (`upload_score_key`, `reupload_score_key`, `publish_score_key`).
- In-app messaging with students (`send_msg`, `get_chat`) and a notification system (`get_notifications`, `mark_notification_read`).
- Centralized error logging to a local `logs/teacher_errors.log` file via a custom exception handler.

### Student Dashboard (`student.php`)
- Displays election **positions** and their **candidates** (with photos) for the voting service.
- Lets a student cast one vote per position; previously-voted positions are shown as disabled.
- CSRF-protected vote submission; prevents duplicate votes per position at the database query level.

## Tech Stack
- **Backend:** PHP (procedural, PDO for database access)
- **Database:** MySQL/MariaDB (tables include `users`, `positions`, `candidates`, `votes`, `teacher_student`, `assignments`, `score_keys`, `notifications`)
- **Frontend:** Server-rendered HTML with vanilla CSS (dark, glassmorphism-style UI) — no external frontend framework
- **Security:** CSRF tokens (`includes/csrf.php`), password hashing (`password_hash`/`password_verify`), session-based access control (`includes/auth.php`)

## Project Structure
```
/auth/
  login.php          # Unified login for all roles/services
  logout.php         # Session teardown
/dashboard/           # Voting-service views
  admin.php
  teacher.php
  student.php
/dashboard2/           # Online-service views (same role split)
  admin.php
  teacher.php
  student.php
/includes/
  db.php             # PDO connection
  auth.php           # require_admin(), require_teacher(), require_student() guards
  csrf.php           # csrf_token(), csrf_check()
  config.php
```
*(Note: `/dashboard` vs `/dashboard2` paths are inferred from `login.php`'s redirect logic — adjust to match your actual folder layout.)*

## Setup
1. Create a MySQL database and import your schema (`users`, `positions`, `candidates`, `votes`, `teacher_student`, `assignments`, `score_keys`, `notifications`).
2. Configure database credentials in `includes/db.php`.
3. Ensure the web server has write access to a `logs/` directory under the teacher dashboard path (used for error logging).
4. Seed at least one verified admin user (`is_verified = 1`) directly in the database to bootstrap access.
5. Serve the project root with PHP (e.g., `php -S localhost:8000`) and navigate to `/auth/login.php`.

## Roles & Access Control
| Role    | Voting Service                          | Online Service                                  |
|---------|------------------------------------------|--------------------------------------------------|
| Admin   | Manage positions/candidates *(assumed)*  | Manage users, assign students to teachers       |
| Teacher | —                                         | Assign pacing, manage score keys, message students |
| Student | View candidates and vote                 | View pacing/assignments *(assumed)*             |

## Security Notes
- All state-changing POST requests are protected by CSRF tokens.
- Passwords require at least one uppercase character on creation/reset (enforced in `admin.php`).
- Role and verification status are re-checked server-side on every login and protected page load via `includes/auth.php`.