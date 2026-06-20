-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jun 19, 2026 at 08:26 AM
-- Server version: 8.4.7
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `apaagps_db`
--

DELIMITER $$
--
-- Procedures
--
DROP PROCEDURE IF EXISTS `sp_recalc_cgpa`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_recalc_cgpa` (IN `p_student_id` VARCHAR(10))   BEGIN
    DECLARE v_total_cu DECIMAL(3,2);
    DECLARE v_total_qp DECIMAL(3,2);
    DECLARE v_cgpa     DECIMAL(3,2);
    DECLARE v_year_no  INT;

    -- Get the latest year_no for this student from results_tb
    SELECT IFNULL(MAX(year_no), 1)
    INTO   v_year_no
    FROM   results_tb
    WHERE  student_ID = p_student_id;

    -- Cumulative credit units across ALL semesters
    SELECT IFNULL(SUM(r.grade_point * m.credit_unit), 0),
           IFNULL(SUM(m.credit_unit), 0)
    INTO   v_total_qp, v_total_cu
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = p_student_id;

    IF v_total_cu > 0 THEN
        SET v_cgpa = v_total_qp / v_total_cu;
    ELSE
        SET v_cgpa = 0.00;
    END IF;

    -- Upsert into cgpa_tb
    INSERT INTO cgpa_tb (student_ID, sem_no, year_no, total_quality_points, total_credit_units, cgpa_value)
    VALUES (p_student_id, 0, v_year_no, v_total_qp, v_total_cu, v_cgpa)
    ON DUPLICATE KEY UPDATE
        total_quality_points = v_total_qp,
        total_credit_units   = v_total_cu,
        cgpa_value           = v_cgpa,
        year_no              = v_year_no;
END$$

DROP PROCEDURE IF EXISTS `sp_recalc_gpa`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_recalc_gpa` (IN `p_student_id` VARCHAR(10), IN `p_sem_no` INT, IN `p_year_no` INT)   BEGIN
    DECLARE v_total_cu   DECIMAL(3,2);
    DECLARE v_total_qp   DECIMAL(3,2);
    DECLARE v_gpa        DECIMAL(3,2);

    -- Sum credit units for modules taken this semester/year
    SELECT IFNULL(SUM(m.credit_unit), 0)
    INTO   v_total_cu
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = p_student_id
      AND  r.sem_no     = p_sem_no
      AND  r.year_no    = p_year_no;

    -- Sum quality points (grade_point × credit_unit) for same semester/year
    SELECT IFNULL(SUM(r.grade_point * m.credit_unit), 0)
    INTO   v_total_qp
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = p_student_id
      AND  r.sem_no     = p_sem_no
      AND  r.year_no    = p_year_no;

    -- GPA = total quality points / total credit units
    IF v_total_cu > 0 THEN
        SET v_gpa = v_total_qp / v_total_cu;
    ELSE
        SET v_gpa = 0.00;
    END IF;

    -- Upsert into gpa_tb
    INSERT INTO gpa_tb (student_ID, sem_no, total_quality_points, total_credit_units, gpa_value)
    VALUES (p_student_id, p_sem_no, v_total_qp, v_total_cu, v_gpa)
    ON DUPLICATE KEY UPDATE
        total_quality_points = v_total_qp,
        total_credit_units   = v_total_cu,
        gpa_value            = v_gpa;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `cgpa_tb`
--

