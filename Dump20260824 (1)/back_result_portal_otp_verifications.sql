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
-- Table structure for table `otp_verifications`
--

DROP TABLE IF EXISTS `otp_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_verifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `roll_no` varchar(50) NOT NULL,
  `exam_id` int NOT NULL,
  `email` varchar(255) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint DEFAULT '0',
  `is_used` tinyint DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_roll_exam` (`roll_no`,`exam_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_verifications`
--

LOCK TABLES `otp_verifications` WRITE;
/*!40000 ALTER TABLE `otp_verifications` DISABLE KEYS */;
INSERT INTO `otp_verifications` VALUES (1,'22mcan028',24,'ravi.vishnoi@jecrcu.edu.in','$2y$10$FGbpSbNMa4FR3AFy2Qorx.nouylPDqy9f9wQnCJBAMFlf8JPWEPPm','2026-08-19 09:54:21',0,1,'2026-08-19 07:49:21'),(2,'23BCOL2248',25,'kunjitdulani@gmail.com','$2y$10$1bwmm0P9UGfCyMkVDuve1OUshIM.ZE2S/i/Pt1Hy4HuZosjAUgXPS','2026-08-19 09:54:46',0,0,'2026-08-19 07:49:46'),(3,'22mcan028',24,'ravi.vishnoi@jecrcu.edu.in','$2y$10$r2jAD63MJ56J5PmTRyxMhe0GesDDszKAJPiQKhtZNaRrb9Cz9mcnm','2026-08-19 10:05:47',0,1,'2026-08-19 08:00:47'),(4,'22mcan028',24,'ravi.vishnoi@jecrcu.edu.in','$2y$10$oqyFJbqEWrCOrAilZzuDI.8EmmaNAkBuOzOE/rUNYHkkCuAHEyZWC','2026-08-19 10:08:15',0,1,'2026-08-19 08:03:15'),(5,'22mcan028',24,'ravi.vishnoi@jecrcu.edu.in','$2y$10$5lS0HY1ZmhX0ivOg7OFe7.aXhUJ4QxKrYsKuMQayED/Z6tvMpmIai','2026-08-19 10:46:58',0,1,'2026-08-19 08:41:58'),(6,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$Q1IULdTEcIS1RD.Y4JxyIe6WBdF42J3qnqvhVl/BfoG/s0KpKkCDi','2026-08-19 10:56:15',0,1,'2026-08-19 08:51:15'),(7,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$YxrMFepw0MaKrEwPiAZgiO/UV.KYvj5WDnRlyE0Wx0SszWkOQm1mi','2026-08-19 11:05:37',0,1,'2026-08-19 09:00:37'),(8,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$NC7sMgKzXqM.WpeXznd3c.7fjrmnDOT/ay/u5aYtH/9ZgiYJL5X86','2026-08-19 11:24:12',0,1,'2026-08-19 09:19:12'),(9,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$WWixCXm2TSrnxRpXIlkFhO2t/Gj0GjAAVHugxyx9BLtEghW1QFgL2','2026-08-19 11:26:03',0,1,'2026-08-19 09:21:03'),(10,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$AbtWo7o05l1aigcCVBb0weAlHmUa25UgtT3n1p8kQA.l7ZR2NQQTK','2026-08-19 12:25:10',0,1,'2026-08-19 10:20:10'),(11,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$S7qBNW8LP4L8uW7oHUpn6.coNXQGSCNLnIQWLSXwJYL0CSj0Vvg/2','2026-08-19 12:26:59',0,1,'2026-08-19 10:21:59'),(12,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$T4/A3r1CZ6F6UsUaKhoCY.K3EdkpGe3u3Yb2lEa3hjcGzVCrH6jkK','2026-08-19 12:42:03',0,1,'2026-08-19 10:37:03'),(13,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$UUj9FpOwwkmGbkYt7sW5l.LKU1.d8IJ4R..1aklWhkISLWPnZVjLe','2026-08-19 12:44:11',0,1,'2026-08-19 10:39:11'),(14,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$fyWG3O0ND3iOCNcE0E7EN.JnI7EEc.YcP8VkUL0xS90317L4kP9j6','2026-08-19 16:33:36',0,1,'2026-08-19 10:58:36'),(15,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$PyuyFYiyzlF2/oPD/S/bFebP4NDokTj2gy5Om5shf38SkxOfYmmA2','2026-08-19 16:40:20',0,1,'2026-08-19 11:05:20'),(16,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$O0dt6iKaueSfjEI9KwjOm.glAxLjMYfTu277QTg3whoQcmKqJY6U2','2026-08-19 16:51:15',0,1,'2026-08-19 11:16:15'),(17,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$xTvYjyMEWdxq1nHZlzX.EuNp9P9C6YzjvQTNQq21syk8ZRjmh0KSy','2026-08-19 17:16:53',0,1,'2026-08-19 11:41:53'),(18,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$3b0hV7K5yD0I56qiW0y3I.TiGgRN7FFOQ1/vw1Reh5b2r31595qCS','2026-08-19 17:19:24',0,1,'2026-08-19 11:44:24'),(19,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$jgBDh0LqGBMzLPq6oRxYQekuC8IxJSruI12Lp78iW3txc1ukpKrlK','2026-08-19 17:24:12',0,1,'2026-08-19 11:49:12'),(20,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$SzP84LMP3rLWE6KSE.dzn.EskZ13UWoFBeI3P1QQqyGmWngkAzL9O','2026-08-20 10:19:12',0,1,'2026-08-20 04:44:12'),(21,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$ekufQsQKB0NT6FWi.o9hkeq0WjGLZVmgAXfDHD6C6SkpGhHm7L2FO','2026-08-20 10:21:37',0,1,'2026-08-20 04:46:37'),(22,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$A6d0mH5K95RHJakHO.XAWeamS5E.maLxyG4SOKH4F9wZOF.IOhshK','2026-08-20 10:33:37',0,1,'2026-08-20 04:58:37'),(23,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$axB0ChGn/O6t3DcvJrqxEuDAgt1.CNsCod9wYzQIs3BlgRds9h71u','2026-08-20 10:36:22',0,1,'2026-08-20 05:01:22'),(24,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$oAivPjaSmb5Ex5d29r6X8u3MG4ErOWMmgBImKc.1/YjflkHKnogjm','2026-08-20 13:08:53',0,1,'2026-08-20 07:33:53'),(25,'23mcan0028',27,'sailendra.trivedi@jecrcu.edu.in','$2y$10$HUcQ46mNOOT/i83sLgRKO.U8tSogJf0O6TBtphqFkjtqhNgdlSIP2','2026-08-20 13:47:30',0,1,'2026-08-20 08:12:30'),(26,'23mcan0028',27,'sailendra.trivedi@jecrcu.edu.in','$2y$10$D1/xfnlC.EcCexGP1wav7u0L11S92CVgJOJZXYEAlhNrV5Dlj8I5y','2026-08-20 13:52:58',0,1,'2026-08-20 08:17:58'),(27,'22mcan028',26,'ravivishnoi57@gmail.com','$2y$10$1x1r/Z1qvt60col4Sm2LIeuOfTQ62sVQhZ21G6LSsI/BqdW0qe19.','2026-08-20 13:57:11',0,1,'2026-08-20 08:22:11'),(28,'22mcan028',28,'ravivishnoi57@gmail.com','$2y$10$ch/V5b.rLUEcl7ZjjtkJXuDTliNTwiu6lSWRCJrAy9zfjdj0zeE/m','2026-08-20 15:44:04',0,1,'2026-08-20 10:09:04'),(29,'23mcan0028',28,'sailendra.trivedi@jecrcu.edu.in','$2y$10$j0Y1Ipf9LzBOFoN2XoS3R.3CvRDDclpBteF8yohYDlGxltsRFe4oK','2026-08-20 15:46:36',0,1,'2026-08-20 10:11:36'),(30,'22mcan028',28,'ravivishnoi09@gmail.com','$2y$10$IG57gNW/xNCjj8s74kdLkOhMgPGEHtHS9BiMzd.4sxjfMd7w41myS','2026-08-20 18:03:44',0,1,'2026-08-20 12:28:44'),(31,'22mcan028',28,'ravivishnoi09@gmail.com','$2y$10$MKXE/RqHF6CiroQtjq0BBOhewPLQJOsRgotVzVsUQnieF5XYVR2bW','2026-08-20 18:05:29',0,1,'2026-08-20 12:30:29'),(32,'22mcan028',0,'ravivishnoi09@gmail.com','$2y$10$ocw6npZVeMlfl9VRwZPuIOmlKZkO0CH/yDYpjUQRkQzJu4eBSp70.','2026-08-21 10:50:23',0,1,'2026-08-21 05:15:23'),(33,'22mcan028',0,'ravivishnoi09@gmail.com','$2y$10$BR0jFexeUHuHkTyh.fXCpOpzupIS2gcuTQoepKwwdU8VUYg/P9MDq','2026-08-21 10:59:46',0,1,'2026-08-21 05:24:46'),(34,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$M3MDCVM2FNjnnlnY/FoddO/WA7nsusG6IVdlgC1L0ZIZ5EOgMPE.e','2026-08-21 11:02:56',0,1,'2026-08-21 05:27:56'),(35,'23mcan0028',0,'sailendra.trivedi@jecrcu.edu.in','$2y$10$WoEeAgR4g28HhUvci4KoQuZnRielMJHC65.lCDgaE9ozrNNvESKlS','2026-08-21 11:03:44',0,1,'2026-08-21 05:28:44'),(36,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$VpiXd8gcdwJ1rdHh9DuJbugBBvTB20JsvpI0CjpFJ/jmln/TYxvqe','2026-08-21 12:44:45',0,1,'2026-08-21 07:09:45'),(37,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$/hx1UTgrNIB1upU3dLikK.aavWV0WcjrgxNYhP9TXw69PprqAgrge','2026-08-21 12:56:45',0,1,'2026-08-21 07:21:45'),(38,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$3iJ0GKVLkOkB9On0.v.Ww.KDQj7DVK8yC3u7F8nQffMftW.iO5RMm','2026-08-21 14:16:20',0,1,'2026-08-21 08:41:20'),(39,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$nXFigLHJXNGQooCClm0kk.Nxyrt4pH7i6z5pg//7bUML48NcYR8WK','2026-08-21 14:36:58',0,1,'2026-08-21 09:01:58'),(40,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$.QK.yYjtqj.auJa4xtlSyeTa/7LVdeaHtGWyZ8T6JFc0R4byt2/Yu','2026-08-21 17:45:20',0,1,'2026-08-21 12:10:20'),(41,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$FfcTD3xfoDngf.nOENlIeez.E2HrNYRidAEi5aP9VHh5WQDU.i4l6','2026-08-22 12:09:26',0,1,'2026-08-22 06:34:26'),(42,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$ExTT9/3LXT6m829TCBzI6Ox7LJuBVOF9kr5whb4oF/lrnwG.UuWaS','2026-08-22 12:18:40',0,1,'2026-08-22 06:43:40'),(43,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$CU8T4R03.qYBHFDTSTlRpuEovc9vxL1nnzqo6vnBR/cgE0YdTY646','2026-08-22 12:25:19',0,1,'2026-08-22 06:50:19'),(44,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$GaU2n/j9Z6sEqzclORu5HuloA1NxYfUPZtr7dsw1SUtBdAUFfJyU6','2026-08-22 12:28:08',0,1,'2026-08-22 06:53:08'),(45,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$AffyNfWoAtgaAmrixtZOU.erQN2GZ5G8ALwp8y7NY9BT0iDIHk7Ya','2026-08-22 12:31:08',0,1,'2026-08-22 06:56:08'),(46,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$C4LdnRnTKCbk3k/DPY51Ee5G0O1KHQVtJrU1mM1.Dw663M9mB4dYS','2026-08-22 12:32:30',1,1,'2026-08-22 06:57:30'),(47,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$h.Rr4qv/Kg8rWpOTZ2X8FeKrayt6jaWNiWPxL7F.Q1HkNxhGJ.Ele','2026-08-22 12:40:19',0,1,'2026-08-22 07:05:19'),(48,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$.hU.gsMu35GrcK.LNBtVIeK3xPjednE1EGt3NocB7APVGXvkkff8G','2026-08-22 12:41:27',0,1,'2026-08-22 07:06:27'),(49,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$IcHNTplrE/oqs8IGUZcF5eEfnsgSd6qKVgbjM4r7/z2xn64EgYW0e','2026-08-22 12:45:56',0,1,'2026-08-22 07:10:56'),(50,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$S4VwljKWmsN/9xHZyqSmj.SJx.WQWCBqy5lAfauHvV/cyCJRw6Z.K','2026-08-22 12:47:32',0,1,'2026-08-22 07:12:32'),(51,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$PmxI28hBc8024fzz1exYquRGqGbJd5vzkFIwjOFHq.50wMIQYREce','2026-08-22 12:48:33',0,1,'2026-08-22 07:13:33'),(52,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$j/IlixLhHqNg93aF9wmgw.ZTrxbbekD2OxacnhI514Tj1eBhVqra2','2026-08-22 12:54:32',0,1,'2026-08-22 07:19:32'),(53,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$0ngTqIiyAu5Sg6Q1Jeag2.LIaa/ilZPdbsjH1jIiZnLsNzgGPu9Au','2026-08-22 12:57:38',0,1,'2026-08-22 07:22:38'),(54,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$l/hUWOzAiqH9pt/W2NzeF.Ef22xM3ZkiHb4UiwZ4RfGNQe8kh7CCe','2026-08-22 12:58:43',0,1,'2026-08-22 07:23:43'),(55,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$R02lDzpRX3Mz96oiBKBgW.JDpBfCmzgtHyTP/IANLqsfxr//8HmIe','2026-08-22 13:00:01',0,1,'2026-08-22 07:25:01'),(56,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$1JyNBR6gLuSnCLIt5IuCpup1UE/XwN2Pf7wFGVMxxrfJZ8/Jb2QOS','2026-08-22 13:07:08',0,1,'2026-08-22 07:32:08'),(57,'22mcan028',0,'ravivishnoi57@gmail.com','$2y$10$JA/bouShWZVUW3vHDKG9Q.U4DajLmvklVlhq1ggfnZgOsDcRMhdH6','2026-08-24 13:23:56',0,1,'2026-08-24 07:48:56');
/*!40000 ALTER TABLE `otp_verifications` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-24 15:20:29
