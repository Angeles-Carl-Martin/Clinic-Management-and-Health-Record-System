-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 08, 2026 at 05:41 PM
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
-- Database: `clinic_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `display_id` varchar(20) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Completed','Cancelled') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status1` varchar(20) DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `display_id`, `patient_id`, `appointment_date`, `appointment_time`, `reason`, `status`, `created_at`, `status1`) VALUES
(1, 'APT-0001', 1, '2026-05-09', '08:00:00', 'COUGH', 'Approved', '2026-05-08 11:34:12', '1'),
(2, 'APT-0002', 1, '2026-05-08', '08:00:00', 'UBO', 'Cancelled', '2026-05-08 11:41:22', '1'),
(3, 'APT-0003', 1, '2026-05-08', '09:00:00', 'UBO', 'Pending', '2026-05-08 11:43:00', '1');

--
-- Triggers `appointments`
--
DELIMITER $$
CREATE TRIGGER `trg_appointments_display_id` BEFORE INSERT ON `appointments` FOR EACH ROW BEGIN
  SET NEW.display_id = CONCAT('APT-', LPAD(
    (SELECT AUTO_INCREMENT FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'),
    4, '0'
  ));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `consultations`
--

CREATE TABLE `consultations` (
  `consultation_id` int(11) NOT NULL,
  `display_id` varchar(20) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `nurse_id` int(11) DEFAULT NULL,
  `visit_date` date NOT NULL,
  `symptoms` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `treatment` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `consultations`
--

INSERT INTO `consultations` (`consultation_id`, `display_id`, `patient_id`, `nurse_id`, `visit_date`, `symptoms`, `diagnosis`, `treatment`, `notes`, `created_at`, `status`) VALUES
(1, 'CON-0001', 1, 1, '2026-05-08', 'NAHIHILO nautot', 'FEVER', 'Medications: Tempra (1 pcs)', '', '2026-05-08 11:32:20', 0);

--
-- Triggers `consultations`
--
DELIMITER $$
CREATE TRIGGER `trg_consultations_display_id` BEFORE INSERT ON `consultations` FOR EACH ROW BEGIN
  SET NEW.display_id = CONCAT('CON-', LPAD(
    (SELECT AUTO_INCREMENT FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultations'),
    4, '0'
  ));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `medicines`
--

CREATE TABLE `medicines` (
  `medicine_id` int(11) NOT NULL,
  `display_id` varchar(20) DEFAULT NULL,
  `medicine_name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `date_added` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medicines`
--

INSERT INTO `medicines` (`medicine_id`, `display_id`, `medicine_name`, `category`, `quantity`, `unit`, `expiration_date`, `date_added`, `status`) VALUES
(1, 'MED-0001', 'Tempra', 'paracetamol', 5, 'tablet', '2026-05-09', '2026-05-08 11:19:25', '1');

--
-- Triggers `medicines`
--
DELIMITER $$
CREATE TRIGGER `trg_medicines_display_id` BEFORE INSERT ON `medicines` FOR EACH ROW BEGIN
  SET NEW.display_id = CONCAT('MED-', LPAD(
    (SELECT AUTO_INCREMENT FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medicines'),
    4, '0'
  ));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `medicine_dispense`
--

CREATE TABLE `medicine_dispense` (
  `dispense_id` int(11) NOT NULL,
  `display_id` varchar(20) DEFAULT NULL,
  `consultation_id` int(11) DEFAULT NULL,
  `medicine_id` int(11) DEFAULT NULL,
  `quantity_given` int(11) NOT NULL,
  `dispense_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medicine_dispense`
--

INSERT INTO `medicine_dispense` (`dispense_id`, `display_id`, `consultation_id`, `medicine_id`, `quantity_given`, `dispense_date`) VALUES
(1, 'DIS-0001', 1, 1, 1, '2026-05-08 11:32:20');

--
-- Triggers `medicine_dispense`
--
DELIMITER $$
CREATE TRIGGER `trg_dispense_display_id` BEFORE INSERT ON `medicine_dispense` FOR EACH ROW BEGIN
  SET NEW.display_id = CONCAT('DIS-', LPAD(
    (SELECT AUTO_INCREMENT FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medicine_dispense'),
    4, '0'
  ));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL,
  `display_id` varchar(20) DEFAULT NULL,
  `id_number` varchar(50) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `category` enum('Student','Faculty','Staff','Visitor') DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `recovery_code` text DEFAULT NULL,
  `sec_question1` varchar(255) DEFAULT NULL,
  `sec_answer1` varchar(255) DEFAULT NULL,
  `sec_question2` varchar(255) DEFAULT NULL,
  `sec_answer2` varchar(255) DEFAULT NULL,
  `last_code_change` datetime DEFAULT NULL,
  `status` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`patient_id`, `display_id`, `id_number`, `full_name`, `gender`, `birthdate`, `category`, `contact_number`, `address`, `created_at`, `username`, `password`, `recovery_code`, `sec_question1`, `sec_answer1`, `sec_question2`, `sec_answer2`, `last_code_change`, `status`) VALUES
(1, 'PAT-0001', '05-2026-01', 'Ar-J C. Rentoria', 'Male', '2005-07-14', 'Visitor', '09358016901', 'Blk 3 Lot 58 Westwood 4 Egret Street Lancaster City Zone 2', '2026-05-08 11:31:07', 'TESTING', '$2y$10$M84Vkpd44Yl/8s7O00xV9uBGaS4uS4JYTwVPa79J8qLhqMLzVwpAy', '[\"F5333393\",\"F33A2CE2\"]', NULL, NULL, NULL, NULL, '2026-05-08 13:41:43', 0),
(2, 'PAT-0002', '23-4960-01', 'Ar-J Rentoria', 'Male', '2000-07-14', 'Faculty', '09358016901', 'Blk 3 Lot 58 Westwood 4 Egret Street Lancaster City Zone 2', '2026-05-08 11:48:40', 'rentoria', '$2y$10$BbUHkvY0K4uOc3En0Tg7WuTMG7ymwC2oNKLkxjDKcpLfrN3tMxnIi', NULL, 'What is your mother\'s maiden name?', '$2y$10$YPlGbOV8rNBtRjN3LsGSQONCI9r6r1GLHa8XizZ0HMOi5L4kh.dSa', 'What city were you born in?', '$2y$10$jse7Q.VacsXa7h22QlAw2uVI1ODzeWltd/W2MiBAqWJW9aCeauA2a', NULL, 0);

--
-- Triggers `patients`
--
DELIMITER $$
CREATE TRIGGER `trg_patients_display_id` BEFORE INSERT ON `patients` FOR EACH ROW BEGIN
  SET NEW.display_id = CONCAT('PAT-', LPAD(
    (SELECT AUTO_INCREMENT FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'),
    4, '0'
  ));
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `staff_id` int(11) NOT NULL,
  `display_id` varchar(20) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','nurse') DEFAULT 'nurse',
  `status` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`staff_id`, `display_id`, `username`, `password`, `full_name`, `role`, `status`, `created_at`) VALUES
(1, 'STF-0001', 'admin', '$2y$10$Na1fFai0lyUZLbuGNJQKo.HokqTbDffMVPvFlQ4ZtL8z.GADjw/oS', 'System Administrator', 'admin', 1, '2026-05-08 11:11:40'),
(2, 'STF-0002', 'arj', '$2y$10$jRYq1Mi/0/X9PSzjN.dDYusxWsglHd64GjqJmhnMSUb.XlfhDKuJi', 'Ar-J C. Rentoria', 'nurse', 1, '2026-05-08 11:12:38');

--
-- Triggers `staff`
--
DELIMITER $$
CREATE TRIGGER `trg_staff_display_id` BEFORE INSERT ON `staff` FOR EACH ROW BEGIN
  SET NEW.display_id = CONCAT('STF-', LPAD(
    (SELECT AUTO_INCREMENT FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff'),
    4, '0'
  ));
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `consultations`
--
ALTER TABLE `consultations`
  ADD PRIMARY KEY (`consultation_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `nurse_id` (`nurse_id`);

--
-- Indexes for table `medicines`
--
ALTER TABLE `medicines`
  ADD PRIMARY KEY (`medicine_id`);

--
-- Indexes for table `medicine_dispense`
--
ALTER TABLE `medicine_dispense`
  ADD PRIMARY KEY (`dispense_id`),
  ADD KEY `consultation_id` (`consultation_id`),
  ADD KEY `medicine_id` (`medicine_id`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `id_number` (`id_number`),
  ADD UNIQUE KEY `id_number_2` (`id_number`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`staff_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `consultations`
--
ALTER TABLE `consultations`
  MODIFY `consultation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `medicines`
--
ALTER TABLE `medicines`
  MODIFY `medicine_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `medicine_dispense`
--
ALTER TABLE `medicine_dispense`
  MODIFY `dispense_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `staff`
--
ALTER TABLE `staff`
  MODIFY `staff_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `consultations`
--
ALTER TABLE `consultations`
  ADD CONSTRAINT `consultations_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `consultations_ibfk_2` FOREIGN KEY (`nurse_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `medicine_dispense`
--
ALTER TABLE `medicine_dispense`
  ADD CONSTRAINT `medicine_dispense_ibfk_1` FOREIGN KEY (`consultation_id`) REFERENCES `consultations` (`consultation_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `medicine_dispense_ibfk_2` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`medicine_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
