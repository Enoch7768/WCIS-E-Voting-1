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
  login.php          
  logout.php        
/dashboard/          
  admin.php
  teacher.php
  student.php
/dashboard2/           
  admin.php
  teacher.php
  student.php
/includes/
  db.php            
  auth.php           
  csrf.php           
  config.php
```


## Roles & Access Control
| Role    | Voting Service                                          | Online Service                                    |
|---------|---------------------------------------------------------|---------------------------------------------------|
| Admin   | Manage positions/candidates and view results            | Manage users, assign students to teachers         |
| Teacher | View voting results                                     | Assign pacing, manage score keys, message students|
| Student | View candidates and vote                                | Chat with teachers and access score keys          |

## Security Notes
- All state-changing POST requests are protected by CSRF tokens.
- Passwords require at least one uppercase character on creation/reset (enforced in `admin.php`).
- Role and verification status are re-checked server-side on every login and protected page load via `includes/auth.php`.