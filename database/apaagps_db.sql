-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jun 16, 2026 at 05:17 AM
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
  `total_quality_points` decimal(3,2) NOT NULL,
  `total_credit_units` decimal(3,2) NOT NULL,
  `cgpa_value` decimal(3,2) NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_cgpa_student` (`student_ID`),
  KEY `student_ID` (`student_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cgpa_tb`
--

INSERT INTO `cgpa_tb` (`ID`, `student_ID`, `sem_no`, `year_no`, `total_quality_points`, `total_credit_units`, `cgpa_value`) VALUES
(2, '100-000', 0, 1, 9.99, 4.00, 4.50);

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
  `total_quality_points` decimal(3,2) NOT NULL,
  `total_credit_units` decimal(3,2) NOT NULL,
  `gpa_value` decimal(3,2) NOT NULL,
  `sem_no` int NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_gpa_student_sem` (`student_ID`,`sem_no`),
  KEY `student_ID` (`student_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `gpa_tb`
--

INSERT INTO `gpa_tb` (`ID`, `student_ID`, `total_quality_points`, `total_credit_units`, `gpa_value`, `sem_no`) VALUES
(2, '100-000', 9.99, 4.00, 4.50, 1);

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
(5, 'BJC110', 'Communication Skills and Learning skills for Employability', 1, 1, 'BIT', 4.0);

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
  UNIQUE KEY `letter_grade_2` (`letter_grade`),
  UNIQUE KEY `letter_grade_3` (`letter_grade`),
  UNIQUE KEY `grade_point` (`grade_point`),
  KEY `module_code` (`module_code`),
  KEY `student_ID` (`student_ID`),
  KEY `letter_grade` (`letter_grade`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `results_tb`
--

INSERT INTO `results_tb` (`ID`, `module_code`, `student_ID`, `year_no`, `sem_no`, `cat1_mk`, `cat2_mk`, `exam_mk`, `grade_point`, `letter_grade`, `final_total`, `status_retake_pass`, `created_at`) VALUES
(1, 'BIT110', '100-000', 1, 1, 17, 18, 40, 4.50, 'B+', 75, 'Pass', '2026-06-15 06:59:19');

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
  PRIMARY KEY (`ID`),
  UNIQUE KEY `student_ID` (`student_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_tb`
--

INSERT INTO `student_tb` (`ID`, `student_ID`, `student_name`, `student_email`, `student_password`, `program_code`) VALUES
(1, '100-000', 'Jane Doe', 'jd100000@students.cavendish.ac.ug', '123', 'BIT'),
(2, '100-001', 'Mary Jane', 'mj100001@students.cavendish.ac.ug', '4321', 'BIT');

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
