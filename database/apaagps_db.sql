-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jul 02, 2026 at 06:58 AM
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
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_recalc_cgpa` (IN `p_student_id` VARCHAR(10), IN `p_sem_no` INT, IN `p_year_no` INT)   BEGIN
    DECLARE v_total_cu DECIMAL(8,2) DEFAULT 0;
    DECLARE v_total_qp DECIMAL(8,2) DEFAULT 0;
    DECLARE v_cgpa     DECIMAL(5,4) DEFAULT 0;

    -- Cumulative quality points = SUM across all results up to and
    -- including this (year_no, sem_no) ordered chronologically.
    -- A result is "up to this semester" when:
    --   year_no < p_year_no  (earlier academic year), OR
    --   year_no = p_year_no AND sem_no <= p_sem_no (same year, earlier/same sem)
    SELECT
        IFNULL(SUM(r.grade_point * m.credit_unit), 0),
        IFNULL(SUM(m.credit_unit), 0)
    INTO
        v_total_qp,
        v_total_cu
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = p_student_id
      AND  (
              r.year_no < p_year_no
           OR (r.year_no = p_year_no AND r.sem_no <= p_sem_no)
          );

    IF v_total_cu > 0 THEN
        SET v_cgpa = v_total_qp / v_total_cu;
    ELSE
        SET v_cgpa = 0.0000;
    END IF;

    -- Upsert: store this semester's running CGPA.
    -- The unique key uq_cgpa_student_sem_year ensures this updates rather
    -- than inserting a duplicate row.
    INSERT INTO cgpa_tb (student_ID, sem_no, year_no, total_quality_points, total_credit_units, cgpa_value)
    VALUES (p_student_id, p_sem_no, p_year_no, v_total_qp, v_total_cu, v_cgpa)
    ON DUPLICATE KEY UPDATE
        total_quality_points = v_total_qp,
        total_credit_units   = v_total_cu,
        cgpa_value           = v_cgpa;

    -- IMPORTANT: Changing results for an earlier semester affects the CGPA
    -- of ALL later semesters too. Recalculate every later semester in order.
    BEGIN
        DECLARE done    INT DEFAULT 0;
        DECLARE v_s_sem INT;
        DECLARE v_s_yr  INT;

        DECLARE later_sems CURSOR FOR
            SELECT DISTINCT sem_no, year_no
            FROM   results_tb
            WHERE  student_ID = p_student_id
              AND  (
                      year_no > p_year_no
                   OR (year_no = p_year_no AND sem_no > p_sem_no)
                  )
            ORDER BY year_no ASC, sem_no ASC;

        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

        OPEN later_sems;
        ripple_loop: LOOP
            FETCH later_sems INTO v_s_sem, v_s_yr;
            IF done THEN LEAVE ripple_loop; END IF;

            -- Recalculate CGPA for each later semester (not GPA — just the
            -- cumulative value, since only the current semester's results changed)
            BEGIN
                DECLARE v_later_qp DECIMAL(8,2) DEFAULT 0;
                DECLARE v_later_cu DECIMAL(8,2) DEFAULT 0;
                DECLARE v_later_cgpa DECIMAL(5,4) DEFAULT 0;

                SELECT
                    IFNULL(SUM(r.grade_point * m.credit_unit), 0),
                    IFNULL(SUM(m.credit_unit), 0)
                INTO
                    v_later_qp,
                    v_later_cu
                FROM   results_tb r
                JOIN   module_tb  m ON r.module_code = m.module_code
                WHERE  r.student_ID = p_student_id
                  AND  (
                          r.year_no < v_s_yr
                       OR (r.year_no = v_s_yr AND r.sem_no <= v_s_sem)
                      );

                IF v_later_cu > 0 THEN
                    SET v_later_cgpa = v_later_qp / v_later_cu;
                END IF;

                INSERT INTO cgpa_tb (student_ID, sem_no, year_no, total_quality_points, total_credit_units, cgpa_value)
                VALUES (p_student_id, v_s_sem, v_s_yr, v_later_qp, v_later_cu, v_later_cgpa)
                ON DUPLICATE KEY UPDATE
                    total_quality_points = v_later_qp,
                    total_credit_units   = v_later_cu,
                    cgpa_value           = v_later_cgpa;
            END;
        END LOOP;
        CLOSE later_sems;
    END;
END$$

DROP PROCEDURE IF EXISTS `sp_recalc_gpa`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_recalc_gpa` (IN `p_student_id` VARCHAR(10), IN `p_sem_no` INT, IN `p_year_no` INT)   BEGIN
    DECLARE v_total_cu DECIMAL(8,2) DEFAULT 0;
    DECLARE v_total_qp DECIMAL(8,2) DEFAULT 0;
    DECLARE v_gpa      DECIMAL(5,4) DEFAULT 0;

    -- Quality Points for this semester = SUM(grade_point × credit_unit)
    SELECT
        IFNULL(SUM(r.grade_point * m.credit_unit), 0),
        IFNULL(SUM(m.credit_unit), 0)
    INTO
        v_total_qp,
        v_total_cu
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = p_student_id
      AND  r.sem_no     = p_sem_no
      AND  r.year_no    = p_year_no;

    IF v_total_cu > 0 THEN
        SET v_gpa = v_total_qp / v_total_cu;
    ELSE
        SET v_gpa = 0.0000;
    END IF;

    -- Upsert: update if a GPA row exists for this student+semester, else insert
    INSERT INTO gpa_tb (student_ID, sem_no, total_quality_points, total_credit_units, gpa_value)
    VALUES (p_student_id, p_sem_no, v_total_qp, v_total_cu, v_gpa)
    ON DUPLICATE KEY UPDATE
        total_quality_points = v_total_qp,
        total_credit_units   = v_total_cu,
        gpa_value            = v_gpa;

    -- After GPA is saved, immediately recalculate the running CGPA for
    -- this student up to and including this semester.
    CALL sp_recalc_cgpa(p_student_id, p_sem_no, p_year_no);
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
  `total_quality_points` decimal(8,2) NOT NULL,
  `total_credit_units` decimal(8,2) NOT NULL,
  `cgpa_value` decimal(5,4) NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_cgpa_student_sem_year` (`student_ID`,`sem_no`,`year_no`),
  KEY `student_ID` (`student_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cgpa_tb`
--

INSERT INTO `cgpa_tb` (`ID`, `student_ID`, `sem_no`, `year_no`, `total_quality_points`, `total_credit_units`, `cgpa_value`) VALUES
(1, '100-000', 2, 1, 171.00, 42.00, 4.0714),
(2, '100-000', 3, 2, 264.00, 64.00, 4.1250),
(3, '100-000', 4, 2, 344.00, 84.00, 4.0952),
(4, '100-000', 5, 3, 429.00, 103.00, 4.1650),
(5, '100-000', 6, 3, 530.00, 125.00, 4.2400),
(17, '100-001', 0, 1, 9.99, 9.99, 1.0000),
(18, '100-000', 1, 1, 73.50, 17.00, 4.3235),
(39, '100-001', 1, 1, 57.50, 17.00, 3.3824),
(40, '200-001', 1, 1, 67.00, 17.00, 3.9412),
(45, '200-001', 2, 1, 173.50, 42.00, 4.1310),
(52, '200-002', 1, 1, 73.00, 17.00, 4.2941),
(57, '200-002', 2, 1, 175.50, 42.00, 4.1786),
(64, '200-002', 3, 2, 267.50, 64.00, 4.1797),
(70, '200-002', 4, 2, 356.00, 84.00, 4.2381),
(76, '200-003', 1, 1, 83.00, 17.00, 4.8824),
(81, '200-003', 2, 1, 206.50, 42.00, 4.9167),
(88, '200-003', 3, 2, 313.00, 64.00, 4.8906),
(94, '200-003', 4, 2, 408.50, 84.00, 4.8631),
(100, '200-003', 5, 3, 500.00, 103.00, 4.8544),
(105, '200-003', 6, 3, 608.50, 125.00, 4.8680);

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
  `total_quality_points` decimal(8,2) NOT NULL,
  `total_credit_units` decimal(8,2) NOT NULL,
  `gpa_value` decimal(5,4) NOT NULL,
  `sem_no` int NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_gpa_student_sem` (`student_ID`,`sem_no`),
  KEY `student_ID` (`student_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `gpa_tb`
--

INSERT INTO `gpa_tb` (`ID`, `student_ID`, `total_quality_points`, `total_credit_units`, `gpa_value`, `sem_no`) VALUES
(1, '100-000', 73.50, 17.00, 4.3235, 1),
(2, '100-000', 97.50, 25.00, 3.9000, 2),
(3, '100-000', 93.00, 22.00, 4.2273, 3),
(4, '100-000', 80.00, 20.00, 4.0000, 4),
(5, '100-000', 85.00, 19.00, 4.4737, 5),
(6, '100-000', 101.00, 22.00, 4.5909, 6),
(7, '100-001', 57.50, 17.00, 3.3824, 1),
(15, '200-001', 67.00, 17.00, 3.9412, 1),
(20, '200-001', 106.50, 25.00, 4.2600, 2),
(27, '200-002', 73.00, 17.00, 4.2941, 1),
(32, '200-002', 102.50, 25.00, 4.1000, 2),
(39, '200-002', 92.00, 22.00, 4.1818, 3),
(45, '200-002', 88.50, 20.00, 4.4250, 4),
(51, '200-003', 83.00, 17.00, 4.8824, 1),
(56, '200-003', 123.50, 25.00, 4.9400, 2),
(63, '200-003', 106.50, 22.00, 4.8409, 3),
(69, '200-003', 95.50, 20.00, 4.7750, 4),
(75, '200-003', 91.50, 19.00, 4.8158, 5),
(80, '200-003', 108.50, 22.00, 4.9318, 6);

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
-- Table structure for table `lecturer_module_tb`
--

DROP TABLE IF EXISTS `lecturer_module_tb`;
CREATE TABLE IF NOT EXISTS `lecturer_module_tb` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `lecturer_ID` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `module_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_lecturer_module` (`lecturer_ID`,`module_code`),
  KEY `module_code` (`module_code`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `lecturer_module_tb`
--

INSERT INTO `lecturer_module_tb` (`ID`, `lecturer_ID`, `module_code`) VALUES
(9, '183972', 'BIT111'),
(1, '220450', 'BIT213'),
(2, '220450', 'BIT314'),
(3, '220451', 'BIT110'),
(4, '220451', 'BIT122'),
(5, '220451', 'BIT212'),
(8, '220452', 'BIT322'),
(6, '220452', 'COM122'),
(7, '220452', 'COM211'),
(10, 'LEC0329', 'BIT113');

-- --------------------------------------------------------

--
-- Table structure for table `lecturer_tb`
--

DROP TABLE IF EXISTS `lecturer_tb`;
CREATE TABLE IF NOT EXISTS `lecturer_tb` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `lecturer_ID` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lecturer_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lecturer_title` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lecturer_email` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lecturer_password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lecturer_faculty` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lecturer_department` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `lecturer_ID` (`lecturer_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `lecturer_tb`
--

INSERT INTO `lecturer_tb` (`ID`, `lecturer_ID`, `lecturer_name`, `lecturer_title`, `lecturer_email`, `lecturer_password`, `lecturer_faculty`, `lecturer_department`) VALUES
(1, '220450', 'George David', NULL, 'gdavid@cavendish.ac.ug', '$2y$10$sSLy3c.ZMus2qkur/43At.ul0FNTom377pUM8XjZ5Cdlcu1l/zy46', NULL, NULL),
(2, '220451', 'Dr. Sarah Namuli', NULL, 'snamuli@cavendish.ac.ug', 'Lec@2026', NULL, NULL),
(3, '220452', 'Mr. Kevin Ssemanda', NULL, 'kssemanda@cavendish.ac.ug', 'Lec@2026', NULL, NULL),
(4, 'LEC0329', 'Stella Johns', 'Mrs', 'sjohns@cavendish.ac.ug', '$2y$10$bFTWDMhtSrJRsMzV5hnP5ecJUc8sWh13/Vnyt6wM6EToX4PZNHf2S', 'FST', 'Information Technology'),
(5, '183972', 'Steve Alfred', 'Mr', 'salfred@cavendish.ac.ug', '$2y$10$qA0H2pG8WBDKecaLIIDPSuUvsJhR4TNcYokfHNNKwgy39QVHqhTfq', 'FST', 'Information Technology');

-- --------------------------------------------------------

--
-- Table structure for table `module_report_tb`
--

DROP TABLE IF EXISTS `module_report_tb`;
CREATE TABLE IF NOT EXISTS `module_report_tb` (
  `report_ID` int NOT NULL AUTO_INCREMENT,
  `student_ID` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `module_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lecturer_ID` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` enum('CAT1','CAT2','Exam') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Submitted','Reviewing','Resolved','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Submitted',
  `lecturer_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_ID`),
  KEY `student_ID` (`student_ID`),
  KEY `module_code` (`module_code`),
  KEY `lecturer_ID` (`lecturer_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `module_report_tb`
--

INSERT INTO `module_report_tb` (`report_ID`, `student_ID`, `module_code`, `lecturer_ID`, `category`, `message`, `status`, `lecturer_note`, `created_at`, `updated_at`) VALUES
(1, '100-001', 'BIT111', NULL, 'Exam', 'My Exam mark is wrongly calculated', 'Submitted', NULL, '2026-07-02 06:12:12', '2026-07-02 06:12:12'),
(2, '100-001', 'BIT113', 'LEC0329', 'CAT1', 'My CAT1 mark is different from the sheet you sent in the group', 'Resolved', NULL, '2026-07-02 06:43:26', '2026-07-02 06:52:51');

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
  KEY `grade_point_2` (`grade_point`),
  UNIQUE KEY `uq_result_student_module` (`student_ID`,`module_code`)
) ENGINE=InnoDB AUTO_INCREMENT=113 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(35, 'FST320', '100-000', 3, 6, 0, 0, 90, 5.00, 'A', 90, 'Pass', '2026-06-17 08:58:36'),
(36, 'BIT111', '100-001', 1, 1, 12, 13, 25, 2.00, 'D', 50, 'Pass', '2026-06-22 06:06:01'),
(37, 'BIT110', '100-001', 1, 1, 14, 13, 30, 2.50, 'D+', 57, 'Pass', '2026-06-22 06:06:01'),
(39, 'BBA116', '100-001', 1, 1, 15, 15, 39, 3.50, 'C+', 69, 'Pass', '2026-06-22 06:06:01'),
(40, 'BJC110', '100-001', 1, 1, 16, 14, 41, 4.00, 'B', 71, 'Pass', '2026-06-22 06:06:01'),
(41, 'BIT110', '200-001', 1, 1, 15, 16, 42, 4.00, 'B', 73, 'Pass', '2026-06-30 07:00:00'),
(42, 'BIT111', '200-001', 1, 1, 18, 17, 48, 5.00, 'A', 83, 'Pass', '2026-06-30 07:00:00'),
(43, 'BBA116', '200-001', 1, 1, 14, 15, 38, 3.50, 'C+', 67, 'Pass', '2026-06-30 07:00:00'),
(44, 'BIT113', '200-001', 1, 1, 16, 17, 44, 4.50, 'B+', 77, 'Pass', '2026-06-30 07:00:00'),
(45, 'BJC110', '200-001', 1, 1, 13, 14, 34, 3.00, 'C', 61, 'Pass', '2026-06-30 07:00:00'),
(46, 'BIT122', '200-001', 1, 2, 17, 18, 48, 5.00, 'A', 83, 'Pass', '2026-06-30 07:00:00'),
(47, 'BIT123', '200-001', 1, 2, 16, 15, 42, 4.00, 'B', 73, 'Pass', '2026-06-30 07:00:00'),
(48, 'BIT124', '200-001', 1, 2, 14, 15, 43, 4.00, 'B', 72, 'Pass', '2026-06-30 07:00:00'),
(49, 'BIT125', '200-001', 1, 2, 15, 16, 45, 4.50, 'B+', 76, 'Pass', '2026-06-30 07:00:00'),
(50, 'BIT126', '200-001', 1, 2, 13, 14, 33, 3.00, 'C', 60, 'Pass', '2026-06-30 07:00:00'),
(51, 'COM122', '200-001', 1, 2, 17, 18, 49, 5.00, 'A', 84, 'Pass', '2026-06-30 07:00:00'),
(52, 'FST121', '200-001', 1, 2, 0, 0, 78, 4.50, 'B+', 78, 'Pass', '2026-06-30 07:00:00'),
(53, 'BIT110', '200-002', 1, 1, 18, 19, 50, 5.00, 'A', 87, 'Pass', '2026-06-30 07:00:00'),
(54, 'BIT111', '200-002', 1, 1, 16, 15, 41, 4.00, 'B', 72, 'Pass', '2026-06-30 07:00:00'),
(55, 'BBA116', '200-002', 1, 1, 14, 13, 36, 3.00, 'C', 63, 'Pass', '2026-06-30 07:00:00'),
(56, 'BIT113', '200-002', 1, 1, 15, 16, 40, 4.00, 'B', 71, 'Pass', '2026-06-30 07:00:00'),
(57, 'BJC110', '200-002', 1, 1, 17, 18, 46, 5.00, 'A', 81, 'Pass', '2026-06-30 07:00:00'),
(58, 'BIT122', '200-002', 1, 2, 15, 14, 38, 3.50, 'C+', 67, 'Pass', '2026-06-30 07:00:00'),
(59, 'BIT123', '200-002', 1, 2, 16, 17, 43, 4.50, 'B+', 76, 'Pass', '2026-06-30 07:00:00'),
(60, 'BIT124', '200-002', 1, 2, 13, 12, 31, 2.50, 'D+', 56, 'Pass', '2026-06-30 07:00:00'),
(61, 'BIT125', '200-002', 1, 2, 14, 15, 39, 3.50, 'C+', 68, 'Pass', '2026-06-30 07:00:00'),
(62, 'BIT126', '200-002', 1, 2, 17, 18, 46, 5.00, 'A', 81, 'Pass', '2026-06-30 07:00:00'),
(63, 'COM122', '200-002', 1, 2, 15, 16, 42, 4.00, 'B', 73, 'Pass', '2026-06-30 07:00:00'),
(64, 'FST121', '200-002', 1, 2, 0, 0, 80, 5.00, 'A', 80, 'Pass', '2026-06-30 07:00:00'),
(65, 'AGM212', '200-002', 2, 3, 16, 17, 44, 4.50, 'B+', 77, 'Pass', '2026-06-30 07:00:00'),
(66, 'BIT212', '200-002', 2, 3, 15, 14, 38, 3.50, 'C+', 67, 'Pass', '2026-06-30 07:00:00'),
(67, 'BIT213', '200-002', 2, 3, 18, 19, 50, 5.00, 'A', 87, 'Pass', '2026-06-30 07:00:00'),
(68, 'BIT214', '200-002', 2, 3, 17, 16, 43, 4.50, 'B+', 76, 'Pass', '2026-06-30 07:00:00'),
(69, 'BIT215', '200-002', 2, 3, 13, 14, 33, 3.00, 'C', 60, 'Pass', '2026-06-30 07:00:00'),
(70, 'COM211', '200-002', 2, 3, 16, 17, 44, 4.50, 'B+', 77, 'Pass', '2026-06-30 07:00:00'),
(71, 'BIT222', '200-002', 2, 4, 15, 16, 42, 4.00, 'B', 73, 'Pass', '2026-06-30 07:00:00'),
(72, 'BIT223', '200-002', 2, 4, 17, 18, 48, 5.00, 'A', 83, 'Pass', '2026-06-30 07:00:00'),
(73, 'BIT225', '200-002', 2, 4, 14, 15, 40, 3.50, 'C+', 69, 'Pass', '2026-06-30 07:00:00'),
(74, 'COM221', '200-002', 2, 4, 18, 17, 48, 5.00, 'A', 83, 'Pass', '2026-06-30 07:00:00'),
(75, 'COM224', '200-002', 2, 4, 16, 15, 41, 4.00, 'B', 72, 'Pass', '2026-06-30 07:00:00'),
(76, 'FST220', '200-002', 2, 4, 0, 0, 88, 5.00, 'A', 88, 'Pass', '2026-06-30 07:00:00'),
(77, 'BIT110', '200-003', 1, 1, 19, 20, 52, 5.00, 'A', 91, 'Pass', '2026-06-30 07:00:00'),
(78, 'BIT111', '200-003', 1, 1, 18, 18, 48, 5.00, 'A', 84, 'Pass', '2026-06-30 07:00:00'),
(79, 'BBA116', '200-003', 1, 1, 17, 18, 46, 5.00, 'A', 81, 'Pass', '2026-06-30 07:00:00'),
(80, 'BIT113', '200-003', 1, 1, 19, 19, 49, 5.00, 'A', 87, 'Pass', '2026-06-30 07:00:00'),
(81, 'BJC110', '200-003', 1, 1, 16, 17, 43, 4.50, 'B+', 76, 'Pass', '2026-06-30 07:00:00'),
(82, 'BIT122', '200-003', 1, 2, 18, 19, 50, 5.00, 'A', 87, 'Pass', '2026-06-30 07:00:00'),
(83, 'BIT123', '200-003', 1, 2, 17, 18, 46, 5.00, 'A', 81, 'Pass', '2026-06-30 07:00:00'),
(84, 'BIT124', '200-003', 1, 2, 16, 17, 44, 4.50, 'B+', 77, 'Pass', '2026-06-30 07:00:00'),
(85, 'BIT125', '200-003', 1, 2, 18, 19, 49, 5.00, 'A', 86, 'Pass', '2026-06-30 07:00:00'),
(86, 'BIT126', '200-003', 1, 2, 17, 18, 47, 5.00, 'A', 82, 'Pass', '2026-06-30 07:00:00'),
(87, 'COM122', '200-003', 1, 2, 18, 19, 50, 5.00, 'A', 87, 'Pass', '2026-06-30 07:00:00'),
(88, 'FST121', '200-003', 1, 2, 0, 0, 85, 5.00, 'A', 85, 'Pass', '2026-06-30 07:00:00'),
(89, 'AGM212', '200-003', 2, 3, 16, 17, 43, 4.50, 'B+', 76, 'Pass', '2026-06-30 07:00:00'),
(90, 'BIT212', '200-003', 2, 3, 18, 19, 49, 5.00, 'A', 86, 'Pass', '2026-06-30 07:00:00'),
(91, 'BIT213', '200-003', 2, 3, 19, 20, 52, 5.00, 'A', 91, 'Pass', '2026-06-30 07:00:00'),
(92, 'BIT214', '200-003', 2, 3, 17, 18, 47, 5.00, 'A', 82, 'Pass', '2026-06-30 07:00:00'),
(93, 'BIT215', '200-003', 2, 3, 16, 17, 44, 4.50, 'B+', 77, 'Pass', '2026-06-30 07:00:00'),
(94, 'COM211', '200-003', 2, 3, 18, 19, 50, 5.00, 'A', 87, 'Pass', '2026-06-30 07:00:00'),
(95, 'BIT222', '200-003', 2, 4, 17, 18, 46, 5.00, 'A', 81, 'Pass', '2026-06-30 07:00:00'),
(96, 'BIT223', '200-003', 2, 4, 16, 17, 43, 4.50, 'B+', 76, 'Pass', '2026-06-30 07:00:00'),
(97, 'BIT225', '200-003', 2, 4, 18, 19, 50, 5.00, 'A', 87, 'Pass', '2026-06-30 07:00:00'),
(98, 'COM221', '200-003', 2, 4, 17, 18, 47, 5.00, 'A', 82, 'Pass', '2026-06-30 07:00:00'),
(99, 'COM224', '200-003', 2, 4, 15, 16, 43, 4.00, 'B', 74, 'Pass', '2026-06-30 07:00:00'),
(100, 'FST220', '200-003', 2, 4, 0, 0, 92, 5.00, 'A', 92, 'Pass', '2026-06-30 07:00:00'),
(101, 'BIS313', '200-003', 3, 5, 18, 19, 49, 5.00, 'A', 86, 'Pass', '2026-06-30 07:00:00'),
(102, 'BIT311', '200-003', 3, 5, 16, 17, 44, 4.50, 'B+', 77, 'Pass', '2026-06-30 07:00:00'),
(103, 'BIT312', '200-003', 3, 5, 18, 19, 50, 5.00, 'A', 87, 'Pass', '2026-06-30 07:00:00'),
(104, 'BIT314', '200-003', 3, 5, 17, 18, 47, 5.00, 'A', 82, 'Pass', '2026-06-30 07:00:00'),
(105, 'BIT315', '200-003', 3, 5, 16, 17, 44, 4.50, 'B+', 77, 'Pass', '2026-06-30 07:00:00'),
(106, 'BIT321', '200-003', 3, 6, 17, 18, 47, 5.00, 'A', 82, 'Pass', '2026-06-30 07:00:00'),
(107, 'BIT325', '200-003', 3, 6, 18, 19, 49, 5.00, 'A', 86, 'Pass', '2026-06-30 07:00:00'),
(108, 'BIT323', '200-003', 3, 6, 17, 18, 46, 5.00, 'A', 81, 'Pass', '2026-06-30 07:00:00'),
(109, 'BIT324', '200-003', 3, 6, 18, 19, 49, 5.00, 'A', 86, 'Pass', '2026-06-30 07:00:00'),
(110, 'BIT322', '200-003', 3, 6, 16, 17, 44, 4.50, 'B+', 77, 'Pass', '2026-06-30 07:00:00'),
(111, 'FST320', '200-003', 3, 6, 0, 0, 95, 5.00, 'A', 95, 'Pass', '2026-06-30 07:00:00'),
(112, 'BIT113', '100-001', 1, 1, 16, 19, 45, 5.00, 'A', 80, 'Pass', '2026-07-02 06:50:30');

--
-- Triggers `results_tb`
--
DROP TRIGGER IF EXISTS `trg_results_after_insert`;
DELIMITER $$
CREATE TRIGGER `trg_results_after_insert` AFTER INSERT ON `results_tb` FOR EACH ROW BEGIN
    CALL sp_recalc_gpa(NEW.student_ID, NEW.sem_no, NEW.year_no);
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_results_after_update`;
DELIMITER $$
CREATE TRIGGER `trg_results_after_update` AFTER UPDATE ON `results_tb` FOR EACH ROW BEGIN
    -- If the mark/grade changed, recalculate for the current semester.
    -- The procedure itself handles rippling the CGPA change forward.
    IF NEW.grade_point <> OLD.grade_point OR NEW.sem_no <> OLD.sem_no OR NEW.year_no <> OLD.year_no THEN
        CALL sp_recalc_gpa(NEW.student_ID, NEW.sem_no, NEW.year_no);
        -- If moved to a different semester, also recalc the old one
        IF NEW.sem_no <> OLD.sem_no OR NEW.year_no <> OLD.year_no THEN
            CALL sp_recalc_gpa(OLD.student_ID, OLD.sem_no, OLD.year_no);
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `result_upload_log_tb`
--

DROP TABLE IF EXISTS `result_upload_log_tb`;
CREATE TABLE IF NOT EXISTS `result_upload_log_tb` (
  `ID` int NOT NULL AUTO_INCREMENT,
  `lecturer_ID` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `module_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rows_total` int NOT NULL DEFAULT '0',
  `rows_inserted` int NOT NULL DEFAULT '0',
  `rows_updated` int NOT NULL DEFAULT '0',
  `rows_skipped` int NOT NULL DEFAULT '0',
  `skipped_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ID`),
  KEY `lecturer_ID` (`lecturer_ID`),
  KEY `module_code` (`module_code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `result_upload_log_tb`
--

INSERT INTO `result_upload_log_tb` (`ID`, `lecturer_ID`, `module_code`, `file_name`, `rows_total`, `rows_inserted`, `rows_updated`, `rows_skipped`, `skipped_detail`, `uploaded_at`) VALUES
(1, 'LEC0329', 'BIT113', 'Result sheet - Copy.xlsx', 1, 0, 1, 0, '', '2026-07-02 06:50:30');

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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_tb`
--

INSERT INTO `student_tb` (`ID`, `student_ID`, `student_name`, `student_email`, `student_password`, `program_code`, `gender`, `nationality`, `date_of_birth`, `intake_year`, `intake_session`, `mode_of_entry`) VALUES
(1, '100-000', 'Jane Doe', 'jd100000@students.cavendish.ac.ug', '$2y$10$DHNM0AW4UG4s/', 'BIT', 'F', 'Ugandan', '2000-12-10', 2023, 'JAN', 'Direct'),
(2, '100-001', 'Mary Jane', 'mj100001@students.cavendish.ac.ug', '$2y$10$wESqlg45fvQgo', 'BIT', 'F', 'Kenyan', '1999-04-20', 2024, 'JAN', 'Direct'),
(3, '200-001', 'Amara Nakato', 'an200001@students.cavendish.ac.ug', 'Test@1234', 'BIT', 'F', 'Ugandan', '2002-03-15', 2023, 'AUG', 'Direct'),
(4, '200-002', 'Brian Otieno', 'bo200002@students.cavendish.ac.ug', 'Test@1234', 'BIT', 'M', 'Kenyan', '2001-07-22', 2022, 'JAN', 'Direct'),
(5, '200-003', 'Chidi Okonkwo', 'co200003@students.cavendish.ac.ug', 'Test@1234', 'BIT', 'M', 'Nigerian', '2001-11-05', 2021, 'AUG', 'Direct');

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
-- Constraints for table `lecturer_module_tb`
--
ALTER TABLE `lecturer_module_tb`
  ADD CONSTRAINT `fk_lecturer_module_lecturer` FOREIGN KEY (`lecturer_ID`) REFERENCES `lecturer_tb` (`lecturer_ID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lecturer_module_module` FOREIGN KEY (`module_code`) REFERENCES `module_tb` (`module_code`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `module_report_tb`
--
ALTER TABLE `module_report_tb`
  ADD CONSTRAINT `fk_report_lecturer` FOREIGN KEY (`lecturer_ID`) REFERENCES `lecturer_tb` (`lecturer_ID`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_report_module` FOREIGN KEY (`module_code`) REFERENCES `module_tb` (`module_code`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_report_student` FOREIGN KEY (`student_ID`) REFERENCES `student_tb` (`student_ID`) ON DELETE CASCADE;

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

--
-- Constraints for table `result_upload_log_tb`
--
ALTER TABLE `result_upload_log_tb`
  ADD CONSTRAINT `fk_log_lecturer` FOREIGN KEY (`lecturer_ID`) REFERENCES `lecturer_tb` (`lecturer_ID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_log_module` FOREIGN KEY (`module_code`) REFERENCES `module_tb` (`module_code`) ON DELETE RESTRICT ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