DROP TABLE IF EXISTS `cgpa_tb`;
CREATE TABLE IF NOT EXISTS `cgpa_tb` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `student_ID` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sem_no` int NOT NULL,
  `year_no` int NOT NULL,
  `total_quality_points` decimal(5,2) NOT NULL,
  `total_credit_units` decimal(4,2) NOT NULL,
  `cgpa_value` decimal(3,2) NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `student_ID` (`student_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cgpa_tb`
--

INSERT INTO `cgpa_tb` (`ID`, `student_ID`, `sem_no`, `year_no`, `total_quality_points`, `total_credit_units`, `cgpa_value`) VALUES
(1, '100-000', 2, 1, 171.00, 42.00, 4.07),
(2, '100-000', 3, 2, 264.00, 64.00, 4.13),
(3, '100-000', 4, 2, 352.00, 86.00, 4.09),
(4, '100-000', 5, 3, 437.00, 99.99, 4.16),
(5, '100-000', 6, 3, 538.00, 99.99, 4.24);

-- --------------------------------------------------------

--
-- Table structure for table `goal_setting_tb`
--

DROP TABLE IF EXISTS `goal_setting_tb`;
CREATE TABLE IF NOT EXISTS `goal_setting_tb` (
  `Goal_ID` int NOT NULL AUTO_INCREMENT,
  `student_ID` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_mark` int NOT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`Goal_ID`),
  UNIQUE KEY `student_ID_2` (`student_ID`),
  UNIQUE KEY `module_code_2` (`module_code`),
  UNIQUE KEY `module_code_3` (`module_code`),
  KEY `student_ID` (`student_ID`),
  KEY `module_code` (`module_code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gpa_tb`
--

DROP TABLE IF EXISTS `gpa_tb`;
CREATE TABLE IF NOT EXISTS `gpa_tb` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `student_ID` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_quality_points` decimal(4,2) NOT NULL,
  `total_credit_units` decimal(4,2) NOT NULL,
  `gpa_value` decimal(3,2) NOT NULL,
  `sem_no` int NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_gpa_student_sem` (`student_ID`,`sem_no`),
  KEY `student_ID` (`student_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `gpa_tb`
--

INSERT INTO `gpa_tb` (`ID`, `student_ID`, `total_quality_points`, `total_credit_units`, `gpa_value`, `sem_no`) VALUES
(1, '100-000', 73.50, 17.00, 4.32, 1),
(2, '100-000', 97.50, 25.00, 3.90, 2),
(3, '100-000', 93.00, 22.00, 4.23, 3),
(4, '100-000', 88.00, 22.00, 4.00, 4),
(5, '100-000', 85.00, 19.00, 4.47, 5),
(6, '100-000', 99.99, 22.00, 4.59, 6);

--
-- Triggers `gpa_tb`
--
DROP TRIGGER IF EXISTS `trg_gpa_after_insert_cgpa`;
DELIMITER $$
CREATE TRIGGER `trg_gpa_after_insert_cgpa` AFTER INSERT ON `gpa_tb` FOR EACH ROW BEGIN
    CALL sp_recalc_cgpa(NEW.student_ID);
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_gpa_after_update_cgpa`;
DELIMITER $$
CREATE TRIGGER `trg_gpa_after_update_cgpa` AFTER UPDATE ON `gpa_tb` FOR EACH ROW BEGIN
    CALL sp_recalc_cgpa(NEW.student_ID);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `grade_system`
--

DROP TABLE IF EXISTS `grade_system`;
CREATE TABLE IF NOT EXISTS `grade_system` (
  `grade_ID` int NOT NULL AUTO_INCREMENT,
  `min_mark` int NOT NULL,
  `max_mark` int NOT NULL,
  `grade_point` decimal(3,2) NOT NULL,
  `letter_grade` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`grade_ID`),
  UNIQUE KEY `min_mark` (`min_mark`,`max_mark`),
  UNIQUE KEY `letter_grade_2` (`letter_grade`),
  UNIQUE KEY `grade_point` (`grade_point`),
  KEY `letter_grade` (`letter_grade`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `grade_system`
--

INSERT INTO `grade_system` (`grade_ID`, `min_mark`, `max_mark`, `grade_point`, `letter_grade`) VALUES
(1, 80, 100, 5.00, 'A'),
(2, 75, 79, 4.50, 'B+'),
(3, 70, 74, 4.00, 'B'),
(4, 65, 69, 3.50, 'C+'),
(5, 60, 64, 3.00, 'C'),
(6, 55, 59, 2.50, 'D+'),
(7, 50, 54, 2.00, 'D'),
(8, 0, 49, 0.00, '0');

-- --------------------------------------------------------

--
-- Table structure for table `module_tb`
--

DROP TABLE IF EXISTS `module_tb`;
CREATE TABLE IF NOT EXISTS `module_tb` (
  `ID` int NOT NULL,
  `module_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module_name` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year_no` int NOT NULL,
  `sem_no` int NOT NULL,
  `program_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credit_unit` decimal(3,1) NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `module_code_2` (`module_code`),
  KEY `module_code` (`module_code`),
  KEY `program_code` (`program_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `module_tb`
--

INSERT INTO `module_tb` (`ID`, `module_code`, `module_name`, `year_no`, `sem_no`, `program_code`, `credit_unit`) VALUES
(1, 'BIT111', 'Discrete Mathematics', 1, 1, 'BIT', 3.0),
(2, 'BIT110', 'Introduction to Information & Communication Technology', 1, 1, 'BIT', 4.0),
(3, 'BIT113', 'Fundamentals of Information Systems', 1, 1, 'BIT', 3.0),
(4, 'BBA116', 'Basic Statistics', 1, 1, 'BIT', 3.0),
(5, 'BJC110', 'Communication Skills and Learning skills for Employability', 1, 1, 'BIT', 4.0),
(6, 'BIT122', 'Internet Technology & Web Design', 1, 2, 'BIT', 3.0),
(7, 'BIT123', 'Computer Applications', 1, 2, 'BIT', 4.0),
(8, 'BIT124', 'E-commerce', 1, 2, 'BIT', 3.0),
(9, 'BIT125', 'Information Systems Management', 1, 2, 'BIT', 3.0),
(10, 'BIT126', 'Database Development and Management 1', 1, 2, 'BIT', 4.0),
(11, 'COM122', 'Principles of Programming', 1, 2, 'BIT', 4.0),
(12, 'FST121', 'Industrial Training', 1, 2, 'BIT', 4.0),
(13, 'AGM212', 'Entrepreneurship & Small Business Management', 2, 3, 'BIT', 3.0),
(14, 'BIT212', 'Systems Analysis and Design', 2, 3, 'BIT', 3.0),
(15, 'BIT213', 'Web Development and Management', 2, 3, 'BIT', 4.0),
(16, 'BIT214', 'Computer Networks & Data Communication', 2, 3, 'BIT', 4.0),
(17, 'BIT215', 'Database Development and Management 2', 2, 3, 'BIT', 4.0),
(18, 'COM211', 'Object Oriented Programming', 2, 3, 'BIT', 4.0),
(19, 'BIT222', 'Research Methodology in Computing', 2, 4, 'BIT', 4.0),
(20, 'BIT223', 'Computer Repair and Maintenance', 2, 4, 'BIT', 3.0),
(21, 'BIT225', 'Emerging Trends in Information Technology', 2, 4, 'BIT', 3.0),
(22, 'COM221', 'Operating Systems Principles', 2, 4, 'BIT', 3.0),
(23, 'COM224', 'Software Engineering Principles', 2, 4, 'BIT', 3.0),
(24, 'FST220', 'Industrial Training', 2, 4, 'BIT', 4.0),
(25, 'BIS313', 'Business Systems Modelling', 3, 5, 'BIT', 4.0),
(26, 'BIT311', 'ICT Project Planning and Management', 3, 5, 'BIT', 3.0),
(27, 'BIT312', 'Mobile Application Development', 3, 5, 'BIT', 4.0),
(28, 'BIT314', 'Network Configuration & Management', 3, 5, 'BIT', 4.0),
(29, 'BIT315', 'Multimedia Systems', 3, 5, 'BIT', 4.0),
(30, 'BIT321', 'Professional Issues in Computing', 3, 6, 'BIT', 3.0),
(31, 'BIT325', 'Information Systems Audit', 3, 6, 'BIT', 4.0),
(32, 'BIT323', 'User Interface Design', 3, 6, 'BIT', 4.0),
(33, 'BIT324', 'Network and Information Security', 3, 6, 'BIT', 4.0),
(34, 'BIT322', 'Distributed System Development', 3, 6, 'BIT', 3.0),
(35, 'FST320', 'Graduation Project', 3, 6, 'BIT', 4.0);

-- --------------------------------------------------------

--
-- Table structure for table `predictions_table`
--

DROP TABLE IF EXISTS `predictions_table`;
CREATE TABLE IF NOT EXISTS `predictions_table` (
  `Prediction_ID` int NOT NULL AUTO_INCREMENT,
  `student_ID` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expected_mk` int NOT NULL,
  `predicted_grade` int NOT NULL,
  `predicted_gpa` decimal(3,2) NOT NULL,
  `Goal_ID` int NOT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`Prediction_ID`),
  KEY `student_ID` (`student_ID`),
  KEY `module_code` (`module_code`),
  KEY `Goal_ID` (`Goal_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `program_tb`
--

DROP TABLE IF EXISTS `program_tb`;
CREATE TABLE IF NOT EXISTS `program_tb` (
  `ID` int NOT NULL,
  `program_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `program_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `program_dept` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `program_faculty` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_ID` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`program_code`),
  UNIQUE KEY `ID` (`ID`),
  UNIQUE KEY `student_ID` (`student_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `program_tb`
--

INSERT INTO `program_tb` (`ID`, `program_code`, `program_name`, `program_dept`, `program_faculty`, `student_ID`) VALUES
(1, 'BIT', 'Bachelors of Information Technology', 'ICT', 'FST', '100-000');

-- --------------------------------------------------------

--
-- Table structure for table `results_tb`
--

DROP TABLE IF EXISTS `results_tb`;
CREATE TABLE IF NOT EXISTS `results_tb` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `module_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_ID` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year_no` int NOT NULL,
  `sem_no` int NOT NULL,
  `cat1_mk` int NOT NULL,
  `cat2_mk` int NOT NULL,
  `exam_mk` int NOT NULL,
  `grade_point` decimal(3,2) NOT NULL,
  `letter_grade` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `final_total` int NOT NULL,
  `status_retake_pass` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `module_code` (`module_code`),
  KEY `student_ID` (`student_ID`),
  KEY `letter_grade` (`letter_grade`),
  KEY `grade_point_2` (`grade_point`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `results_tb`
--

INSERT INTO `results_tb` (`ID`, `module_code`, `student_ID`, `year_no`, `sem_no`, `cat1_mk`, `cat2_mk`, `exam_mk`, `grade_point`, `letter_grade`, `final_total`, `status_retake_pass`, `created_at`) VALUES
(1, 'BIT110', '100-000', 1, 1, 17, 18, 40, 4.50, 'B+', 75, 'Pass', '2026-06-15 06:59:19'),
(2, 'BIT111', '100-000', 1, 1, 16, 14, 35, 3.50, 'C+', 65, 'Pass', '2026-06-16 07:30:29'),
(3, 'BBA116', '100-000', 1, 1, 18, 15, 56, 5.00, 'A', 89, 'Pass', '2026-06-16 07:30:29'),
(4, 'BIT113', '100-000', 1, 1, 14, 18, 41, 4.00, 'B', 71, 'Pass', '2026-06-16 07:33:27'),
(5, 'BJC110', '100-000', 1, 1, 15, 17, 45, 4.50, 'B+', 77, 'Pass', '2026-06-16 08:27:40'),
(6, 'BIT122', '100-000', 1, 2, 18, 19, 50, 5.00, 'A', 87, 'Pass', '2026-06-17 07:04:42'),
(7, 'BIT123', '100-000', 1, 2, 15, 15, 40, 4.00, 'B', 70, 'Pass', '2026-06-17 07:04:42'),
(8, 'BIT124', '100-000', 1, 2, 13, 12, 36, 3.00, 'C', 61, 'Pass', '2026-06-17 07:07:33'),
(9, 'BIT125', '100-000', 1, 2, 12, 14, 30, 2.50, 'D+', 56, 'Pass', '2026-06-17 07:07:33'),
(10, 'BIT126', '100-000', 1, 2, 14, 16, 39, 3.50, 'C+', 69, 'Pass', '2026-06-17 07:11:45'),
(11, 'COM122', '100-000', 1, 2, 17, 16, 44, 4.50, 'B+', 77, 'Pass', '2026-06-17 07:11:45'),
(12, 'FST121', '100-000', 1, 2, 0, 0, 75, 4.50, 'B+', 75, 'Pass', '2026-06-17 07:11:45'),
(13, 'AGM212', '100-000', 2, 3, 15, 16, 40, 4.00, 'B', 71, 'Pass', '2026-06-17 08:58:30'),
(14, 'BIT212', '100-000', 2, 3, 17, 18, 48, 5.00, 'A', 83, 'Pass', '2026-06-17 08:58:30'),
(15, 'BIT213', '100-000', 2, 3, 18, 19, 50, 5.00, 'A', 87, 'Pass', '2026-06-17 08:58:30'),
(16, 'BIT214', '100-000', 2, 3, 14, 15, 38, 3.50, 'C+', 67, 'Pass', '2026-06-17 08:58:31'),
(17, 'BIT215', '100-000', 2, 3, 16, 17, 40, 4.00, 'B', 73, 'Pass', '2026-06-17 08:58:31'),
(18, 'COM211', '100-000', 2, 3, 16, 17, 40, 4.00, 'B', 73, 'Pass', '2026-06-17 08:58:31'),
(19, 'BIT222', '100-000', 2, 4, 13, 14, 37, 3.00, 'C', 64, 'Pass', '2026-06-17 08:58:32'),
(20, 'BIT223', '100-000', 2, 4, 17, 18, 46, 5.00, 'A', 81, 'Pass', '2026-06-17 08:58:32'),
(21, 'BIT225', '100-000', 2, 4, 15, 16, 42, 4.00, 'B', 73, 'Pass', '2026-06-17 08:58:32'),
(22, 'COM221', '100-000', 2, 4, 14, 16, 39, 3.50, 'C+', 69, 'Pass', '2026-06-17 08:58:33'),
(23, 'COM224', '100-000', 2, 4, 14, 16, 38, 3.50, 'C+', 68, 'Pass', '2026-06-17 08:58:33'),
(24, 'FST220', '100-000', 2, 4, 0, 0, 85, 5.00, 'A', 85, 'Pass', '2026-06-17 08:58:33'),
(25, 'BIS313', '100-000', 3, 5, 17, 19, 49, 5.00, 'A', 85, 'Pass', '2026-06-17 08:58:33'),
(26, 'BIT311', '100-000', 3, 5, 12, 15, 35, 3.00, 'C', 62, 'Pass', '2026-06-17 08:58:34'),
(27, 'BIT312', '100-000', 3, 5, 17, 18, 50, 5.00, 'A', 85, 'Pass', '2026-06-17 08:58:34'),
(28, 'BIT314', '100-000', 3, 5, 17, 19, 48, 5.00, 'A', 84, 'Pass', '2026-06-17 08:58:35'),
(29, 'BIT315', '100-000', 3, 5, 13, 16, 42, 4.00, 'B', 71, 'Pass', '2026-06-17 08:58:35'),
(30, 'BIT321', '100-000', 3, 6, 17, 17, 40, 4.00, 'B', 74, 'Pass', '2026-06-17 08:58:35'),
(31, 'BIT325', '100-000', 3, 6, 16, 16, 40, 4.00, 'B', 72, 'Pass', '2026-06-17 08:58:35'),
(32, 'BIT323', '100-000', 3, 6, 17, 18, 47, 5.00, 'A', 82, 'Pass', '2026-06-17 08:58:35'),
(33, 'BIT324', '100-000', 3, 6, 14, 17, 44, 4.50, 'B+', 75, 'Pass', '2026-06-17 08:58:35'),
(34, 'BIT322', '100-000', 3, 6, 14, 19, 48, 5.00, 'A', 81, 'Pass', '2026-06-17 08:58:36'),
(35, 'FST320', '100-000', 3, 6, 0, 0, 90, 5.00, 'A', 90, 'Pass', '2026-06-17 08:58:36');

-- --------------------------------------------------------

--
-- Table structure for table `student_tb`
--

DROP TABLE IF EXISTS `student_tb`;
CREATE TABLE IF NOT EXISTS `student_tb` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `student_ID` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_email` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_password` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `program_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gender` enum('M','F') COLLATE utf8mb4_unicode_ci NOT NULL,
  `nationality` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_of_birth` date NOT NULL,
  `intake_year` int NOT NULL,
  `intake_session` enum('JAN','MAY','AUG') COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode_of_entry` enum('Direct','Transfer','Foundation','Mature','Diploma') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `student_ID` (`student_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_tb`
--

INSERT INTO `student_tb` (`ID`, `student_ID`, `student_name`, `student_email`, `student_password`, `program_code`, `gender`, `nationality`, `date_of_birth`, `intake_year`, `intake_session`, `mode_of_entry`) VALUES
(1, '100-000', 'Jane Doe', 'jd100000@students.cavendish.ac.ug', '123', 'BIT', 'F', 'Ugandan', '2000-12-10', 2023, 'JAN', 'Direct'),
(2, '100-001', 'Mary Jane', 'mj100001@students.cavendish.ac.ug', '4321', 'BIT', 'F', 'Kenyan', '1999-04-20', 2024, 'JAN', 'Direct');

-- --------------------------------------------------------

--
-- Table structure for table `term_mapping_tb`
--

DROP TABLE IF EXISTS `term_mapping_tb`;
CREATE TABLE IF NOT EXISTS `term_mapping_tb` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `intake_session` enum('JAN','MAY','AUG') COLLATE utf8mb4_unicode_ci NOT NULL,
  `year_no` int NOT NULL,
  `sem_no` int NOT NULL,
  `year_offset` int NOT NULL,
  `term_month` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_intake_progression` (`intake_session`,`year_no`,`sem_no`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `term_mapping_tb`
--

INSERT INTO `term_mapping_tb` (`ID`, `intake_session`, `year_no`, `sem_no`, `year_offset`, `term_month`) VALUES
(1, 'AUG', 1, 1, 0, 'AUG'),
(2, 'AUG', 1, 2, 1, 'JAN'),
(3, 'AUG', 2, 3, 1, 'AUG'),
(4, 'AUG', 2, 4, 2, 'JAN'),
(5, 'AUG', 3, 5, 2, 'AUG'),
(6, 'AUG', 3, 6, 3, 'JAN'),
(7, 'JAN', 1, 1, 0, 'JAN'),
(8, 'JAN', 1, 2, 0, 'AUG'),
(9, 'JAN', 2, 3, 1, 'JAN'),
(10, 'JAN', 2, 4, 1, 'AUG'),
(11, 'JAN', 3, 5, 2, 'JAN'),
(12, 'JAN', 3, 6, 2, 'AUG'),
(13, 'MAY', 1, 1, 0, 'MAY'),
(14, 'MAY', 1, 2, 0, 'AUG'),
(15, 'MAY', 2, 3, 1, 'JAN'),
(16, 'MAY', 2, 4, 1, 'AUG'),
(17, 'MAY', 3, 5, 2, 'JAN'),
(18, 'MAY', 3, 6, 2, 'AUG');

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cgpa_tb`
--
ALTER TABLE `cgpa_tb`
  ADD CONSTRAINT `cgpa_tb_ibfk_1` FOREIGN KEY (`student_ID`) REFERENCES `student_tb` (`student_ID`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `goal_setting_tb`
--
ALTER TABLE `goal_setting_tb`
  ADD CONSTRAINT `goal_setting_tb_ibfk_1` FOREIGN KEY (`student_ID`) REFERENCES `student_tb` (`student_ID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `goal_setting_tb_ibfk_2` FOREIGN KEY (`module_code`) REFERENCES `module_tb` (`module_code`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `gpa_tb`
--
ALTER TABLE `gpa_tb`
  ADD CONSTRAINT `gpa_tb_ibfk_1` FOREIGN KEY (`student_ID`) REFERENCES `student_tb` (`student_ID`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `module_tb`
--
ALTER TABLE `module_tb`
  ADD CONSTRAINT `module_tb_ibfk_1` FOREIGN KEY (`program_code`) REFERENCES `program_tb` (`program_code`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `predictions_table`
--
ALTER TABLE `predictions_table`
  ADD CONSTRAINT `predictions_table_ibfk_1` FOREIGN KEY (`student_ID`) REFERENCES `student_tb` (`student_ID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `predictions_table_ibfk_2` FOREIGN KEY (`module_code`) REFERENCES `module_tb` (`module_code`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `predictions_table_ibfk_3` FOREIGN KEY (`Goal_ID`) REFERENCES `goal_setting_tb` (`Goal_ID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `program_tb`
--
ALTER TABLE `program_tb`
  ADD CONSTRAINT `program_tb_ibfk_1` FOREIGN KEY (`student_ID`) REFERENCES `student_tb` (`student_ID`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `results_tb`
--
ALTER TABLE `results_tb`
  ADD CONSTRAINT `results_tb_ibfk_1` FOREIGN KEY (`student_ID`) REFERENCES `student_tb` (`student_ID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `results_tb_ibfk_2` FOREIGN KEY (`module_code`) REFERENCES `module_tb` (`module_code`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `results_tb_ibfk_3` FOREIGN KEY (`letter_grade`) REFERENCES `grade_system` (`letter_grade`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `results_tb_ibfk_4` FOREIGN KEY (`grade_point`) REFERENCES `grade_system` (`grade_point`) ON DELETE RESTRICT ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
