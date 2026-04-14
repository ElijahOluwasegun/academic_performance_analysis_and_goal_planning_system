-- phpMyAdmin SQL Dump
-- version 4.0.4
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: Apr 14, 2026 at 03:28 PM
-- Server version: 5.6.12-log
-- PHP Version: 5.4.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- Database: `exam_management_gp_db`
--
CREATE DATABASE IF NOT EXISTS `exam_management_gp_db` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci;
USE `exam_management_gp_db`;

-- --------------------------------------------------------

--
-- Table structure for table `gpa`
--

CREATE TABLE IF NOT EXISTS `gpa` (
  `ID` int(11) NOT NULL,
  `Student_ID_Ref` int(11) NOT NULL,
  `GPA_Value` decimal(3,2) NOT NULL,
  `SemNo` int(11) NOT NULL,
  `Program_Code` varchar(10) NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `Student_ID_Ref` (`Student_ID_Ref`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
