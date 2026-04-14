-- phpMyAdmin SQL Dump
-- version 4.0.4
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: Apr 14, 2026 at 03:29 PM
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
-- Table structure for table `cgpa`
--

CREATE TABLE IF NOT EXISTS `cgpa` (
  `ID` int(11) NOT NULL,
  `Student_ID_Ref` int(11) NOT NULL,
  `SemNo` int(11) NOT NULL,
  `YearNo` int(11) NOT NULL,
  `CGPA_Value` decimal(3,2) NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `Student_ID_Ref` (`Student_ID_Ref`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `goalsetting`
--

CREATE TABLE IF NOT EXISTS `goalsetting` (
  `Goal_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Student_ID_Ref` int(11) NOT NULL,
  `Module_Code` varchar(10) NOT NULL,
  `Target_Grade` int(3) NOT NULL,
  PRIMARY KEY (`Goal_ID`),
  KEY `Student_ID_Ref` (`Student_ID_Ref`,`Module_Code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

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

-- --------------------------------------------------------

--
-- Table structure for table `module`
--

CREATE TABLE IF NOT EXISTS `module` (
  `ID` int(11) NOT NULL,
  `Module_Code` varchar(10) NOT NULL,
  `Module_Name` varchar(250) NOT NULL,
  `YearNo` int(11) NOT NULL,
  `SemNo` int(11) NOT NULL,
  `Program_Code` varchar(10) NOT NULL,
  `Credit_Unit` decimal(3,1) NOT NULL,
  PRIMARY KEY (`Module_Code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `predictions`
--

CREATE TABLE IF NOT EXISTS `predictions` (
  `Prediction_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Student_ID_Ref` int(11) NOT NULL,
  `Module_Code` varchar(10) NOT NULL,
  `Expected_Mark` int(11) NOT NULL,
  PRIMARY KEY (`Prediction_ID`),
  KEY `Student_ID_Ref` (`Student_ID_Ref`,`Module_Code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `program`
--

CREATE TABLE IF NOT EXISTS `program` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `Program_Code` varchar(10) NOT NULL,
  `Program_Name` varchar(150) NOT NULL,
  `Program_Dept` varchar(150) NOT NULL,
  `Program_Faculty` varchar(150) NOT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE IF NOT EXISTS `results` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `Module_Code` varchar(10) NOT NULL,
  `Student_ID_Ref` int(11) NOT NULL,
  `YearNo` int(11) NOT NULL,
  `SemNo` int(11) NOT NULL,
  `CAT1_Mark` int(11) NOT NULL,
  `CAT2_Mark` int(11) NOT NULL,
  `Exam_Mark` int(11) NOT NULL,
  `Grade_Point` decimal(3,2) NOT NULL,
  `Letter_Grade` varchar(5) NOT NULL,
  `Final_Total` int(11) NOT NULL,
  `Status` varchar(20) NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `Module_Code` (`Module_Code`,`Student_ID_Ref`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `student`
--

CREATE TABLE IF NOT EXISTS `student` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `Student_ID` varchar(10) NOT NULL,
  `Student_Name` varchar(100) NOT NULL,
  `Program_Code` varchar(10) NOT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `Student_ID` (`Student_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
