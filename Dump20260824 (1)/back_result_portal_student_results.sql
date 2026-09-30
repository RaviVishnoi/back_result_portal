-- MySQL dump 10.13  Distrib 8.0.43, for Win64 (x86_64)
--
-- Host: localhost    Database: back_result_portal
-- ------------------------------------------------------
-- Server version	8.0.43

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `student_results`
--

DROP TABLE IF EXISTS `student_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_results` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exam_id` int NOT NULL,
  `roll_no` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `session` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `subject_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `grade` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `verification_code` varchar(32) COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `semester` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_result_roll_session_subject` (`roll_no`,`session`,`subject_name`),
  KEY `roll_no` (`roll_no`),
  KEY `exam_id` (`exam_id`),
  KEY `idx_exam_roll` (`exam_id`,`roll_no`),
  CONSTRAINT `student_results_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3584 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_results`
--

LOCK TABLES `student_results` WRITE;
/*!40000 ALTER TABLE `student_results` DISABLE KEYS */;
INSERT INTO `student_results` VALUES (3578,42,'22mcan028','June 2026','Critical Thinking and Psychology','A','d12590b309ebdf4ac602235a44b3a559','2026-08-22 06:33:10','I'),(3579,42,'22mcan028','June 2026','Data Mining &amp;amp; Warehousing','B','7bd29a27242aabc6b6f5f90d38772fd8','2026-08-22 06:33:10','II'),(3580,42,'22mcan028','June 2026','Formal Languages and Automation Theory','F','d775c8b68f96a3ad65a6734baa748f69','2026-08-22 06:33:10','I'),(3581,44,'22mcan028','APRIL 2026','Critical Thinking and Psychology','A','184ef13135aec84a5cb54eb805aeb475','2026-08-22 06:34:08','I'),(3582,44,'22mcan028','APRIL 2026','Data Mining &amp;amp; Warehousing','B','b98b6d556ae4b6d5e6bb4f7fd0bdb4d4','2026-08-22 06:34:08','II'),(3583,44,'22mcan028','APRIL 2026','Formal Languages and Automation Theory','F','d07c72b0f4c78b71910c768fa2cec024','2026-08-22 06:34:08','I');
/*!40000 ALTER TABLE `student_results` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-24 15:20:28
