-- =====================================================================
--  BALITECH Pvt. Ltd. - IT Inventory Management System
--  Full MySQL database dump (schema + sample data)
--  File: balitech_inventory.sql
--  MySQL 5.7+ / MariaDB 10.4+  |  Engine: InnoDB  |  Charset: utf8mb4
-- =====================================================================
--  IMPORTANT
--  1. Import this file in phpMyAdmin (or: mysql -u root -p < balitech_inventory.sql)
--  2. Then open http://localhost/balitech_inventory/setup.php ONCE to create the
--     default user accounts with properly hashed passwords, then delete setup.php
--     or simply press "Lock setup" (it writes a lock file).
--     Default accounts (created by setup.php):
--       superadmin / Admin@123   -> Super Admin
--       itadmin    / Admin@123   -> IT Admin
--       branchstaff/ Admin@123   -> Branch IT Staff  (Lahore Branch)
--       deptmanager/ Admin@123   -> Department Manager (IT Department)
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `balitech_inventory`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `balitech_inventory`;

-- ---------------------------------------------------------------------
-- Table: branches
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_code` VARCHAR(20) NOT NULL,
  `branch_name` VARCHAR(120) NOT NULL,
  `city` VARCHAR(80) NOT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `branch_manager` VARCHAR(120) DEFAULT NULL,
  `contact_number` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(120) DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_code` (`branch_code`),
  KEY `idx_branch_status` (`status`),
  KEY `idx_branch_name` (`branch_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: departments
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dept_code` VARCHAR(20) NOT NULL,
  `department_name` VARCHAR(120) NOT NULL,
  `branch_id` INT UNSIGNED DEFAULT NULL,
  `dept_head` VARCHAR(120) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dept_code` (`dept_code`),
  KEY `idx_dept_branch` (`branch_id`),
  KEY `idx_dept_name` (`department_name`),
  CONSTRAINT `fk_dept_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: users  (login accounts + role based access control)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('super_admin','it_admin','branch_staff','dept_manager') NOT NULL DEFAULT 'branch_staff',
  `branch_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `last_login` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_username` (`username`),
  UNIQUE KEY `uq_user_email` (`email`),
  KEY `idx_user_role` (`role`),
  KEY `idx_user_branch` (`branch_id`),
  KEY `idx_user_dept` (`department_id`),
  CONSTRAINT `fk_user_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_user_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: employees
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `emp_code` VARCHAR(20) NOT NULL,
  `employee_name` VARCHAR(120) NOT NULL,
  `branch_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `designation` VARCHAR(120) DEFAULT NULL,
  `contact_number` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(120) DEFAULT NULL,
  `joining_date` DATE DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_code` (`emp_code`),
  KEY `idx_emp_branch` (`branch_id`),
  KEY `idx_emp_dept` (`department_id`),
  KEY `idx_emp_name` (`employee_name`),
  CONSTRAINT `fk_emp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_emp_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: asset_categories  (self referencing: parent = equipment group)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_categories`;
CREATE TABLE `asset_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_code` VARCHAR(20) NOT NULL,
  `category_name` VARCHAR(120) NOT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `low_stock_threshold` INT NOT NULL DEFAULT 5,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_code` (`category_code`),
  KEY `idx_cat_parent` (`parent_id`),
  KEY `idx_cat_name` (`category_name`),
  CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `asset_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: brands
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `brands`;
CREATE TABLE `brands` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brand_name` (`brand_name`),
  KEY `idx_brand_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: inventory  (one row = one asset / unit)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE `inventory` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_code` VARCHAR(30) NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `brand_id` INT UNSIGNED DEFAULT NULL,
  `equipment_name` VARCHAR(150) NOT NULL,
  `model_number` VARCHAR(100) DEFAULT NULL,
  `serial_number` VARCHAR(100) DEFAULT NULL,
  `branch_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `assigned_employee_id` INT UNSIGNED DEFAULT NULL,
  `purchase_date` DATE DEFAULT NULL,
  `purchase_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `warranty_expiry` DATE DEFAULT NULL,
  `asset_condition` ENUM('New','Good','Fair','Poor') NOT NULL DEFAULT 'Good',
  `status` ENUM('available','assigned','under_maintenance','damaged','retired') NOT NULL DEFAULT 'available',
  `remarks` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_code` (`asset_code`),
  UNIQUE KEY `uq_asset_serial` (`serial_number`),
  KEY `idx_inv_category` (`category_id`),
  KEY `idx_inv_brand` (`brand_id`),
  KEY `idx_inv_branch` (`branch_id`),
  KEY `idx_inv_dept` (`department_id`),
  KEY `idx_inv_emp` (`assigned_employee_id`),
  KEY `idx_inv_status` (`status`),
  KEY `idx_inv_purchase` (`purchase_date`),
  KEY `idx_inv_name` (`equipment_name`),
  CONSTRAINT `fk_inv_category` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_emp` FOREIGN KEY (`assigned_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: asset_assignments
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_assignments`;
CREATE TABLE `asset_assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `branch_id` INT UNSIGNED DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `assignment_date` DATE NOT NULL,
  `assigned_by` INT UNSIGNED DEFAULT NULL,
  `expected_return_date` DATE DEFAULT NULL,
  `return_date` DATE DEFAULT NULL,
  `assignment_status` ENUM('assigned','returned','transferred') NOT NULL DEFAULT 'assigned',
  `condition_on_assign` ENUM('New','Good','Fair','Poor') NOT NULL DEFAULT 'Good',
  `condition_on_return` ENUM('New','Good','Fair','Poor') DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_asg_asset` (`asset_id`),
  KEY `idx_asg_emp` (`employee_id`),
  KEY `idx_asg_status` (`assignment_status`),
  KEY `idx_asg_date` (`assignment_date`),
  CONSTRAINT `fk_asg_asset` FOREIGN KEY (`asset_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asg_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asg_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_asg_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_asg_user` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: asset_transfers
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `asset_transfers`;
CREATE TABLE `asset_transfers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` INT UNSIGNED NOT NULL,
  `from_branch_id` INT UNSIGNED DEFAULT NULL,
  `to_branch_id` INT UNSIGNED NOT NULL,
  `from_department_id` INT UNSIGNED DEFAULT NULL,
  `to_department_id` INT UNSIGNED DEFAULT NULL,
  `transfer_date` DATE NOT NULL,
  `transferred_by` INT UNSIGNED DEFAULT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'completed',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_trf_asset` (`asset_id`),
  KEY `idx_trf_from` (`from_branch_id`),
  KEY `idx_trf_to` (`to_branch_id`),
  KEY `idx_trf_date` (`transfer_date`),
  CONSTRAINT `fk_trf_asset` FOREIGN KEY (`asset_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_trf_from` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_trf_to` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_trf_fromdept` FOREIGN KEY (`from_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_trf_todept` FOREIGN KEY (`to_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_trf_user` FOREIGN KEY (`transferred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: maintenance_records
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `maintenance_records`;
CREATE TABLE `maintenance_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` INT UNSIGNED NOT NULL,
  `issue_title` VARCHAR(150) NOT NULL,
  `issue_description` TEXT DEFAULT NULL,
  `maintenance_type` ENUM('preventive','corrective','upgrade','inspection') NOT NULL DEFAULT 'corrective',
  `technician_name` VARCHAR(120) DEFAULT NULL,
  `vendor` VARCHAR(120) DEFAULT NULL,
  `reported_date` DATE NOT NULL,
  `repair_date` DATE DEFAULT NULL,
  `cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('reported','in_progress','completed','unrepairable') NOT NULL DEFAULT 'reported',
  `equipment_condition_after` ENUM('New','Good','Fair','Poor') DEFAULT NULL,
  `reported_by` INT UNSIGNED DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mnt_asset` (`asset_id`),
  KEY `idx_mnt_status` (`status`),
  KEY `idx_mnt_date` (`reported_date`),
  CONSTRAINT `fk_mnt_asset` FOREIGN KEY (`asset_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mnt_user` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: stock_transactions
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `stock_transactions`;
CREATE TABLE `stock_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` INT UNSIGNED DEFAULT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `transaction_type` ENUM('in','out','transfer','return','adjustment') NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `branch_id` INT UNSIGNED DEFAULT NULL,
  `reference_no` VARCHAR(60) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `performed_by` INT UNSIGNED DEFAULT NULL,
  `transaction_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stk_asset` (`asset_id`),
  KEY `idx_stk_category` (`category_id`),
  KEY `idx_stk_type` (`transaction_type`),
  KEY `idx_stk_date` (`transaction_date`),
  KEY `idx_stk_branch` (`branch_id`),
  CONSTRAINT `fk_stk_asset` FOREIGN KEY (`asset_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_stk_category` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stk_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stk_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: activity_logs
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `user_name` VARCHAR(120) DEFAULT NULL,
  `module` VARCHAR(60) NOT NULL,
  `action` VARCHAR(60) NOT NULL,
  `record_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_user` (`user_id`),
  KEY `idx_log_module` (`module`),
  KEY `idx_log_date` (`created_at`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  SAMPLE DATA
-- =====================================================================

-- ------------------------------ Branches ------------------------------
INSERT INTO `branches` (`id`,`branch_code`,`branch_name`,`city`,`address`,`branch_manager`,`contact_number`,`email`,`status`) VALUES
(1,'BR-HO','Head Office','Lahore','12-C, Main Boulevard, Gulberg III, Lahore','Junaid Malik','+92-42-35761230','ho@balitech.com','active'),
(2,'BR-KHI','Karachi Branch','Karachi','Shahrah-e-Faisal, Block 6, PECHS, Karachi','Sana Ahmed','+92-21-34551122','karachi@balitech.com','active'),
(3,'BR-ISB','Islamabad Branch','Islamabad','Blue Area, Jinnah Avenue, Islamabad','Bilal Hussain','+92-51-2876540','islamabad@balitech.com','active'),
(4,'BR-FSD','Faisalabad Branch','Faisalabad','Jaranwala Road, Industrial Estate, Faisalabad','Ayesha Tariq','+92-41-8733201','faisalabad@balitech.com','active'),
(5,'BR-PSH','Peshawar Branch','Peshawar','University Road, Peshawar','Kamran Khan','+92-91-5701122','peshawar@balitech.com','active');

-- ---------------------------- Departments -----------------------------
INSERT INTO `departments` (`id`,`dept_code`,`department_name`,`branch_id`,`dept_head`,`description`,`status`) VALUES
(1,'DP-IT','IT Department',1,'Usman Sheikh','Infrastructure, support and systems','active'),
(2,'DP-SAL','Sales Department',1,'Fahad Iqbal','Retail and corporate sales','active'),
(3,'DP-HR','HR Department',1,'Nadia Rehman','Human resources and payroll','active'),
(4,'DP-ACC','Accounts Department',1,'Imran Yousaf','Finance and taxation','active'),
(5,'DP-OPS','Operations Department',2,'Hassan Raza','Daily operations management','active'),
(6,'DP-QA','Quality Assurance',2,'Sadia Nawaz','Quality audits and compliance','active'),
(7,'DP-CS','Customer Support',3,'Muhammad Adeel','Helpdesk and after sales support','active'),
(8,'DP-MGT','Management',1,'Junaid Malik','Executive management','active');

-- ------------------------------- Users --------------------------------
-- NOTE: the hash below is the well-known bcrypt hash of the word  password
--       Run setup.php once to install the documented default accounts
--       (superadmin / itadmin / branchstaff / deptmanager  -  Admin@123).
INSERT INTO `users` (`id`,`username`,`full_name`,`email`,`password`,`role`,`branch_id`,`department_id`,`phone`,`status`,`last_login`) VALUES
(1,'superadmin','Ahsan Baloch','admin@balitech.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','super_admin',1,8,'+92-300-1234567','active',NOW()),
(2,'itadmin','Usman Sheikh','it.admin@balitech.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','it_admin',1,1,'+92-301-2244668','active',NOW()),
(3,'branchstaff','Hamza Sultan','branch.staff@balitech.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','branch_staff',2,5,'+92-302-3344556','active',NOW()),
(4,'deptmanager','Fahad Iqbal','sales.manager@balitech.com','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','dept_manager',1,2,'+92-303-4455667','active',NOW());

-- ----------------------------- Employees ------------------------------
INSERT INTO `employees` (`id`,`emp_code`,`employee_name`,`branch_id`,`department_id`,`designation`,`contact_number`,`email`,`joining_date`,`status`) VALUES
(1,'EMP-0001','Ali Hassan',1,1,'System Administrator','+92-321-4500111','ali.hassan@balitech.com','2021-03-15','active'),
(2,'EMP-0002','Fatima Noor',1,2,'Sales Executive','+92-321-4500222','fatima.noor@balitech.com','2021-07-01','active'),
(3,'EMP-0003','Zeeshan Akram',1,1,'Network Engineer','+92-321-4500333','zeeshan.akram@balitech.com','2020-01-20','active'),
(4,'EMP-0004','Maryam Saeed',1,3,'HR Officer','+92-321-4500444','maryam.saeed@balitech.com','2022-02-11','active'),
(5,'EMP-0005','Bilal Anwar',1,4,'Accounts Officer','+92-321-4500555','bilal.anwar@balitech.com','2022-05-30','active'),
(6,'EMP-0006','Hira Malik',2,5,'Operations Supervisor','+92-322-4500666','hira.malik@balitech.com','2019-11-05','active'),
(7,'EMP-0007','Saad Mehmood',2,6,'QA Analyst','+92-322-4500777','saad.mehmood@balitech.com','2023-01-09','active'),
(8,'EMP-0008','Areeba Khan',2,5,'Operations Executive','+92-322-4500888','areeba.khan@balitech.com','2023-06-19','active'),
(9,'EMP-0009','Talha Javed',3,7,'Support Engineer','+92-333-4500999','talha.javed@balitech.com','2021-09-27','active'),
(10,'EMP-0010','Nimra Bashir',3,7,'Customer Support Officer','+92-333-4501010','nimra.bashir@balitech.com','2022-08-14','active'),
(11,'EMP-0011','Kashif Raza',4,1,'IT Support Officer','+92-334-4501111','kashif.raza@balitech.com','2023-03-02','active'),
(12,'EMP-0012','Sadia Nawaz',1,8,'General Manager','+92-335-4501212','sadia.nawaz@balitech.com','2018-04-01','active');

-- -------------------------- Asset Categories --------------------------
-- Level 1 = equipment groups, Level 2 = sub categories (parent_id)
INSERT INTO `asset_categories` (`id`,`category_code`,`category_name`,`parent_id`,`description`,`low_stock_threshold`,`status`) VALUES
(1,'CAT-CMP','Computers',NULL,'Computing devices',5,'active'),
(2,'CAT-MON','Monitors and LCDs',NULL,'Display devices',5,'active'),
(3,'CAT-ACC','Accessories',NULL,'IT accessories and peripherals',8,'active'),
(4,'CAT-NET','Networking Equipment',NULL,'Network hardware',5,'active'),
(5,'CAT-TLS','Networking Tools',NULL,'Installation and testing tools',3,'active'),
(6,'CAT-OTH','Other IT Equipment',NULL,'Miscellaneous IT equipment',3,'active'),
-- Computers
(7,'CAT-CMP-01','Desktop PCs',1,'Office desktop computers',5,'active'),
(8,'CAT-CMP-02','Workstations',1,'High performance workstations',2,'active'),
(9,'CAT-CMP-03','Laptops',1,'Portable computers',5,'active'),
(10,'CAT-CMP-04','CPUs',1,'System units / CPU boxes',3,'active'),
-- Monitors
(11,'CAT-MON-01','LCD Monitors',2,'Standard LCD monitors',5,'active'),
(12,'CAT-MON-02','LED Monitors',2,'LED backlit monitors',5,'active'),
(13,'CAT-MON-03','Curved Monitors',2,'Curved display panels',2,'active'),
(14,'CAT-MON-04','Monitor Accessories',2,'Stands, arms and cables',3,'active'),
-- Accessories
(15,'CAT-ACC-01','Headsets',3,'Headphones and headsets',8,'active'),
(16,'CAT-ACC-02','Keyboards',3,'Wired and wireless keyboards',8,'active'),
(17,'CAT-ACC-03','Mouse',3,'Mice and pointers',8,'active'),
(18,'CAT-ACC-04','USB Devices',3,'USB hubs, drives and dongles',8,'active'),
(19,'CAT-ACC-05','Webcams',3,'Web cameras',4,'active'),
(20,'CAT-ACC-06','Speakers',3,'Speakers and sound bars',4,'active'),
(21,'CAT-ACC-07','Chargers',3,'Power adapters and chargers',6,'active'),
(22,'CAT-ACC-08','Docking Stations',3,'Laptop docking stations',4,'active'),
-- Networking Equipment
(23,'CAT-NET-01','Routers',4,'Edge and core routers',3,'active'),
(24,'CAT-NET-02','Switches',4,'Access and distribution switches',4,'active'),
(25,'CAT-NET-03','Access Points',4,'Wireless access points',4,'active'),
(26,'CAT-NET-04','Firewalls',4,'Security appliances',2,'active'),
(27,'CAT-NET-05','LAN Cables',4,'Cat5e / Cat6 patch cables',20,'active'),
(28,'CAT-NET-06','Fiber Optic Cables',4,'Fiber patch and trunk cables',10,'active'),
(29,'CAT-NET-07','SFP Modules',4,'Transceiver modules',6,'active'),
(30,'CAT-NET-08','Patch Panels',4,'Cat6 patch panels',3,'active'),
(31,'CAT-NET-09','Network Racks',4,'Server and network racks',2,'active'),
(32,'CAT-NET-10','Network Adapters',4,'NICs and USB adapters',5,'active'),
(33,'CAT-NET-11','Wi-Fi Devices',4,'Wi-Fi bridges and extenders',4,'active'),
-- Networking Tools
(34,'CAT-TLS-01','LAN Testers',5,'Cable testers',2,'active'),
(35,'CAT-TLS-02','Cable Crimping Tools',5,'RJ45 crimping tools',3,'active'),
(36,'CAT-TLS-03','Punch Down Tools',5,'IDC punch down tools',3,'active'),
(37,'CAT-TLS-04','Fiber Testing Tools',5,'OTDR and power meters',2,'active'),
(38,'CAT-TLS-05','Network Cable Strippers',5,'Cable stripping tools',3,'active'),
(39,'CAT-TLS-06','Screwdriver Sets',5,'Precision screwdriver sets',4,'active'),
(40,'CAT-TLS-07','Network Installation Tools',5,'Complete toolkits',2,'active'),
-- Other IT Equipment
(41,'CAT-OTH-01','Printers',6,'Laser and inkjet printers',2,'active'),
(42,'CAT-OTH-02','Scanners',6,'Document scanners',2,'active'),
(43,'CAT-OTH-03','UPS',6,'Uninterruptible power supplies',3,'active'),
(44,'CAT-OTH-04','Servers',6,'Rack and tower servers',2,'active'),
(45,'CAT-OTH-05','Projectors',6,'Projection systems',2,'active'),
(46,'CAT-OTH-06','CCTV Equipment',6,'Cameras and NVRs',4,'active'),
(47,'CAT-OTH-07','Other IT Accessories',6,'Miscellaneous IT items',5,'active');

-- -------------------------------- Brands ------------------------------
INSERT INTO `brands` (`id`,`brand_name`,`description`,`status`) VALUES
(1,'Dell','Dell Technologies','active'),
(2,'HP','Hewlett Packard','active'),
(3,'Lenovo','Lenovo Group','active'),
(4,'Asus','ASUSTeK Computer','active'),
(5,'Acer','Acer Inc.','active'),
(6,'Apple','Apple Inc.','active'),
(7,'Samsung','Samsung Electronics','active'),
(8,'LG','LG Electronics','active'),
(9,'Logitech','Logitech International','active'),
(10,'TP-Link','TP-Link Technologies','active'),
(11,'Cisco','Cisco Systems','active'),
(12,'D-Link','D-Link Corporation','active'),
(13,'Netgear','NETGEAR Inc.','active'),
(14,'APC','APC by Schneider Electric','active'),
(15,'Epson','Seiko Epson Corporation','active'),
(16,'Canon','Canon Inc.','active'),
(17,'Ubiquiti','Ubiquiti Networks','active'),
(18,'Kingston','Kingston Technology','active'),
(19,'Jabra','GN Audio','active'),
(20,'Hikvision','Hikvision Digital Technology','active');

-- ------------------------------ Inventory -----------------------------
-- codes: BLT-00001 .. (asset_code is generated by the application)
INSERT INTO `inventory` (`id`,`asset_code`,`category_id`,`brand_id`,`equipment_name`,`model_number`,`serial_number`,`branch_id`,`department_id`,`assigned_employee_id`,`purchase_date`,`purchase_price`,`warranty_expiry`,`asset_condition`,`status`,`remarks`,`created_by`,`created_at`) VALUES
(1,'BLT-00001',9,1,'Dell Latitude 5440 Laptop','LAT-5440','DL5440-0001',1,1,1,'2023-04-10',285000.00,'2026-04-10','Good','assigned','Issued to system administrator',2,'2023-04-11 09:20:00'),
(2,'BLT-00002',9,2,'HP ProBook 450 G9','PB450G9','HP450-0002',1,1,3,'2023-05-02',268000.00,'2026-05-02','Good','assigned','Network engineer laptop',2,'2023-05-03 10:05:00'),
(3,'BLT-00003',9,3,'Lenovo ThinkPad E14','TP-E14','LN14-0003',2,5,6,'2023-06-15',245000.00,'2026-06-15','Good','assigned','Operations supervisor',3,'2023-06-16 11:30:00'),
(4,'BLT-00004',7,1,'Dell OptiPlex 7010 Desktop','OPT-7010','DL7010-0004',1,2,2,'2022-11-08',168000.00,'2025-11-08','Fair','assigned','Sales counter desktop',1,'2022-11-09 09:00:00'),
(5,'BLT-00005',7,2,'HP ProDesk 400 G7','PD400G7','HP400G7-0005',1,3,4,'2023-01-19',155000.00,'2026-01-19','Good','assigned','HR office desktop',1,'2023-01-20 09:45:00'),
(6,'BLT-00006',7,3,'Lenovo ThinkCentre M70','TC-M70','LN70-0006',1,4,5,'2023-02-27',152000.00,'2026-02-27','Good','assigned','Accounts desktop',1,'2023-02-28 10:15:00'),
(7,'BLT-00007',8,1,'Dell Precision 3660 Workstation','PRC-3660','DL3660-0007',1,1,NULL,'2023-08-05',545000.00,'2026-08-05','New','available','Reserved for design team',2,'2023-08-06 12:00:00'),
(8,'BLT-00008',8,2,'HP Z2 Tower G9','Z2G9','HPZ2-0008',3,7,9,'2023-09-11',512000.00,'2026-09-11','Good','assigned','Support engineer workstation',2,'2023-09-12 09:35:00'),
(9,'BLT-00009',11,7,'Samsung 22" LCD Monitor','S22-LCD','SM22-0009',1,1,1,'2022-10-14',42000.00,'2025-10-14','Good','assigned','Attached to BLT-00001',2,'2022-10-15 09:10:00'),
(10,'BLT-00010',11,8,'LG 22MP410 LCD Monitor','LG22MP','LG22-0010',1,2,2,'2022-10-14',39500.00,'2025-10-14','Good','assigned','Sales counter monitor',2,'2022-10-15 09:12:00'),
(11,'BLT-00011',12,1,'Dell P2422H LED Monitor','P2422H','DL24-0011',1,1,NULL,'2023-03-21',56000.00,'2026-03-21','New','available','Spare monitor in store',2,'2023-03-22 10:00:00'),
(12,'BLT-00012',12,2,'HP M24f LED Monitor','M24F','HPM24-0012',2,5,6,'2023-03-21',48500.00,'2026-03-21','Good','assigned','Karachi ops monitor',3,'2023-03-23 11:20:00'),
(13,'BLT-00013',13,7,'Samsung Odyssey G5 Curved Monitor','G5-C32','SMG5-0013',1,8,NULL,'2023-12-04',118000.00,'2025-12-04','Good','under_maintenance','Display flicker reported',2,'2023-12-05 14:00:00'),
(14,'BLT-00014',13,8,'LG UltraWide 34" Curved','LG34W','LG34-0014',1,1,NULL,'2024-01-16',165000.00,'2027-01-16','New','available','For development bench',2,'2024-01-17 09:40:00'),
(15,'BLT-00015',15,19,'Jabra Evolve2 40 Headset','EV2-40','JB40-0015',1,7,4,'2023-07-08',38000.00,'2025-07-08','Good','assigned','HR calling headset',2,'2023-07-09 10:30:00'),
(16,'BLT-00016',15,19,'Jabra Evolve2 65 Wireless Headset','EV2-65','JB65-0016',3,7,10,'2023-10-02',52000.00,'2026-10-02','Good','assigned','Customer support headset',2,'2023-10-03 11:15:00'),
(17,'BLT-00017',15,9,'Logitech H390 USB Headset','H390','LG390-0017',1,1,NULL,'2024-02-19',9800.00,'2026-02-19','New','available','Spare headsets (store)',2,'2024-02-20 09:25:00'),
(18,'BLT-00018',16,9,'Logitech K120 Keyboard','K120','LGK120-0018',1,2,2,'2023-01-11',2400.00,'2025-01-11','Good','assigned','Sales desk keyboard',2,'2023-01-12 09:05:00'),
(19,'BLT-00019',16,2,'HP 320K Wired Keyboard','320K','HP320K-0019',1,4,5,'2023-01-11',2900.00,'2025-01-11','Fair','assigned','Accounts keyboard',2,'2023-01-12 09:06:00'),
(20,'BLT-00020',17,9,'Logitech M90 Mouse','M90','LGM90-0020',1,2,2,'2023-01-11',1600.00,'2025-01-11','Good','assigned','Sales desk mouse',2,'2023-01-12 09:07:00'),
(21,'BLT-00021',17,9,'Logitech MX Master 3S Mouse','MX3S','LGMX3S-0021',1,1,1,'2023-11-27',24500.00,'2025-11-27','Good','assigned','Admin mouse',2,'2023-11-28 10:45:00'),
(22,'BLT-00022',18,18,'Kingston 64GB USB 3.2 Flash Drive','DTX-64','KNG64-0022',1,1,NULL,'2024-03-04',3500.00,'2027-03-04','New','available','IT store stock',2,'2024-03-05 09:15:00'),
(23,'BLT-00023',18,18,'Kingston 128GB USB Flash Drive','DTX-128','KNG128-0023',2,5,NULL,'2024-03-04',5200.00,'2027-03-04','New','available','Karachi store stock',3,'2024-03-05 09:18:00'),
(24,'BLT-00024',19,9,'Logitech C920 Pro Webcam','C920','LGC920-0024',1,8,NULL,'2023-09-18',21500.00,'2025-09-18','Good','available','Board room webcam',2,'2023-09-19 11:00:00'),
(25,'BLT-00025',20,9,'Logitech Z120 Speakers','Z120','LGZ120-0025',1,8,NULL,'2023-09-18',6500.00,'2025-09-18','Good','available','Board room speakers',2,'2023-09-19 11:02:00'),
(26,'BLT-00026',21,2,'HP 65W USB-C Charger','HP65W','HP65W-0026',1,1,NULL,'2024-01-08',7800.00,'2026-01-08','New','available','Spare chargers',2,'2024-01-09 09:30:00'),
(27,'BLT-00027',22,1,'Dell WD19S Docking Station','WD19S','DLWD19-0027',1,1,3,'2023-05-02',68000.00,'2026-05-02','Good','assigned','Paired with BLT-00002',2,'2023-05-03 10:10:00'),
(28,'BLT-00028',23,11,'Cisco RV340 Router','RV340','CS340-0028',1,1,NULL,'2022-08-22',145000.00,'2025-08-22','Good','available','Core router head office',2,'2022-08-23 09:00:00'),
(29,'BLT-00029',23,10,'TP-Link ER605 Router','ER605','TP605-0029',2,5,NULL,'2023-02-13',32000.00,'2026-02-13','Good','available','Branch backup router',3,'2023-02-14 09:20:00'),
(30,'BLT-00030',24,11,'Cisco Catalyst 2960X 24-Port Switch','C2960X','CS2960-0030',1,1,NULL,'2022-08-22',385000.00,'2025-08-22','Good','available','Head office access switch',2,'2022-08-23 09:05:00'),
(31,'BLT-00031',24,10,'TP-Link TL-SG1024D Switch','SG1024D','TPSG1024-0031',2,5,NULL,'2023-02-13',34000.00,'2026-02-13','Good','available','Karachi branch switch',3,'2023-02-14 09:25:00'),
(32,'BLT-00032',24,13,'Netgear GS308 8-Port Switch','GS308','NG308-0032',4,1,NULL,'2023-05-30',18500.00,'2026-05-30','Good','available','Faisalabad switch',3,'2023-05-31 10:00:00'),
(33,'BLT-00033',25,17,'Ubiquiti UniFi U6-Lite Access Point','U6-LITE','UBU6-0033',1,1,NULL,'2023-06-22',29000.00,'2026-06-22','Good','available','2nd floor Wi-Fi',2,'2023-06-23 11:40:00'),
(34,'BLT-00034',25,17,'Ubiquiti UniFi U6-Pro Access Point','U6-PRO','UBU6P-0034',3,7,NULL,'2023-06-22',42000.00,'2026-06-22','Good','available','Islamabad Wi-Fi',2,'2023-06-23 11:42:00'),
(35,'BLT-00035',26,11,'Cisco ASA 5506-X Firewall','ASA5506','CSASA-0035',1,1,NULL,'2022-08-22',465000.00,'2025-08-22','Good','available','Perimeter firewall',2,'2022-08-23 09:10:00'),
(36,'BLT-00036',27,12,'D-Link Cat6 LAN Cable 305m Box','DL-CAT6-305','DLC6-0036',1,1,NULL,'2024-02-06',38000.00,'2027-02-06','New','available','Store consumable',2,'2024-02-07 09:50:00'),
(37,'BLT-00037',28,12,'D-Link LC-LC Fiber Patch Cable 3m','DL-FIB3','DLF3-0037',1,1,NULL,'2024-02-06',8500.00,'2027-02-06','New','available','Store consumable',2,'2024-02-07 09:52:00'),
(38,'BLT-00038',29,11,'Cisco SFP-10G-SR Module','SFP10GSR','CSSFP-0038',1,1,NULL,'2023-07-19',68000.00,'2026-07-19','Good','available','Spare SFP module',2,'2023-07-20 10:20:00'),
(39,'BLT-00039',30,12,'D-Link 24-Port Cat6 Patch Panel','DL-PP24','DLPP24-0039',1,1,NULL,'2023-07-19',26500.00,'2026-07-19','Good','available','Rack patch panel',2,'2023-07-20 10:22:00'),
(40,'BLT-00040',31,12,'D-Link 22U Network Rack','DL-RACK22','DLRK22-0040',1,1,NULL,'2023-07-19',115000.00,'2026-07-19','Good','available','Server room rack',2,'2023-07-20 10:25:00'),
(41,'BLT-00041',32,18,'Kingston 1G PCIe Network Adapter','NIC-1G','KNNIC-0041',1,1,NULL,'2024-01-24',9800.00,'2027-01-24','New','available','Spare NIC',2,'2024-01-25 09:35:00'),
(42,'BLT-00042',33,17,'Ubiquiti UniFi UAP-AC-M Wi-Fi Extender','UAP-ACM','UBACM-0042',4,1,NULL,'2024-01-24',24500.00,'2027-01-24','New','available','Warehouse Wi-Fi',2,'2024-01-25 09:38:00'),
(43,'BLT-00043',34,12,'D-Link LAN Tester','DL-LT1','DLLT1-0043',1,1,NULL,'2023-04-05',12500.00,'2025-04-05','Good','available','IT toolkit',2,'2023-04-06 10:50:00'),
(44,'BLT-00044',35,9,'Logitech RJ45 Crimping Tool','LG-CRIMP','LGCR-0044',1,1,NULL,'2023-04-05',4800.00,'2025-04-05','Good','available','IT toolkit',2,'2023-04-06 10:52:00'),
(45,'BLT-00045',36,12,'D-Link Punch Down Tool','DL-PDT','DLPDT-0045',2,5,NULL,'2023-04-05',5600.00,'2025-04-05','Good','available','Karachi toolkit',3,'2023-04-07 11:00:00'),
(46,'BLT-00046',37,12,'D-Link Fiber Optic Power Meter','DL-FPM','DLFPM-0046',1,1,NULL,'2023-08-14',42000.00,'2025-08-14','Good','available','Fiber testing kit',2,'2023-08-15 09:45:00'),
(47,'BLT-00047',38,9,'Cable Stripper Tool','CS-100','CS100-0047',1,1,NULL,'2023-08-14',3200.00,'2025-08-14','Good','available','IT toolkit',2,'2023-08-15 09:47:00'),
(48,'BLT-00048',39,9,'Precision Screwdriver Set 32pcs','SD-32','SD32-0048',1,1,NULL,'2023-08-14',4500.00,'2025-08-14','Good','available','IT toolkit',2,'2023-08-15 09:49:00'),
(49,'BLT-00049',40,12,'Network Installation Toolkit','NIT-KIT','NITK-0049',3,7,NULL,'2023-09-25',38000.00,'2026-09-25','Good','available','Islamabad toolkit',2,'2023-09-26 10:30:00'),
(50,'BLT-00050',41,2,'HP LaserJet Pro M404dn Printer','M404DN','HPM404-0050',1,3,NULL,'2023-03-08',95000.00,'2026-03-08','Good','available','HR printer',2,'2023-03-09 09:15:00'),
(51,'BLT-00051',41,16,'Canon imageCLASS MF3010 Printer','MF3010','CN3010-0051',2,5,NULL,'2023-04-17',62000.00,'2026-04-17','Fair','damaged','Paper jam / fuser fault',3,'2023-04-18 10:05:00'),
(52,'BLT-00052',42,15,'Epson DS-1630 Document Scanner','DS1630','EP1630-0052',1,4,NULL,'2023-05-09',78000.00,'2026-05-09','Good','available','Accounts scanner',2,'2023-05-10 09:30:00'),
(53,'BLT-00053',43,14,'APC Smart-UPS 1500VA','SUA1500','APC1500-0053',1,1,NULL,'2022-09-13',145000.00,'2025-09-13','Good','available','Server room UPS',2,'2022-09-14 09:00:00'),
(54,'BLT-00054',43,14,'APC Back-UPS 650VA','BX650','APC650-0054',2,5,NULL,'2023-02-28',32000.00,'2026-02-28','Good','available','Karachi branch UPS',3,'2023-03-01 09:20:00'),
(55,'BLT-00055',44,1,'Dell PowerEdge R450 Server','R450','DLR450-0055',1,1,NULL,'2023-06-01',985000.00,'2026-06-01','Good','available','Primary application server',2,'2023-06-02 09:00:00'),
(56,'BLT-00056',44,2,'HP ProLiant DL360 Gen10 Server','DL360G10','HPDL360-0056',1,1,NULL,'2023-06-01',1150000.00,'2026-06-01','Good','under_maintenance','RAID controller alert',2,'2023-06-02 09:05:00'),
(57,'BLT-00057',45,15,'Epson EB-X06 Projector','EB-X06','EPX06-0057',1,8,NULL,'2023-10-11',135000.00,'2026-10-11','Good','available','Board room projector',2,'2023-10-12 10:15:00'),
(58,'BLT-00058',46,20,'Hikvision 4MP IP Dome Camera','DS-2CD2143','HK2143-0058',1,1,NULL,'2023-11-20',22000.00,'2026-11-20','New','available','Entrance CCTV',2,'2023-11-21 09:40:00'),
(59,'BLT-00059',46,20,'Hikvision 8-Channel NVR','DS-7608','HK7608-0059',1,1,NULL,'2023-11-20',85000.00,'2026-11-20','Good','available','CCTV recording unit',2,'2023-11-21 09:42:00'),
(60,'BLT-00060',47,9,'Logitech Wireless Presenter R400','R400','LGR400-0060',1,8,NULL,'2024-02-27',11000.00,'2027-02-27','New','available','Presentation remote',2,'2024-02-28 09:55:00');

-- Lifecycle adjustments: end-of-life items are retired
UPDATE `inventory` SET `status` = 'retired', `asset_condition` = 'Poor', `remarks` = 'End of life - retired from service'
 WHERE `asset_code` IN ('BLT-0043','BLT-0044');
UPDATE `inventory` SET `status` = 'retired' WHERE `asset_code` = 'BLT-0048';

-- --------------------------- Asset Assignments ------------------------
INSERT INTO `asset_assignments` (`id`,`asset_id`,`employee_id`,`branch_id`,`department_id`,`assignment_date`,`assigned_by`,`expected_return_date`,`return_date`,`assignment_status`,`condition_on_assign`,`condition_on_return`,`remarks`) VALUES
(1,1,1,1,1,'2023-04-12',2,NULL,NULL,'assigned','Good',NULL,'Primary work laptop'),
(2,2,3,1,1,'2023-05-04',2,NULL,NULL,'assigned','Good',NULL,'Includes docking station BLT-00027'),
(3,3,6,2,5,'2023-06-17',3,NULL,NULL,'assigned','Good',NULL,'Karachi operations laptop'),
(4,4,2,1,2,'2022-11-10',1,NULL,NULL,'assigned','New',NULL,'Sales counter desktop'),
(5,5,4,1,3,'2023-01-21',1,NULL,NULL,'assigned','New',NULL,'HR desktop'),
(6,6,5,1,4,'2023-03-01',1,NULL,NULL,'assigned','New',NULL,'Accounts desktop'),
(7,8,9,3,7,'2023-09-13',2,NULL,NULL,'assigned','New',NULL,'Support workstation'),
(8,9,1,1,1,'2022-10-16',2,NULL,NULL,'assigned','New',NULL,'Attached to laptop'),
(9,10,2,1,2,'2022-10-16',2,NULL,NULL,'assigned','New',NULL,'Sales counter monitor'),
(10,12,6,2,5,'2023-03-24',3,NULL,NULL,'assigned','New',NULL,'Karachi ops monitor'),
(11,15,4,1,3,'2023-07-10',2,NULL,NULL,'assigned','New',NULL,'HR headset'),
(12,16,10,3,7,'2023-10-04',2,NULL,NULL,'assigned','New',NULL,'Support headset'),
(13,18,2,1,2,'2023-01-13',2,NULL,NULL,'assigned','New',NULL,'Sales keyboard'),
(14,19,5,1,4,'2023-01-13',2,NULL,NULL,'assigned','New',NULL,'Accounts keyboard'),
(15,20,2,1,2,'2023-01-13',2,NULL,NULL,'assigned','New',NULL,'Sales mouse'),
(16,21,1,1,1,'2023-11-29',2,NULL,NULL,'assigned','New',NULL,'Admin mouse'),
(17,27,3,1,1,'2023-05-04',2,NULL,NULL,'assigned','New',NULL,'Docking station'),
(18,25,3,1,1,'2023-10-01',2,'2023-11-01','2023-10-30','returned','Good','Good','Temporary loan for training session'),
(19,24,12,1,8,'2023-12-20',2,'2024-01-20','2024-01-18','returned','Good','Good','Annual meeting loan'),
(20,31,6,2,5,'2022-12-01',3,'2023-06-01','2023-05-28','returned','Good','Fair','Old switch returned to store');

-- ---------------------------- Asset Transfers -------------------------
INSERT INTO `asset_transfers` (`id`,`asset_id`,`from_branch_id`,`to_branch_id`,`from_department_id`,`to_department_id`,`transfer_date`,`transferred_by`,`reason`,`status`) VALUES
(1,32,1,4,1,1,'2023-05-31',2,'New branch network setup','completed'),
(2,42,1,4,1,1,'2024-01-26',2,'Warehouse Wi-Fi coverage','completed'),
(3,45,1,2,1,5,'2023-04-07',2,'Toolkit for Karachi branch','completed'),
(4,49,1,3,1,7,'2023-09-26',2,'Islamabad installation project','completed'),
(5,34,1,3,1,7,'2023-06-24',2,'Branch Wi-Fi upgrade','completed'),
(6,11,1,2,1,5,'2024-03-01',2,'Requested by Karachi branch','pending');

-- -------------------------- Maintenance Records -----------------------
INSERT INTO `maintenance_records` (`id`,`asset_id`,`issue_title`,`issue_description`,`maintenance_type`,`technician_name`,`vendor`,`reported_date`,`repair_date`,`cost`,`status`,`equipment_condition_after`,`reported_by`,`remarks`) VALUES
(1,13,'Display flickering','Screen flickers at 60Hz after 30 minutes of use.','corrective','Imran Ali','Samsung Service Center','2024-02-12','2024-02-20',18500.00,'completed','Good',2,'Panel cable replaced'),
(2,56,'RAID controller alert','Server reports degraded RAID array, battery backup failure.','corrective','Waqas Ahmed','HP Enterprise Support','2024-03-05',NULL,0.00,'in_progress',NULL,2,'Spare controller ordered'),
(3,51,'Paper jam and fuser error','Repeated paper jams and fuser unit error code E100.','corrective','Atif Mehmood','Canon Service','2024-01-30','2024-02-06',12500.00,'unrepairable','Poor',3,'Fuser damage beyond economical repair'),
(4,19,'Keys not working','Several keys unresponsive on the keyboard.','corrective','IT Support Team',NULL,'2024-02-18','2024-02-18',0.00,'completed','Good',2,'Keyboard cleaned and returned'),
(5,53,'Battery replacement','UPS battery backup reduced to 5 minutes.','preventive','Adnan Sheikh','APC Service Partner','2024-02-25',NULL,0.00,'reported',NULL,2,'Battery replacement quote awaited'),
(6,43,'Cable tester calibration','Annual calibration of LAN tester.','inspection','IT Support Team',NULL,'2024-03-01','2024-03-01',0.00,'completed','Good',2,'Calibrated successfully'),
(7,59,'NVR storage warning','Hard disk health warning on NVR.','corrective','Usman Ali','Hikvision Dealer','2024-03-10',NULL,0.00,'reported',NULL,2,'Awaiting replacement disk'),
(8,1,'Battery health degraded','Laptop battery drains quickly, replacement recommended.','preventive','IT Support Team',NULL,'2024-03-12',NULL,0.00,'reported',NULL,2,'Quotation requested');

-- --------------------------- Stock Transactions -----------------------
INSERT INTO `stock_transactions` (`id`,`asset_id`,`category_id`,`transaction_type`,`quantity`,`branch_id`,`reference_no`,`notes`,`performed_by`,`transaction_date`) VALUES
(1,1,9,'in',1,1,'GRN-2023-001','Laptop received from vendor',2,'2023-04-10'),
(2,2,9,'in',1,1,'GRN-2023-002','Laptop received from vendor',2,'2023-05-02'),
(3,3,9,'in',1,2,'GRN-2023-003','Laptop received at Karachi',3,'2023-06-15'),
(4,7,8,'in',1,1,'GRN-2023-004','Workstation received',2,'2023-08-05'),
(5,11,12,'in',3,1,'GRN-2023-005','Monitors received (3 units)',2,'2023-03-21'),
(6,17,15,'in',10,1,'GRN-2024-006','Headsets bulk purchase',2,'2024-02-19'),
(7,22,18,'in',25,1,'GRN-2024-007','USB drives bulk purchase',2,'2024-03-04'),
(8,22,18,'out',5,1,'ISS-2024-008','Issued to HR department',2,'2024-03-06'),
(9,1,9,'out',1,1,'ASG-2024-009','Assigned to employee EMP-0001',2,'2023-04-12'),
(10,3,9,'out',1,2,'ASG-2024-010','Assigned to employee EMP-0006',3,'2023-06-17'),
(11,32,24,'transfer',1,4,'TRF-2024-011','Transferred to Faisalabad branch',2,'2023-05-31'),
(12,45,36,'transfer',1,2,'TRF-2024-012','Toolkit transferred to Karachi',2,'2023-04-07'),
(13,25,20,'return',1,1,'RET-2023-013','Speakers returned by employee',2,'2023-10-30'),
(14,31,24,'return',1,2,'RET-2024-014','Switch returned to store',3,'2023-05-28'),
(15,51,41,'adjustment',1,2,'ADJ-2024-015','Marked damaged - awaiting disposal',3,'2024-02-07');

-- ----------------------------- Activity Logs --------------------------
INSERT INTO `activity_logs` (`id`,`user_id`,`user_name`,`module`,`action`,`record_id`,`description`,`ip_address`,`created_at`) VALUES
(1,2,'Usman Sheikh','Inventory','create',1,'Added asset BLT-00001 - Dell Latitude 5440 Laptop','127.0.0.1','2023-04-11 09:20:00'),
(2,2,'Usman Sheikh','Assignment','create',1,'Assigned BLT-00001 to Ali Hassan (EMP-0001)','127.0.0.1','2023-04-12 09:30:00'),
(3,3,'Hamza Sultan','Inventory','create',3,'Added asset BLT-00003 - Lenovo ThinkPad E14','127.0.0.1','2023-06-16 11:30:00'),
(4,2,'Usman Sheikh','Maintenance','create',1,'Reported fault on BLT-00013','127.0.0.1','2024-02-12 10:10:00'),
(5,2,'Usman Sheikh','Maintenance','update',1,'Maintenance completed on BLT-00013 - cost 18,500','127.0.0.1','2024-02-20 15:40:00'),
(6,2,'Usman Sheikh','Stock','create',7,'Stock in: 25 USB drives','127.0.0.1','2024-03-04 09:15:00'),
(7,2,'Usman Sheikh','Inventory','update',56,'Status changed to under_maintenance for BLT-00056','127.0.0.1','2024-03-05 09:45:00'),
(8,3,'Hamza Sultan','Inventory','update',51,'Marked BLT-00051 as damaged','127.0.0.1','2024-02-07 09:50:00'),
(9,1,'Ahsan Baloch','User','create',2,'Created IT Admin account','127.0.0.1','2024-03-01 11:00:00'),
(10,2,'Usman Sheikh','Transfer','create',6,'Requested transfer of BLT-00011 to Karachi Branch','127.0.0.1','2024-03-01 12:15:00');

-- =====================================================================
--  END OF FILE
-- =====================================================================
