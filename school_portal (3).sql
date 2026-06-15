-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 22, 2025 at 07:58 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `school_portal`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `teacher_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `title`, `description`, `due_date`, `teacher_id`, `created_at`) VALUES
(1, 'Coding ', 'Create a website using this description\r\n🧠 SmartStudyPro Sell Digital & Physical Products with Bookings, Mobile Money Payments, AI Assistance, and a Visual Editor — Built with PHP, JavaScript & CSS SmartStudyPro is a smart, mobile-first commerce platform designed to sell both digital and physical products, manage custom service bookings, and deliver a personalized, AI-assisted customer experience. Built entirely with PHP, JavaScript, and CSS, it offers full admin control over the storefront through a visual, no-code editor. Whether you\'re a solo entrepreneur or a growing brand, SmartStudyPro gives you powerful tools — without the complexity. 🛍️ Product Sales – Digital & Physical Sell digital downloads (eBooks, templates, PDFs) Sell physical items (books, clothing, devices, etc.) Track digital access and physical delivery statuses Product listings include images, prices, categories, and descriptions Instant access to digital files after payment confirmation 🛠️ Visual Admin Panel — No Coding Needed SmartStudyPro comes with a built-in drag-and-drop admin panel that functions like a website builder, allowing you to control everything: ✏️ Edit the homepage layout, banners, color scheme, and section order 📦 Add, update, or delete products 📅 Manage bookings and user appointments 🖼️ Upload new slider images or promotional content 🧾 View orders, booking logs, and download summaries 🔒 Admin cannot access or see user passwords or sensitive authentication data. The system uses secure, encrypted handling of all logins. 💳 MTN & Airtel Mobile Money Payments Checkout supports MTN Mobile Money and Airtel Money Customers complete purchases using only a mobile number No cards or banking apps needed Instant verification and order unlocking Tailored for mobile-first markets like Uganda, Ghana, Nigeria, and more 📅 Custom Booking System (No Third-Party Services) Users can book services or sessions directly from your platform Choose time slots, enter session details, and receive confirmation Admin controls available times, views incoming bookings, and confirms status Bookings are stored in the user dashboard 🤖 AI Assistant (Text + Voice) Built-in GPT-powered assistant to help users with: Product search Download issues Booking guidance General support Optional voice input support on modern browsers Customizable tone and knowledge base 🖼️ Responsive Image Slider Showcase featured products, new arrivals, sales, or service offerings Easily update slider content from the admin panel Fully mobile-responsive for a polished front page experience 🌍 Multilingual + Mobile App Support Interface available in English, French, and Spanish (more languages can be added) Fully responsive design for phones, tablets, and desktops PWA (Progressive Web App) enabled — install SmartStudyPro like a native app Offline browsing of cached pages and assets 🔐 Secure Two-Role System Role Capabilities User Browse, purchase, download, book sessions, manage profile Admin Full control: edit layout, manage products, handle orders & bookings Simple, secure system — no multi-vendor complexity Admin panel built to empower, not overwhelm User credentials and authentication are encrypted and inaccessible to admin 🧰 Powered by PHP, JavaScript, and CSS SmartStudyPro is built using: PHP – Core backend logic, session management, payment integration JavaScript – Frontend interactivity, sliders, dynamic UI, voice input CSS – Responsive layout, color themes, and styling customization ✔ No proprietary frameworks or SaaS lock-ins ✔ Fully customizable and self-hosted ✔ Compatible with XAMPP, LAMP, or shared hosting environments 💼 SmartStudyPro Is Perfect For: Use Case Why It Works Digital Product Stores Sell eBooks, templates, guides, and downloadable content Physical Product Shops Manage real products and offer shipping or pickup Service-Based Brands Accept bookings for tutoring, coaching, or appointments Mobile-First Markets Checkout with MTN or Airtel MoMo; optimized for mobile use Solo Brands & SMEs All-in-one tool with full admin control, no coding needed 🎯 Feature Summary ✔ Sell digital and physical products ✔ Accept MTN & Airtel mobile payments ✔ Drag-and-drop admin panel (site editor) ✔ Built-in booking system ✔ AI assistant with voice support ✔ Multilingual UI ✔ Progressive Web App (PWA) support ✔ Role-based access (Admin & User only) ✔ Built entirely with PHP, JavaScript & CSS ✔ No vendor system, no user password access for admin SmartStudyPro gives you the control of a full e-commerce site, the power of AI, and the simplicity of a website builder — all powered by open technologies you can trust.', '2025-09-13', 8, '2025-09-03 16:58:53');

-- --------------------------------------------------------

--
-- Table structure for table `candidates`
--

CREATE TABLE `candidates` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `candidates`
--

INSERT INTO `candidates` (`id`, `name`, `description`, `created_at`, `image`) VALUES
(4, 'Enoch M', 'President', '2025-09-22 17:57:18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submissions`
--

CREATE TABLE `submissions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `grade` varchar(5) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `submissions`
--

INSERT INTO `submissions` (`id`, `assignment_id`, `student_id`, `submitted_at`, `grade`) VALUES
(1, 1, 9, '2025-09-03 17:00:02', '90');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','teacher','student') NOT NULL,
  `is_verified` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `is_verified`, `created_at`) VALUES
(4, 'Admin One', 'admin1@school.local', '$2y$10$HPovOge3ZK9rUt8dVttGtOMlqh/ZZTQQ9z2hA0KHDoBc6pPPs3BT.', 'admin', 1, '2025-09-02 16:31:49'),
(5, 'Teacher One', 'teacher1@school.local', '$2y$10$L.8ukZk5L3kLnx1X0OhRyut5so7lDLaI0G79ayvt8h2x8PQZCvFhK', 'teacher', 1, '2025-09-02 16:31:49'),
(6, 'Student One', 'student1@school.local', '$2y$10$QU6uLy7M2PwZYpkofqvsEuyBt9yye4yyxmHCDVwFtyr/imxzXYqZC', 'student', 1, '2025-09-02 16:31:49'),
(7, 'Super Admin', 'superadmin@school.local', '$2y$10$YLvrgCLed5u6h0XGhyD0b./ImmiSiAhXdWZ65yuAb.BWMuojriawm', 'admin', 1, '2025-09-02 16:36:09'),
(8, 'Redeemed Winner', 'r7194714@gmail.com', '$2y$10$YmfyFSiCGsHQ8TJPcFs4OOFpBVuEiRpAUK34tsLq4r.S2thZX8d4q', 'teacher', 1, '2025-09-03 16:50:48'),
(9, 'Enoch M', 'e.elijah2023@gmail.com', '$2y$10$dHnqs1QHj7yc3UwqjaPpXeYWK7SREYkKCrQSooM0jJWMcG1Troo56', 'student', 1, '2025-09-03 16:50:57');

-- --------------------------------------------------------

--
-- Table structure for table `votes`
--

CREATE TABLE `votes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `candidate_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `votes`
--

INSERT INTO `votes` (`id`, `user_id`, `candidate_id`, `created_at`) VALUES
(1, 9, 1, '2025-09-22 17:23:48');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `candidates`
--
ALTER TABLE `candidates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `submissions`
--
ALTER TABLE `submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `votes`
--
ALTER TABLE `votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `candidates`
--
ALTER TABLE `candidates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `submissions`
--
ALTER TABLE `submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `votes`
--
ALTER TABLE `votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `submissions`
--
ALTER TABLE `submissions`
  ADD CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
