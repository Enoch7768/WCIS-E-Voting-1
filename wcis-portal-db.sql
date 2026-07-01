-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 01, 2026 at 11:21 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+03:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wcis-portal-db`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `pace` varchar(255) NOT NULL,
  `score_key_id` int(10) UNSIGNED NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('Assigned','In Progress','Needs Correction','Completed') NOT NULL DEFAULT 'Assigned',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expected_pages` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `student_id`, `pace`, `score_key_id`, `due_date`, `status`, `created_at`, `expected_pages`) VALUES
(1, 3, 'Math', 1, '1111-11-11', 'Completed', '2026-06-17 17:59:48', 0),
(2, 3, 'Test', 1, '2026-06-25', 'In Progress', '2026-06-25 18:15:54', 0),
(3, 3, 'Mathm', 4, '2026-07-12', 'In Progress', '2026-06-28 13:16:06', 0);

-- --------------------------------------------------------

--
-- Table structure for table `candidates`
--

CREATE TABLE `candidates` (
  `id` int(10) UNSIGNED NOT NULL,
  `position_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `candidates`
--

INSERT INTO `candidates` (`id`, `position_id`, `name`, `image`) VALUES
(1, 1, 'Redeemed Winner', 'uploads/candidates/6a44d41493a84.png'),
(2, 1, 'Redeemed Winner7', 'uploads/candidates/6a44d418d09d1.png');

-- --------------------------------------------------------

--
-- Table structure for table `help_requests`
--

CREATE TABLE `help_requests` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `question_number` varchar(50) NOT NULL,
  `extracted_answer` varchar(255) DEFAULT NULL,
  `correct_answer` varchar(255) DEFAULT NULL,
  `status` enum('pending','answered') DEFAULT 'pending',
  `gemini_explanation` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `help_requests`
--

INSERT INTO `help_requests` (`id`, `student_id`, `assignment_id`, `question_number`, `extracted_answer`, `correct_answer`, `status`, `gemini_explanation`, `created_at`) VALUES
(1, 3, 3, '1', '42', 'B', 'answered', 'To provide a specific explanation, I would need to know the subject matter of Question 1. However, here is a template you can use to address this discrepancy:\n\n### The Concept\n[Insert the name of the concept being tested here, e.g., \"The Law of Supply,\" \"Pythagorean Theorem,\" or \"Verb Conjugation\"]. \n\nThis concept requires understanding that [briefly explain the underlying rule or logic required to solve the problem].\n\n### Why the answer is incorrect\nThe student answered **\'42\'**, which appears to be a [common mistake type, e.g., calculation error, logical fallacy, or misinterpretation of the data]. \n\n*   **The Error:** The student likely [describe the specific mistake, e.g., \"multiplied instead of divided\" or \"applied the wrong formula\"].\n*   **The Correct Approach:** To arrive at **\'B\'**, the student should have [state the correct step-by-step process].\n\n***\n\n**If you provide the question text and the context of the subject (e.g., Algebra, History, Science), I can draft a precise explanation for you!**', '2026-06-17 09:15:58'),
(2, 3, 3, '10', '10', 'C', 'answered', 'To explain why the answer \'10\' is incorrect for question 10, we must look at the nature of this test.\n\n### The Concept\nBased on the structure of the provided image, this appears to be a **pattern recognition or logic test** rather than a traditional arithmetic examination. \n\nIn tests like these, each item (such as \"10. 10\") is typically part of a sequence where the number on the left (the question number) relates to the value on the right (the answer) through a specific rule or logic set. \n\n### Why the student\'s answer is incorrect\nIf the correct answer for question 10 is **\'C\'**, it suggests the following:\n\n1.  **Multiple Choice Format:** The student likely treated the \"10\" on the right as a numerical answer (a mathematical result). However, the exam is clearly designed as a **multiple-choice test** where the answers correspond to letters (A, B, C, D). \n2.  **Misunderstanding the Task:** By writing \"10,\" the student provided a number instead of the required letter code. Even if the logic behind the question results in a numerical value, the test requires that value to be mapped to a specific letter option (e.g., if the math equals 10, and option C represents 10, the correct response is \'C\').\n3.  **Formatting Error:** In many automated or standardized tests, the \"answer\" field requires a selection from the provided options (A, B, C, D), not a restatement of the question number or the result of an equation. \n\n**Summary for the student:**\nYou provided the numerical value rather than the option letter. In this exam format, you must identify which letter (A, B, C, or D) corresponds to the correct result of the problem. Please ensure you are selecting from the provided multiple-choice options for all future questions.', '2026-06-17 09:23:34'),
(3, 3, 3, '11', '009', '83', 'answered', 'Based on the image provided, it is not possible to determine the mathematical problem for question 11 because the actual questions are missing—the document only lists the candidate\'s answers.\n\nHowever, here is an explanation of why **\'009\'** is fundamentally incorrect compared to **\'83\'**:\n\n### The Concept of Place Value\nIn the standard base-10 number system, the position of each digit determines its value.\n*   **The number 83:** This represents \"eight tens\" and \"three ones\" ($80 + 3$).\n*   **The number 009:** This is mathematically equivalent to simply **9**. Adding zeros to the left of a whole number (leading zeros) does not change its value.\n\n### Why the answer is incorrect:\n1.  **Mathematical Inequality:** 9 does not equal 83. They represent two different quantities on the number line. \n2.  **Formatting:** Unless a specific format (like a 3-digit code or a data entry field) is required, adding extra zeros is considered incorrect notation. In a standard math context, you should always simplify the answer to its most concise form (e.g., writing \"9\" instead of \"009\").\n\n**Recommendation:** Check the original problem prompt to see if there is a typo in the question or if the calculation resulted in 9, when it should have been 83. If you can provide the text of question 11, I can help you work through the actual math to see where the error occurred!', '2026-06-17 09:23:37'),
(4, 3, 3, '9', '1', 'B', 'answered', 'To provide an explanation for question 9, **I would need to see the actual question text and the corresponding multiple-choice options.**\n\nThe image provided only shows a list of numbers representing the answers the student gave for a 25-question examination. It does not include the questions themselves or the set of choices (A, B, C, D) for those questions.\n\n**However, here is how you can help the student once you have the question:**\n\n1.  **Identify the Concept:** Determine what topic the question covers (e.g., algebra, arithmetic, geometry).\n2.  **Verify the Correct Answer:** Look at the test key to understand what specific step led to \'B\' being the correct choice.\n3.  **Analyze the Error:** Compare the student\'s answer (\'1\') to the correct answer (\'B\'). Ask the student how they arrived at \'1\'. Often, a student answers \'1\' because:\n    *   They calculated the result of a simplification incorrectly (e.g., thinking $x/x = 0$ instead of $1$).\n    *   They misread a variable or operator.\n    *   They guessed based on a pattern they *thought* they saw.\n\n**If you can provide the text of question 9 and the multiple-choice options, I would be happy to explain the concept and show exactly why \'B\' is the correct answer!**', '2026-06-17 09:23:54');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `receiver_id` int(10) UNSIGNED NOT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_mime` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `sender_id`, `receiver_id`, `body`, `created_at`, `attachment_path`, `attachment_name`, `attachment_mime`) VALUES
(1, 2, 3, 'Good Afternoon', '2026-06-17 08:33:05', NULL, NULL, NULL),
(2, 3, 2, 'Testing 123!!@@##', '2026-06-17 08:39:40', NULL, NULL, NULL),
(3, 3, 2, 'hi', '2026-06-17 09:00:01', NULL, NULL, NULL),
(4, 3, 2, 'IVE Failed I need help', '2026-06-17 09:07:19', NULL, NULL, NULL),
(5, 2, 3, 'ok', '2026-06-17 09:08:13', NULL, NULL, NULL),
(6, 2, 3, 'upload page', '2026-06-17 09:08:26', NULL, NULL, NULL),
(7, 3, 2, 'Testing', '2026-06-17 09:16:21', NULL, NULL, NULL),
(8, 3, 2, 'IVE Failed I need help', '2026-06-17 09:25:18', NULL, NULL, NULL),
(9, 3, 2, '', '2026-06-26 16:17:47', 'uploads/chat/chat_6a3ea62bd3f699.72969241.jpeg', 'WhatsApp Image 2026-06-24 at 8.41.28 PM.jpeg', 'image/jpeg'),
(10, 2, 3, '', '2026-06-26 16:21:56', 'uploads/chat/chat_6a3ea724569b75.41069563.webm', 'voice_message.webm', 'audio/webm'),
(11, 3, 2, '', '2026-06-26 16:33:09', 'uploads/chat/chat_6a3ea9c5566758.07609291.sql', 'wcis-portal-db.sql', 'application/octet-stream'),
(12, 2, 3, 'hi', '2026-06-27 15:38:28', NULL, NULL, NULL),
(13, 2, 3, 'testing', '2026-06-27 15:38:37', NULL, NULL, NULL),
(14, 2, 3, '', '2026-06-27 15:38:54', 'uploads/chat/chat_6a3fee8ef2d570.97569459.png', 'Screenshot (5).png', 'image/png'),
(15, 2, 3, 'testing', '2026-06-27 15:41:32', NULL, NULL, NULL),
(16, 2, 3, '', '2026-06-27 15:42:48', 'uploads/chat/chat_6a3fef78ce39a8.75157062.mp3', '01_He_Arose_SpotiDost.mp3', 'audio/mpeg'),
(17, 2, 3, '', '2026-06-27 16:03:58', 'uploads/chat/chat_6a3ff46e2a5c14.06686647.mp3', '01_He_Arose_SpotiDost.mp3', 'audio/mpeg'),
(18, 2, 3, '', '2026-06-27 16:05:43', 'uploads/chat/chat_6a3ff4d73ce416.42720085.mp3', '01_He_Arose_SpotiDost.mp3', 'audio/mpeg'),
(19, 2, 3, 'test', '2026-06-28 12:35:39', NULL, NULL, NULL),
(20, 2, 3, 'test', '2026-06-28 12:35:55', NULL, NULL, NULL),
(21, 2, 3, 'test', '2026-06-28 12:36:05', NULL, NULL, NULL),
(22, 2, 3, 'Testing 123!!@@##', '2026-06-28 12:36:25', NULL, NULL, NULL),
(23, 2, 3, 'Testing 123!!@@##', '2026-06-28 12:42:30', NULL, NULL, NULL),
(24, 2, 3, 'tez', '2026-06-28 12:42:36', NULL, NULL, NULL),
(25, 2, 3, '', '2026-06-28 12:43:05', 'uploads/chat/chat_6a4116d9c84b11.43948903.webm', 'voice_message.webm', 'audio/webm'),
(26, 2, 3, 'Testing 123!!@@##', '2026-06-28 12:45:32', NULL, NULL, NULL),
(27, 2, 3, 'Testing 123!!@@##', '2026-06-28 12:46:26', NULL, NULL, NULL),
(28, 2, 3, 'Testing 123!!@@##', '2026-06-28 12:48:17', NULL, NULL, NULL),
(29, 2, 3, 'Testing 123!!@@##', '2026-06-28 12:48:23', NULL, NULL, NULL),
(30, 2, 3, 'Testing 123!!@@##', '2026-06-28 12:49:44', NULL, NULL, NULL),
(31, 2, 3, 'IVE Failed I need help', '2026-06-28 12:49:52', NULL, NULL, NULL),
(32, 3, 2, '', '2026-06-28 13:24:29', 'uploads/chat/chat_6a41208dda64f1.39344498.webm', 'voice_message.webm', 'audio/webm'),
(33, 3, 2, '', '2026-06-28 13:24:45', 'uploads/chat/chat_6a41209d02c6e4.84395873.php', 'student.php', 'application/octet-stream'),
(34, 3, 2, 'bb', '2026-06-30 07:16:50', NULL, NULL, NULL),
(35, 4, 3, 'Hi', '2026-06-30 07:42:18', NULL, NULL, NULL),
(36, 4, 3, 'Im your second teacher', '2026-06-30 07:42:28', NULL, NULL, NULL),
(37, 3, 2, '', '2026-06-30 12:18:17', 'uploads/chat/chat_6a43b40987f296.23057855.webm', 'voice_message.webm', 'audio/webm'),
(38, 3, 2, '', '2026-06-30 12:18:39', 'uploads/chat/chat_6a43b41fc72ff9.55433434.png', 'Screenshot (2).png', 'image/png'),
(39, 2, 3, 'Multi Testing', '2026-06-30 18:47:23', NULL, NULL, NULL),
(40, 3, 4, 'Testing', '2026-06-30 18:47:51', NULL, NULL, NULL),
(41, 3, 2, '', '2026-06-30 19:01:33', 'uploads/chat/chat_6a44128d6fb4a3.60974669.webm', 'voice_message.webm', 'audio/webm'),
(42, 3, 2, '', '2026-06-30 19:01:46', 'uploads/chat/chat_6a44129acfedc7.04783172.webm', 'voice_message.webm', 'audio/webm'),
(43, 2, 3, '', '2026-06-30 19:15:27', 'uploads/chat/chat_6a4415cf4569b4.10244979.webm', 'voice_message.webm', 'audio/webm');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `type` enum('message','submission','status_change','correction') NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `sender_id`, `type`, `message`, `link`, `is_read`, `created_at`) VALUES
(1, 2, 3, 'message', 'New message from student', NULL, 1, '2026-06-17 09:00:01'),
(2, 2, 3, 'submission', 'Student submitted assignment #2 (1 pages)', NULL, 1, '2026-06-17 09:02:17'),
(3, 3, 2, 'status_change', 'New PACE assignment: Math', NULL, 1, '2026-06-17 09:06:10'),
(4, 2, 3, 'submission', 'Student submitted assignment #3 (1 pages)', NULL, 1, '2026-06-17 09:06:41'),
(5, 2, 3, 'message', 'New message from student', NULL, 1, '2026-06-17 09:07:19'),
(6, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-17 09:08:13'),
(7, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-17 09:08:26'),
(8, 2, 3, 'message', 'New message from student', NULL, 1, '2026-06-17 09:16:21'),
(9, 2, 3, 'submission', 'Student submitted assignment #3 (1 pages)', NULL, 1, '2026-06-17 09:23:22'),
(10, 2, 3, 'submission', 'Student submitted assignment #3 (1 pages)', NULL, 1, '2026-06-17 09:25:00'),
(11, 2, 3, 'message', 'New message from student', NULL, 1, '2026-06-17 09:25:18'),
(12, 3, 2, 'status_change', 'New PACE assignment: Math', NULL, 1, '2026-06-17 17:59:48'),
(13, 2, 3, 'submission', 'Student submitted assignment #1 (1 pages)', NULL, 1, '2026-06-17 18:00:26'),
(14, 2, 3, 'submission', 'Student submitted assignment #1 (1 pages)', NULL, 1, '2026-06-17 18:01:39'),
(15, 3, 2, 'status_change', 'Assignment status updated to: Needs Correction', NULL, 1, '2026-06-17 18:02:34'),
(16, 3, 2, 'status_change', 'Assignment status updated to: Completed', NULL, 1, '2026-06-17 18:02:37'),
(17, 3, 2, 'status_change', 'New PACE assignment: Test', NULL, 1, '2026-06-25 18:15:54'),
(18, 2, 3, 'message', 'New message from student with attachment', NULL, 1, '2026-06-26 16:17:47'),
(19, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-26 16:21:56'),
(20, 3, 2, 'status_change', 'Assignment status updated to: Completed', NULL, 1, '2026-06-26 16:22:34'),
(21, 2, 3, 'message', 'New message from student with attachment', NULL, 1, '2026-06-26 16:33:09'),
(22, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-27 15:38:28'),
(23, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-27 15:38:37'),
(24, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-27 15:38:55'),
(25, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-27 15:41:32'),
(26, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-27 15:42:48'),
(27, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-27 16:03:58'),
(28, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-27 16:05:43'),
(29, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:35:39'),
(30, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:35:55'),
(31, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:36:05'),
(32, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:36:25'),
(33, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:42:30'),
(34, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:42:36'),
(35, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:43:05'),
(36, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:45:32'),
(37, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:46:26'),
(38, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:48:17'),
(39, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:48:23'),
(40, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:49:44'),
(41, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-28 12:49:52'),
(42, 3, 2, 'status_change', 'New PACE assignment: Math', NULL, 1, '2026-06-28 13:16:06'),
(43, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:00'),
(44, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:01'),
(45, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:02'),
(46, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:02'),
(47, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:03'),
(48, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:03'),
(49, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:03'),
(50, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:03'),
(51, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:08'),
(52, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:09'),
(53, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:14'),
(54, 3, 2, 'status_change', 'Assignment updated: Math', NULL, 1, '2026-06-28 13:17:15'),
(55, 3, 2, 'status_change', 'Assignment updated: Mathm', NULL, 1, '2026-06-28 13:17:18'),
(56, 3, 2, 'status_change', 'Assignment updated: Mathm', NULL, 1, '2026-06-28 13:17:18'),
(57, 3, 2, 'status_change', 'Assignment updated: Mathm', NULL, 1, '2026-06-28 13:19:27'),
(58, 3, 2, 'status_change', 'Assignment updated: Test', NULL, 1, '2026-06-28 13:19:33'),
(59, 3, 2, 'status_change', 'Assignment updated: Mathm', NULL, 1, '2026-06-28 13:20:16'),
(60, 3, 2, 'status_change', 'Assignment updated: Mathm', NULL, 1, '2026-06-28 13:20:16'),
(61, 2, 3, 'message', 'New message from student with attachment', NULL, 1, '2026-06-28 13:24:29'),
(62, 2, 3, 'message', 'New message from student with attachment', NULL, 1, '2026-06-28 13:24:45'),
(63, 3, 2, 'status_change', 'Assignment updated: Test', NULL, 1, '2026-06-28 13:25:24'),
(64, 3, 2, 'status_change', 'Assignment updated: Test', NULL, 1, '2026-06-28 13:25:25'),
(65, 3, 2, 'status_change', 'Assignment updated: Test', NULL, 1, '2026-06-28 13:25:25'),
(66, 3, 2, 'status_change', 'Assignment updated: Test', NULL, 1, '2026-06-28 13:25:25'),
(67, 3, 2, 'status_change', 'Assignment updated: Test', NULL, 1, '2026-06-28 13:25:25'),
(68, 2, 3, 'message', 'New message from student with text', NULL, 1, '2026-06-30 07:16:50'),
(69, 3, 4, 'message', 'New message from teacher', NULL, 1, '2026-06-30 07:42:18'),
(70, 3, 4, 'message', 'New message from teacher', NULL, 1, '2026-06-30 07:42:28'),
(71, 2, 3, 'message', 'New message from student with attachment', NULL, 1, '2026-06-30 12:18:17'),
(72, 2, 3, 'message', 'New message from student with attachment', NULL, 1, '2026-06-30 12:18:39'),
(73, 3, 2, 'message', 'New message from teacher', NULL, 1, '2026-06-30 18:47:23'),
(74, 4, 3, 'message', 'New message from student with text', NULL, 0, '2026-06-30 18:47:51'),
(75, 2, 3, 'message', 'Sent an attachment', NULL, 1, '2026-06-30 19:01:33'),
(76, 2, 3, 'message', 'Sent an attachment', NULL, 1, '2026-06-30 19:01:46'),
(77, 3, 2, 'message', 'Sent an attachment', NULL, 1, '2026-06-30 19:15:27');

-- --------------------------------------------------------

--
-- Table structure for table `ocr_results`
--

CREATE TABLE `ocr_results` (
  `id` int(10) UNSIGNED NOT NULL,
  `assignment_id` int(10) UNSIGNED NOT NULL,
  `question_number` varchar(50) NOT NULL,
  `extracted_answer` text NOT NULL,
  `confidence` decimal(4,3) NOT NULL DEFAULT 1.000,
  `status` enum('Pending Review','Approved','Rejected') NOT NULL DEFAULT 'Approved',
  `is_correct` tinyint(1) DEFAULT NULL,
  `points_earned` decimal(5,2) DEFAULT NULL,
  `page_number` int(11) DEFAULT 1,
  `image_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ocr_results`
--

INSERT INTO `ocr_results` (`id`, `assignment_id`, `question_number`, `extracted_answer`, `confidence`, `status`, `is_correct`, `points_earned`, `page_number`, `image_path`) VALUES
(126, 3, '1', '42', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(127, 3, '2', '37', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(128, 3, '3', '56', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(129, 3, '4', '7', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(130, 3, '5', '75', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(131, 3, '6', '6x', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(132, 3, '7', '39', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(133, 3, '8', '90', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(134, 3, '9', '1', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(135, 3, '10', '10', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(136, 3, '11', '009', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(137, 3, '12', '90', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(138, 3, '13', '89', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(139, 3, '14', '54', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(140, 3, '15', '66', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(141, 3, '16', '779', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(142, 3, '17', '878', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(143, 3, '18', 'nzx', 0.800, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(144, 3, '19', '098', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(145, 3, '20', '89.00', 0.950, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(146, 3, '21', '99', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(147, 3, '22', '89', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(148, 3, '23', '899', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(149, 3, '24', '899', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(150, 3, '24', '899', 0.900, 'Approved', 0, 0.00, 1, 'uploads/submissions/sub_6a329218bfcea0.81049901.png'),
(151, 1, '1', 'B', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(152, 1, '2', 'C', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(153, 1, '3', 'A', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(154, 1, '4', 'D', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(155, 1, '5', 'B', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(156, 1, '6', 'A', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(157, 1, '7', 'C', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(158, 1, '8', 'B', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(159, 1, '9', 'D', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(160, 1, '10', 'A', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(161, 1, '11', 'x = 5', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(162, 1, '12', 'y = -8', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(163, 1, '13', '(5, 3)', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(164, 1, '14', '4x^2 - 12x + 9', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(165, 1, '15', '(x-2)(x-3)', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(166, 1, '16', '30', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(167, 1, '17', '26', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(168, 1, '18', '14π', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(169, 1, '19', '15', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(170, 1, '20', '45π', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(171, 1, '21', 'x^5', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(172, 1, '22', 'x = 9', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(173, 1, '23', '6', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(174, 1, '24', '2', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(175, 1, '25', '2/5', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg'),
(176, 1, '25', '2/5', 1.000, 'Approved', 1, 1.00, 1, 'uploads/submissions/sub_6a32e0faebe035.11183055.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`id`, `name`) VALUES
(1, 'Test Tester');

-- --------------------------------------------------------

--
-- Table structure for table `score_keys`
--

CREATE TABLE `score_keys` (
  `id` int(10) UNSIGNED NOT NULL,
  `pace` varchar(255) NOT NULL,
  `file_path` text DEFAULT NULL,
  `version` varchar(50) NOT NULL DEFAULT 'Draft-1.0',
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `question_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `question_structure` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`question_structure`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expected_pages` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `score_keys`
--

INSERT INTO `score_keys` (`id`, `pace`, `file_path`, `version`, `is_published`, `question_count`, `question_structure`, `created_at`, `expected_pages`) VALUES
(1, 'Math', 'uploads/score_keys/sk_6a32e07c883d69.64577606.pdf', 'Prod-1.0', 1, 26, '{\"pace\":{\"subject\":\"Mathematics\",\"pace_number\":\"Comprehensive Mid-Term\",\"title\":\"Math Answer Key\"},\"version\":1,\"questions\":[{\"question_number\":\"1\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"B\",\"acceptable_answers\":[\"B\"],\"points\":4},{\"question_number\":\"2\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"C\",\"acceptable_answers\":[\"C\"],\"points\":4},{\"question_number\":\"3\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"A\",\"acceptable_answers\":[\"A\"],\"points\":4},{\"question_number\":\"4\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"D\",\"acceptable_answers\":[\"D\"],\"points\":4},{\"question_number\":\"5\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"B\",\"acceptable_answers\":[\"B\"],\"points\":4},{\"question_number\":\"6\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"A\",\"acceptable_answers\":[\"A\"],\"points\":4},{\"question_number\":\"7\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"C\",\"acceptable_answers\":[\"C\"],\"points\":4},{\"question_number\":\"8\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"B\",\"acceptable_answers\":[\"B\"],\"points\":4},{\"question_number\":\"9\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"D\",\"acceptable_answers\":[\"D\"],\"points\":4},{\"question_number\":\"10\",\"question_type\":\"multiple_choice\",\"correct_answer\":\"A\",\"acceptable_answers\":[\"A\"],\"points\":4},{\"question_number\":\"11\",\"question_type\":\"fill_blank\",\"correct_answer\":\"x = 5\",\"acceptable_answers\":[\"x = 5\"],\"points\":5},{\"question_number\":\"12\",\"question_type\":\"fill_blank\",\"correct_answer\":\"y = -8\",\"acceptable_answers\":[\"y = -8\"],\"points\":5},{\"question_number\":\"13\",\"question_type\":\"fill_blank\",\"correct_answer\":\"(5, 3)\",\"acceptable_answers\":[\"(5, 3)\"],\"points\":5},{\"question_number\":\"14\",\"question_type\":\"fill_blank\",\"correct_answer\":\"4x^2 - 12x + 9\",\"acceptable_answers\":[\"4x^2 - 12x + 9\"],\"points\":5},{\"question_number\":\"15\",\"question_type\":\"fill_blank\",\"correct_answer\":\"(x-2)(x-3)\",\"acceptable_answers\":[\"(x-2)(x-3)\"],\"points\":5},{\"question_number\":\"16\",\"question_type\":\"fill_blank\",\"correct_answer\":\"30\",\"acceptable_answers\":[\"30\"],\"points\":5},{\"question_number\":\"17\",\"question_type\":\"fill_blank\",\"correct_answer\":\"26\",\"acceptable_answers\":[\"26\"],\"points\":5},{\"question_number\":\"18\",\"question_type\":\"fill_blank\",\"correct_answer\":\"14\\u03c0\",\"acceptable_answers\":[\"14\\u03c0\"],\"points\":5},{\"question_number\":\"19\",\"question_type\":\"fill_blank\",\"correct_answer\":\"15\",\"acceptable_answers\":[\"15\"],\"points\":5},{\"question_number\":\"20\",\"question_type\":\"fill_blank\",\"correct_answer\":\"45\\u03c0\",\"acceptable_answers\":[\"45\\u03c0\"],\"points\":5},{\"question_number\":\"21\",\"question_type\":\"fill_blank\",\"correct_answer\":\"x^5\",\"acceptable_answers\":[\"x^5\"],\"points\":2},{\"question_number\":\"22\",\"question_type\":\"fill_blank\",\"correct_answer\":\"x = 9\",\"acceptable_answers\":[\"x = 9\"],\"points\":2},{\"question_number\":\"23\",\"question_type\":\"fill_blank\",\"correct_answer\":\"6\",\"acceptable_answers\":[\"6\"],\"points\":2},{\"question_number\":\"24\",\"question_type\":\"fill_blank\",\"correct_answer\":\"2\",\"acceptable_answers\":[\"2\"],\"points\":2},{\"question_number\":\"25\",\"question_type\":\"fill_blank\",\"correct_answer\":\"2\\/5\",\"acceptable_answers\":[\"2\\/5\"],\"points\":2},{\"question_number\":\"26\",\"question_type\":\"fill_blank\",\"correct_answer\":\"x = 5\",\"acceptable_answers\":[\"x = 5\"],\"points\":20}]}', '2026-06-17 17:59:31', 0),
(2, 'Basic', 'uploads/score_keys/sk_6a411a10c98318.87566474.pdf', 'Prod-1.0', 1, 0, '{}', '2026-06-28 12:56:48', 0),
(3, 'Test', 'uploads/score_keys/sk_6a411beed137a6.26702306.jpg', 'Prod-1.0', 1, 0, '{}', '2026-06-28 13:04:46', 0),
(4, 'Mathm', '[\"uploads\\/score_keys\\/sk_6a44c1bd3d14c5.48001340.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3d65e6.21119090.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3dcfa6.52867521.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3e13d2.00428051.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3e55b8.24723857.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3e9390.07531204.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3ecfa1.33387838.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3f1152.49413726.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3f5131.64431247.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3f9053.19957240.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd3fcfe9.34302465.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd400d75.46500211.jpg\",\"uploads\\/score_keys\\/sk_6a44c1bd404980.39222746.jpg\"]', 'Prod-1.0', 1, 0, '[]', '2026-06-28 13:15:40', 0);

-- --------------------------------------------------------

--
-- Table structure for table `teacher_student`
--

CREATE TABLE `teacher_student` (
  `teacher_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `teacher_student`
--

INSERT INTO `teacher_student` (`teacher_id`, `student_id`) VALUES
(2, 3),
(4, 3);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','teacher','student') NOT NULL DEFAULT 'student',
  `is_verified` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `is_verified`, `created_at`) VALUES
(1, 'Admin', 'admin@watotoschools.com', '$2y$10$02GjxtXOLkFNuQAhdF1umOMIoYR5.v3W0pJxan0U2x5PhAtmxKh5a', 'admin', 1, '2026-06-17 08:27:02'),
(2, 'Teacher One', 'teacher1@school.local', '$2y$10$orZvXL2YBxnhC45vDOVdN.HVPQEgIvRnyBnkXpqiZPXbhBTEId49.', 'teacher', 1, '2026-06-17 08:27:49'),
(3, 'Student One', 'student1@school.local', '$2y$10$GWHhJi/3x12UNEFwIfHtXuqCO3y0dg0TMdzcSIadvXHN2tuL0jCKK', 'student', 1, '2026-06-17 08:28:01'),
(4, 'Teacher Two', 'teacher2@watotoschools.com', '$2y$10$/vVdstvr4Dl3/zOYve2XmelOfwd1Eh9Tnz9HxyabKtUZ4gOYMZkMa', 'teacher', 1, '2026-06-30 07:41:27');

-- --------------------------------------------------------

--
-- Table structure for table `votes`
--

CREATE TABLE `votes` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `candidate_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `votes`
--

INSERT INTO `votes` (`id`, `user_id`, `candidate_id`, `created_at`) VALUES
(1, 3, 1, '2026-07-01 08:47:36');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `score_key_id` (`score_key_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `candidates`
--
ALTER TABLE `candidates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `position_id` (`position_id`);

--
-- Indexes for table `help_requests`
--
ALTER TABLE `help_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`,`assignment_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`,`is_read`);

--
-- Indexes for table `ocr_results`
--
ALTER TABLE `ocr_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assignment_question` (`assignment_id`,`question_number`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `score_keys`
--
ALTER TABLE `score_keys`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `teacher_student`
--
ALTER TABLE `teacher_student`
  ADD PRIMARY KEY (`teacher_id`,`student_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_role` (`role`);

--
-- Indexes for table `votes`
--
ALTER TABLE `votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_user_position_vote` (`user_id`,`candidate_id`),
  ADD KEY `candidate_id` (`candidate_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `candidates`
--
ALTER TABLE `candidates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `help_requests`
--
ALTER TABLE `help_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `ocr_results`
--
ALTER TABLE `ocr_results`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=177;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `score_keys`
--
ALTER TABLE `score_keys`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `votes`
--
ALTER TABLE `votes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`score_key_id`) REFERENCES `score_keys` (`id`);

--
-- Constraints for table `candidates`
--
ALTER TABLE `candidates`
  ADD CONSTRAINT `candidates_ibfk_1` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ocr_results`
--
ALTER TABLE `ocr_results`
  ADD CONSTRAINT `ocr_results_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_student`
--
ALTER TABLE `teacher_student`
  ADD CONSTRAINT `teacher_student_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `teacher_student_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `votes`
--
ALTER TABLE `votes`
  ADD CONSTRAINT `votes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `votes_ibfk_2` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
