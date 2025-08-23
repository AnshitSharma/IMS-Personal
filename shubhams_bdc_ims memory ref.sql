-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 14, 2025 at 12:38 PM
-- Server version: 10.6.20-MariaDB-cll-lve
-- PHP Version: 8.3.15

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `shubhams_bdc_ims`
--

DELIMITER $$
--
-- Procedures
--
$$

$$

$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `acl_permissions`
--

CREATE TABLE `acl_permissions` (
  `id` int(11) NOT NULL,
  `permission_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `acl_permissions`
--

INSERT INTO `acl_permissions` (`id`, `permission_name`, `description`, `category`, `created_at`) VALUES
(1, 'dashboard.view', 'View dashboard', 'dashboard', '2025-08-14 12:32:26'),
(2, 'cpu.view', 'View CPU components', 'inventory', '2025-08-14 12:32:26'),
(3, 'cpu.create', 'Create CPU components', 'inventory', '2025-08-14 12:32:26'),
(4, 'cpu.edit', 'Edit CPU components', 'inventory', '2025-08-14 12:32:26'),
(5, 'cpu.delete', 'Delete CPU components', 'inventory', '2025-08-14 12:32:26'),
(6, 'ram.view', 'View RAM components', 'inventory', '2025-08-14 12:32:26'),
(7, 'ram.create', 'Create RAM components', 'inventory', '2025-08-14 12:32:26'),
(8, 'ram.edit', 'Edit RAM components', 'inventory', '2025-08-14 12:32:26'),
(9, 'ram.delete', 'Delete RAM components', 'inventory', '2025-08-14 12:32:26'),
(10, 'storage.view', 'View storage components', 'inventory', '2025-08-14 12:32:26'),
(11, 'storage.create', 'Create storage components', 'inventory', '2025-08-14 12:32:26'),
(12, 'storage.edit', 'Edit storage components', 'inventory', '2025-08-14 12:32:26'),
(13, 'storage.delete', 'Delete storage components', 'inventory', '2025-08-14 12:32:26'),
(14, 'motherboard.view', 'View motherboard components', 'inventory', '2025-08-14 12:32:26'),
(15, 'motherboard.create', 'Create motherboard components', 'inventory', '2025-08-14 12:32:27'),
(16, 'motherboard.edit', 'Edit motherboard components', 'inventory', '2025-08-14 12:32:27'),
(17, 'motherboard.delete', 'Delete motherboard components', 'inventory', '2025-08-14 12:32:27'),
(18, 'nic.view', 'View NIC components', 'inventory', '2025-08-14 12:32:27'),
(19, 'nic.create', 'Create NIC components', 'inventory', '2025-08-14 12:32:27'),
(20, 'nic.edit', 'Edit NIC components', 'inventory', '2025-08-14 12:32:27'),
(21, 'nic.delete', 'Delete NIC components', 'inventory', '2025-08-14 12:32:27'),
(22, 'caddy.view', 'View caddy components', 'inventory', '2025-08-14 12:32:27'),
(23, 'caddy.create', 'Create caddy components', 'inventory', '2025-08-14 12:32:27'),
(24, 'caddy.edit', 'Edit caddy components', 'inventory', '2025-08-14 12:32:27'),
(25, 'caddy.delete', 'Delete caddy components', 'inventory', '2025-08-14 12:32:27'),
(26, 'server.view', 'View server configurations', 'server', '2025-08-14 12:32:27'),
(27, 'server.create', 'Create server configurations', 'server', '2025-08-14 12:32:27'),
(28, 'server.edit', 'Edit server configurations', 'server', '2025-08-14 12:32:27'),
(29, 'server.delete', 'Delete server configurations', 'server', '2025-08-14 12:32:27'),
(30, 'user.view', 'View users', 'user_management', '2025-08-14 12:32:27'),
(31, 'user.create', 'Create users', 'user_management', '2025-08-14 12:32:27'),
(32, 'user.edit', 'Edit users', 'user_management', '2025-08-14 12:32:27'),
(33, 'user.delete', 'Delete users', 'user_management', '2025-08-14 12:32:27'),
(34, 'role.view', 'View roles', 'role_management', '2025-08-14 12:32:27'),
(35, 'role.manage', 'Manage roles', 'role_management', '2025-08-14 12:32:27'),
(36, 'permission.manage', 'Manage permissions', 'role_management', '2025-08-14 12:32:27'),
(37, 'acl.manage', 'Manage ACL system', 'system', '2025-08-14 12:32:27'),
(38, 'search.use', 'Use search functionality', 'utilities', '2025-08-14 12:32:27');

-- --------------------------------------------------------

--
-- Table structure for table `acl_roles`
--

CREATE TABLE `acl_roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `acl_roles`
--

INSERT INTO `acl_roles` (`id`, `role_name`, `description`, `created_at`) VALUES
(1, 'admin', 'Administrator with full access', '2025-08-14 12:32:27'),
(2, 'user', 'Regular user with limited access', '2025-08-14 12:32:27'),
(3, 'viewer', 'Read-only access', '2025-08-14 12:32:27');

-- --------------------------------------------------------

--
-- Table structure for table `auth_tokens`
--

CREATE TABLE `auth_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(6) UNSIGNED NOT NULL,
  `token` varchar(128) NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `auth_tokens`
--

INSERT INTO `auth_tokens` (`id`, `user_id`, `token`, `created_at`, `expires_at`, `last_used_at`) VALUES
(3, 3, '7445badd338c5c63f3a2ce982acc1f8cc4ef26d5b4abbe37c2d1e5d762444943', '2025-04-09 12:46:48', '2025-04-16 12:46:48', NULL),
(4, 4, 'f9b50bb0e26411acb70bb633f704c18b16c069d70cd8cd0324b2661a5d688761', '2025-04-09 16:24:16', '2025-04-16 16:24:16', NULL),
(5, 3, '3d427aca75b42626268391237f21d5d19e03bec1dc63946bc4f984227e1d13fb', '2025-04-09 16:42:18', '2025-04-16 16:42:18', NULL),
(6, 37, '6233831cf4aa883114fb92f9c326bc8f232a385acf2b8abbbf409a3cedc9e60d', '2025-07-23 21:03:30', '2025-08-22 21:03:30', '2025-07-25 07:08:05'),
(7, 37, 'da09f665c313f56af05f611ee962946d9228ce77bbf36ee0c76bb55511219d68', '2025-07-23 21:08:08', '2025-08-22 21:08:08', '2025-07-25 07:08:05'),
(8, 37, 'f9ee7438a75bbbb617e4b96c0910cc6b0030943284a2f71d749454a36b166cc0', '2025-07-25 01:29:56', '2025-08-24 01:29:56', '2025-07-25 07:08:05'),
(9, 37, '96e349c89a9c70de802796e7e26e5689eb67d4aa0e7308e406e164c657eb58be', '2025-07-25 01:43:06', '2025-08-24 01:43:06', '2025-07-25 07:08:05'),
(10, 37, 'bd9e4734d0f83a64bd880e8baba5691ac2098d2c91e766980a1e79d5050f4bc7', '2025-07-25 01:45:19', '2025-08-24 01:45:19', '2025-07-25 07:08:05'),
(11, 37, 'ba84e78dfd2651e5568659fc8060e1187adcd3bb659df19f0121630f878ee9b8', '2025-07-25 02:41:20', '2025-08-24 02:41:20', '2025-07-25 07:08:05'),
(12, 37, 'adfbb667ea9b2cbde651bf0e1f99cb6c97c8c7a1c690fd13183b0f23cc068991', '2025-07-25 03:57:32', '2025-08-24 03:57:32', '2025-07-25 07:08:05'),
(13, 37, '5d6bbbf5e86d0b7034ddb2308c6d159a6bfcd9b206233e58147b515345ce546d', '2025-07-25 07:00:57', '2025-08-24 07:00:57', '2025-07-25 07:08:05'),
(14, 38, 'fc1332ec83801f81d262f60c8db1c515e2606d460eb6ea54568ad829513f3c35', '2025-07-25 07:06:47', '2025-08-24 07:06:47', '2025-08-06 06:23:55'),
(15, 38, '7a64424a28bb7aafb9992573f0241e431b3d08e4dc6916a2a288224db5064e45', '2025-07-25 07:12:06', '2025-08-24 07:12:06', '2025-08-06 06:23:55'),
(16, 38, 'e0ace32e255c07400534923f8b148520a195178edc6177fa6c6a6ae3b2df79dd', '2025-07-25 08:08:35', '2025-08-24 08:08:35', '2025-08-06 06:23:55'),
(17, 25, 'dfa2ff74c0b9c1a93970f365d9fc689262047710bfcd72599cee8d8d4689f2e4', '2025-07-25 14:39:09', '2025-08-24 14:39:09', NULL),
(18, 25, 'ddad6f90f21deeab493e116c8da65d854d3016be141edc67075abc6f8258c5d0', '2025-07-25 14:39:38', '2025-08-24 14:39:38', NULL),
(19, 25, '803bd0dc379ad57ef817281686ddf9282f2a64625d99096fab2a292590786e71', '2025-07-25 14:40:09', '2025-08-24 14:40:09', NULL),
(21, 38, '0926f45e7796eb5c30cdf60d9679cc9510dfe39ec1f06e2228e7b220f1858784', '2025-07-25 17:53:52', '2025-08-24 17:53:52', '2025-08-06 06:23:55'),
(22, 38, '3e41789883050c46e903f654184d555c2a5f5821cd28300c842fad32c30a0c2a', '2025-07-25 19:44:23', '2025-08-24 19:44:23', '2025-08-06 06:23:55'),
(23, 38, '4a2cd57d889db3abacffa66c938c41ff065b6b1533f3f26b6fbbeb3881ce0471', '2025-07-25 19:51:05', '2025-08-24 19:51:05', '2025-08-06 06:23:55'),
(24, 38, 'f3b7f61d1c3f334a30d4857321b305c21231ac4b966af503d55c4d53de8f8476', '2025-07-25 19:53:18', '2025-08-24 19:53:18', '2025-08-06 06:23:55'),
(25, 38, 'a370c1fbba38e7143398a1a7e5e713f6520e7ea96a564daa79b7101615ffc497', '2025-07-26 07:19:14', '2025-08-25 07:19:14', '2025-08-06 06:23:55'),
(26, 38, '4a0fa4767768ad56f7d7cf1efbec13f9b2ca6bc2a254b009792b1f712e8bddf0', '2025-07-26 07:26:34', '2025-08-25 07:26:34', '2025-08-06 06:23:55'),
(27, 38, 'e9ba6083dd669034caac47dfd83b8bd96966b5371351ff46ab8428c26e3ffceb', '2025-07-26 08:44:47', '2025-08-25 08:44:47', '2025-08-06 06:23:55'),
(28, 38, '074310954f4ae5724b4c6ef72c08e81dc7598564149c88ef4d0e8852c4b22b4a', '2025-07-26 18:02:09', '2025-08-25 18:02:09', '2025-08-06 06:23:55'),
(29, 38, '61c2c4f190ae0963ab91959a1bd9a92a586e36609e5ccb9ab7042bb5e387e179', '2025-07-26 18:06:16', '2025-08-25 18:06:16', '2025-08-06 06:23:55'),
(30, 38, '693d13ac0a403026b4c8cd77d17ede1da3977a8cdfa770f8b9c1accf9389101f', '2025-07-26 18:06:30', '2025-08-25 18:06:30', '2025-08-06 06:23:55'),
(31, 38, '33bfecef66ebceb09406f44625937f4d74492f04b4a7c1290cd4be977be0a32e', '2025-07-26 18:15:16', '2025-08-25 18:15:16', '2025-08-06 06:23:55'),
(32, 38, '14036e07e3617e54411fdf6f70f4fa890bd47031e9aa4aacd92f46d7e7c5a802', '2025-07-26 18:16:18', '2025-08-25 18:16:18', '2025-08-06 06:23:55'),
(33, 38, '57d094e02dd89e7cb53635e5d92b5267e46715b63e0a98a747cc7fd02f6a8a60', '2025-07-26 18:16:57', '2025-08-25 18:16:57', '2025-08-06 06:23:55'),
(34, 38, '543ff2b4ff448a8e429f270ded1cf136b796d91b672df266d1f83237f544d41d', '2025-07-26 18:22:43', '2025-08-25 18:22:43', '2025-08-06 06:23:55'),
(35, 38, 'd6da214df698a842a1672a9672540901e82122449972c7b414e521fe9982dae6', '2025-07-26 18:26:19', '2025-08-25 18:26:19', '2025-08-06 06:23:55'),
(36, 38, '01aaa7a8d6cc2d1159776fb41474f2b3eb91eb7291c6aea28129b99555a86ad8', '2025-07-26 18:34:34', '2025-08-25 18:34:34', '2025-08-06 06:23:55'),
(37, 38, '9a9178195c92e27149def664b58fcadffee4f70a03766cdb6efee3879a0ac8da', '2025-07-26 18:37:25', '2025-08-25 18:37:25', '2025-08-06 06:23:55'),
(38, 38, 'd22de4e7230c77a5b8a69287756a365b5617091e1fb8d1dcf3c4cc745b2fef7d', '2025-07-26 23:25:45', '2025-08-25 23:25:45', '2025-08-06 06:23:55'),
(39, 38, '41bf78c28d7480f6a54984368446419a2fe9b89100bc07221279f242007e8393', '2025-07-26 23:29:31', '2025-08-25 23:29:31', '2025-08-06 06:23:55'),
(40, 38, '02253d080aa153e9e4fd3b392b07df4214fb42ea42a2f053a325411168f5c8b7', '2025-07-26 23:42:11', '2025-08-25 23:42:11', '2025-08-06 06:23:55'),
(41, 38, 'd4fd5cfc4f8dd45b8163f9f7ef05fd94f4a05f8f7a702d226a69aa4b6b9fb73b', '2025-07-27 01:06:06', '2025-08-26 01:06:06', '2025-08-06 06:23:55'),
(42, 38, 'a58f42a4083f74dc295b7463311236aa61df80cb737ce629bccc2d2321b47edb', '2025-07-27 02:47:43', '2025-08-26 02:47:43', '2025-08-06 06:23:55'),
(43, 38, '047b7cbe797ffdc4e591779e797f257caa01b3458eaf28b212e6447e0eb482f0', '2025-07-27 02:49:31', '2025-08-26 02:49:31', '2025-08-06 06:23:55'),
(44, 38, 'ed8f1f3d7e882e9593a11a4fd9b63add9a19212725aa7f13fb9f6dcabf61140f', '2025-07-27 03:03:24', '2025-08-26 03:03:24', '2025-08-06 06:23:55'),
(45, 38, 'b906d256c9ff6e3dbc79aa4a143b400aa71bc17641c05d41766ddc90f3e54403', '2025-07-27 03:35:58', '2025-08-26 03:35:58', '2025-08-06 06:23:55'),
(46, 38, '56c79975ddc7b11a09e66fecea84a78aca9543f8087b878659b985666c0b4794', '2025-07-27 03:36:59', '2025-08-26 03:36:59', '2025-08-06 06:23:55'),
(47, 38, 'e177a2cf2073f30d57671cff89f51a3f68cd013552b5d103c7d69b3cd1dadb5d', '2025-07-27 03:39:39', '2025-08-26 03:39:39', '2025-08-06 06:23:55'),
(48, 38, '678f695b4ec89c3447cf491088d9a405602077f53235248952df2d2bbab6b0b3', '2025-07-27 03:42:38', '2025-08-26 03:42:38', '2025-08-06 06:23:55'),
(49, 38, '20024c2a9b03ccd1c3252255434e9860f0463e36878863e68e8978809b9cee71', '2025-07-27 03:48:21', '2025-08-26 03:48:21', '2025-08-06 06:23:55'),
(50, 38, '5f805d0537916874aa5e38cc9051ad204a9d8d21b993c794ca717389ff73a272', '2025-07-27 03:49:14', '2025-08-26 03:49:14', '2025-08-06 06:23:55'),
(51, 38, '8812e924da41c562d071312486693f318232027cbeaf1041ac5580a2c3cf0085', '2025-07-27 03:50:22', '2025-08-26 03:50:22', '2025-08-06 06:23:55'),
(52, 38, '9509334de41c8e596951e99fcaf551ee125c74180932e23fedef3008f05c0981', '2025-07-27 03:57:12', '2025-08-26 03:57:12', '2025-08-06 06:23:55'),
(53, 38, 'd708d959a8d65731e25da79aa4f5736e8feaab7faf524df4bb0065626bd2fe16', '2025-07-27 04:05:54', '2025-08-26 04:05:54', '2025-08-06 06:23:55'),
(54, 38, '6342ce234f5f08ee523a65b60196c4f61c2364d9b97531eb4ce7da9bdebc4dc4', '2025-07-27 04:39:56', '2025-08-26 04:39:56', '2025-08-06 06:23:55'),
(55, 38, 'bbb93a5ba5e764fce306c633d3b808b682c8a35be225433429c76fef7720165f', '2025-07-27 05:06:46', '2025-08-26 05:06:46', '2025-08-06 06:23:55'),
(56, 38, '118f6f5e2130658df4171bfb319a41d96d6dc025f6efc49859d0e1a371714091', '2025-07-27 05:14:34', '2025-08-26 05:14:34', '2025-08-06 06:23:55'),
(57, 38, '0145fe15842403e58254bb8d91460c67c039fd2334ce9ea35400051e9d82a017', '2025-07-27 07:02:04', '2025-08-26 07:02:04', '2025-08-06 06:23:55'),
(58, 38, 'f80d8fbc30973a8a456af0f4d784dd3ac80b21f8bf164e3c27f7a0bef83bb482', '2025-07-27 07:02:23', '2025-08-26 07:02:23', '2025-08-06 06:23:55'),
(59, 38, '9c95e3963b798462e42e94d8509f6b237888f36822f5ea431e47fda807a94538', '2025-07-27 08:03:02', '2025-08-26 08:03:02', '2025-08-06 06:23:55'),
(60, 38, '122d102bdf5de9de8bb3f2d33cbd010a5ed0c98568276b38cf338f4ab5cbd8c5', '2025-07-27 08:05:39', '2025-08-26 08:05:39', '2025-08-06 06:23:55'),
(61, 38, 'a1d005cfc5cece23fc12735b3a1010e398b720db17d807cd0c41f719c31f4ef5', '2025-07-27 08:37:10', '2025-08-26 08:37:10', '2025-08-06 06:23:55'),
(62, 38, 'b92105ec4ff6e5afc1c896437ff0d4f994ac7e3feb29cf7fab3f35691c9a79ee', '2025-07-27 08:55:53', '2025-08-26 08:55:53', '2025-08-06 06:23:55'),
(63, 38, '87aa31785539511101689454a7d44407293ec12de2fd399914f3936f9c968b6e', '2025-07-27 13:51:06', '2025-08-26 13:51:06', '2025-08-06 06:23:55'),
(64, 38, '863bb59c5c43e08dbbf08b6a7a493696e36b0e9b0f39c45b2443e2680fd14f70', '2025-07-27 14:05:07', '2025-08-26 14:05:07', '2025-08-06 06:23:55'),
(66, 38, 'fcff7af0783972c39a90251f41cc54df7c62e7106947f3962622fb1dd9a58d66', '2025-07-27 15:53:03', '2025-08-26 15:53:03', '2025-08-06 06:23:55'),
(67, 38, '0b5a6fd22e6d0f99ee4d58843cc2418ae1563f253f39b327fee2feb4802deb31', '2025-07-27 16:00:05', '2025-08-26 16:00:05', '2025-08-06 06:23:55'),
(68, 38, '3dbfc91331a439d2103f9785146d99fbd01e97a428b808d98d281057abad771b', '2025-07-27 20:05:29', '2025-08-26 20:05:29', '2025-08-06 06:23:55'),
(69, 38, '40af1278c67ed0abaf9167318cb805d3393380cff55544e35de8eb012add392a', '2025-07-27 20:06:04', '2025-08-26 20:06:04', '2025-08-06 06:23:55'),
(77, 38, '81d7915fa1f0d98b3fbee288c6f57516c97c1b15ce12b312b1db88050a158d62', '2025-07-28 04:44:32', '2025-08-27 04:44:32', '2025-08-06 06:23:55'),
(78, 38, '8612e24a3ce6e07ef2801951b569cdb954bc594f6f11b359d3e64cdd04ff981a', '2025-07-28 06:51:11', '2025-08-27 06:51:11', '2025-08-06 06:23:55'),
(79, 38, '8690f108544cec93d624fa3ac642298d90d654b8badea24b7c63f95406469d9f', '2025-07-29 03:21:02', '2025-08-28 03:21:02', '2025-08-06 06:23:55'),
(80, 38, 'b8a72d0e37778bd15328901487b347aac011f172451396e9916488060d98de1e', '2025-07-29 04:21:32', '2025-08-28 04:21:32', '2025-08-06 06:23:55'),
(83, 38, '461cb747cd1fc539994cac17e87384c47b5a0d0f57c10615f90c9e8d2aafe23c', '2025-07-30 01:13:00', '2025-08-29 01:13:00', '2025-08-06 06:23:55'),
(85, 38, '6e8b9d81fae3f67837a1fc6618d339ac38db80ce3b7d9f841d36e495e7d9435d', '2025-08-02 15:10:04', '2025-09-01 15:10:04', '2025-08-06 06:23:55'),
(86, 38, '900fc60e4d18c2c599138b30cc78b7d791e271dd6df7513624569bad8680cbb3', '2025-08-02 15:14:41', '2025-09-01 15:14:41', '2025-08-06 06:23:55'),
(87, 38, '8d82138f631b06b645d53d79f01779ad6b8d565cdbd8e4244270b971af61bb39', '2025-08-02 17:05:39', '2025-09-01 17:05:39', '2025-08-06 06:23:55'),
(88, 38, '8bfb6c283bd7cab6ae1df5740a189fdc10cc82ec434423abc3a5f94ecb1f0026', '2025-08-02 18:31:05', '2025-09-01 18:31:05', '2025-08-06 06:23:55'),
(89, 38, '0c0013d34f21c3eee25f2e3aef3fc289e38241489de73299ca3086e960a9575f', '2025-08-02 20:33:20', '2025-09-01 20:33:20', '2025-08-06 06:23:55'),
(90, 38, '0c7cdf3b90131692818245ca9c584231a5e24ab69c25e3ed5630408e1fed3a87', '2025-08-04 02:15:08', '2025-09-03 02:15:08', '2025-08-06 06:23:55'),
(91, 38, '8f3afea6e3d1f95acf970a726d2311e3572a97f296898a895074360817c0124c', '2025-08-04 03:42:16', '2025-09-03 03:42:16', '2025-08-06 06:23:55'),
(92, 38, '9662860765ed977edeb968792da502614a9b1f54ff97fee3f52e14343d9e7980', '2025-08-05 06:40:55', '2025-09-04 06:40:55', '2025-08-06 06:23:55'),
(93, 38, '4ef3aae48b4f2a22a6acd2ae13c9bdaa41e84f5dea2b07fe6fb126d84e1d9aa9', '2025-08-05 10:02:34', '2025-09-04 10:02:34', '2025-08-06 06:23:55'),
(94, 38, '4da585c3a54c89edfa6664e6bb1f233fb3f2722c633bda7bb6d5b26e20dcb8df', '2025-08-06 05:30:04', '2025-09-05 05:30:04', '2025-08-06 06:23:55'),
(95, 38, 'b54fa37e6941c2e0626f07047f73c060570763722f749b5ab9552bc828c19a26', '2025-08-06 05:41:18', '2025-09-05 05:41:18', '2025-08-06 06:23:55'),
(96, 38, '422fa3027e747fce13163df3abd0224ee6ea87c1b102c0dc1048d20cf815f719', '2025-08-08 10:46:27', '2025-09-07 10:46:27', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `caddyinventory`
--

CREATE TABLE `caddyinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(36) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where caddy is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `caddyinventory`
--

INSERT INTO `caddyinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, '4a8a2c05-e993-4b00-acae-9f036617091c', 'CDY123456', 2, '03b02d6f-1690-47ef-98f1-1a04bfa6e6f2', 'Datacenter North', 'Rack A3-12', '2023-05-12', '2023-06-01', '2026-05-12', 'Production', 'Dell 2.5\" SAS Drive Caddy', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(2, 'bcdce745-47ce-4deb-984d-8c3ba4b767ca', 'CDY789012', 0, NULL, 'Disposal', 'Bin 2', '2022-03-10', '2022-03-25', '2025-03-10', 'Damaged', 'HP 3.5\" SATA Drive Caddy - Damaged locking mechanism', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(3, 'bf3192bf-810e-47ea-81c6-946533aad2ca', 'CDY456789', 1, NULL, 'Warehouse Central', 'Shelf F2', '2024-03-01', NULL, '2027-03-01', 'New', 'SuperMicro 3.5\" SAS/SATA Drive Tray', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(4, '505c1ec9-35cc-4da9-b555-b7d15c0d9d06', 'CDY789082', 1, 'null', 'Himachal', 'Rack Z5', '2025-07-29', NULL, '2025-07-17', 'Critical', 'Type: 3.5 Inch\n\nAdditional Notes: new caddy', '2025-07-27 14:11:44', '2025-07-27 14:11:44');

-- --------------------------------------------------------

--
-- Table structure for table `compatibility_log`
--

CREATE TABLE `compatibility_log` (
  `id` int(11) NOT NULL,
  `session_id` varchar(64) DEFAULT NULL COMMENT 'Session identifier for grouping related operations',
  `operation_type` varchar(50) NOT NULL COMMENT 'Type of operation (check, validate, build, etc.)',
  `component_type_1` varchar(50) DEFAULT NULL,
  `component_uuid_1` varchar(36) DEFAULT NULL,
  `component_type_2` varchar(50) DEFAULT NULL,
  `component_uuid_2` varchar(36) DEFAULT NULL,
  `compatibility_result` tinyint(1) DEFAULT NULL COMMENT 'Result of compatibility check',
  `compatibility_score` decimal(3,2) DEFAULT NULL COMMENT 'Compatibility score result',
  `applied_rules` longtext DEFAULT NULL COMMENT 'JSON array of rules that were applied',
  `execution_time_ms` int(11) DEFAULT NULL COMMENT 'Execution time in milliseconds',
  `user_id` int(6) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit log for compatibility operations';

-- --------------------------------------------------------

--
-- Table structure for table `compatibility_rules`
--

CREATE TABLE `compatibility_rules` (
  `id` int(11) NOT NULL,
  `rule_name` varchar(100) NOT NULL COMMENT 'Human-readable rule name',
  `rule_type` varchar(50) NOT NULL COMMENT 'Type of rule (socket, interface, power, etc.)',
  `component_types` varchar(255) NOT NULL COMMENT 'Comma-separated list of component types this rule applies to',
  `rule_definition` longtext NOT NULL COMMENT 'JSON rule definition',
  `rule_priority` int(11) NOT NULL DEFAULT 100 COMMENT 'Rule priority (lower = higher priority)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Whether the rule is active',
  `is_override_allowed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether admin can override this rule',
  `failure_message` text DEFAULT NULL COMMENT 'Message to show when rule fails',
  `created_by` int(6) UNSIGNED DEFAULT NULL,
  `updated_by` int(6) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Business rules for component compatibility';

--
-- Dumping data for table `compatibility_rules`
--

INSERT INTO `compatibility_rules` (`id`, `rule_name`, `rule_type`, `component_types`, `rule_definition`, `rule_priority`, `is_active`, `is_override_allowed`, `failure_message`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'CPU-Motherboard Socket Match', 'socket', 'cpu,motherboard', '{\"rule\": \"socket_match\", \"description\": \"CPU and motherboard must have matching socket types\", \"validation\": {\"method\": \"json_path_match\", \"cpu_field\": \"socket.type\", \"motherboard_field\": \"socket.type\"}}', 1, 1, 0, 'CPU socket type must match motherboard socket type for compatibility', NULL, NULL, '2025-08-04 12:55:37', '2025-08-06 02:38:05'),
(2, 'Memory Type Compatibility', 'memory', 'motherboard,ram', '{\"rule\": \"memory_type_support\", \"description\": \"RAM type must be supported by motherboard\", \"validation\": {\"method\": \"array_contains\", \"motherboard_field\": \"memory.supported_types\", \"ram_field\": \"type\"}}', 2, 1, 0, 'Memory type not supported by this motherboard', NULL, NULL, '2025-08-04 12:55:37', '2025-08-06 02:38:26'),
(3, 'Memory Speed Compatibility', 'memory', 'cpu,motherboard,ram', '{\"rule\": \"memory_speed_check\", \"description\": \"Memory speed must be supported by CPU and motherboard\", \"json_path\": {\"motherboard\": \"memory.max_frequency_MHz\", \"ram\": \"frequency_mhz\"}}', 3, 1, 0, 'Memory speed exceeds CPU or motherboard limits', NULL, NULL, '2025-08-04 12:55:37', '2025-08-04 12:55:37'),
(4, 'Storage Interface Compatibility', 'interface', 'motherboard,storage', '{\"rule\": \"storage_interface_check\", \"description\": \"Storage interface must be available on motherboard\", \"json_path\": {\"motherboard\": \"storage\", \"storage\": \"interface\"}}', 4, 1, 0, 'Storage interface not available on motherboard', NULL, NULL, '2025-08-04 12:55:37', '2025-08-04 12:55:37'),
(5, 'PCIe Slot Availability', 'interface', 'motherboard,nic', '{\"rule\": \"pcie_slot_check\", \"description\": \"Sufficient PCIe slots must be available\", \"json_path\": {\"motherboard\": \"expansion_slots.pcie_slots\", \"nic\": \"pcie_requirements\"}}', 5, 1, 0, 'Insufficient PCIe slots available on motherboard', NULL, NULL, '2025-08-04 12:55:37', '2025-08-04 12:55:37'),
(6, 'Power Consumption Check', 'power', 'cpu,motherboard,ram,storage,nic', '{\"rule\": \"power_budget_check\", \"description\": \"Total power consumption within limits\", \"json_path\": {\"cpu\": \"tdp_watts\", \"ram\": \"power_consumption\", \"storage\": \"power_consumption_watts\"}}', 10, 1, 0, 'Total power consumption exceeds system limits', NULL, NULL, '2025-08-04 12:55:37', '2025-08-04 12:55:37');

-- --------------------------------------------------------

--
-- Table structure for table `component_compatibility`
--

CREATE TABLE `component_compatibility` (
  `id` int(11) NOT NULL,
  `component_type_1` varchar(50) NOT NULL COMMENT 'First component type (cpu, motherboard, ram, etc.)',
  `component_uuid_1` varchar(36) NOT NULL COMMENT 'UUID of first component',
  `component_type_2` varchar(50) NOT NULL COMMENT 'Second component type',
  `component_uuid_2` varchar(36) NOT NULL COMMENT 'UUID of second component',
  `compatibility_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=Compatible, 0=Incompatible',
  `compatibility_score` decimal(3,2) DEFAULT 1.00 COMMENT 'Compatibility score (0.00-1.00)',
  `compatibility_notes` text DEFAULT NULL COMMENT 'Additional compatibility information',
  `validation_rules` longtext DEFAULT NULL COMMENT 'JSON rules that determine compatibility',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Master compatibility matrix for all components';

-- --------------------------------------------------------

--
-- Table structure for table `component_specifications`
--

CREATE TABLE `component_specifications` (
  `id` int(11) NOT NULL,
  `component_uuid` varchar(36) NOT NULL,
  `component_type` varchar(20) NOT NULL,
  `specification_key` varchar(100) NOT NULL COMMENT 'socket_type, memory_type, form_factor, etc.',
  `specification_value` text NOT NULL,
  `data_type` varchar(20) NOT NULL DEFAULT 'string' COMMENT 'string, integer, decimal, boolean, json',
  `is_searchable` tinyint(1) NOT NULL DEFAULT 1,
  `is_comparable` tinyint(1) NOT NULL DEFAULT 1,
  `unit` varchar(20) DEFAULT NULL COMMENT 'MHz, GB, W, etc.',
  `source` varchar(100) DEFAULT NULL COMMENT 'manufacturer, manual, specification_sheet, etc.',
  `confidence_level` tinyint(1) NOT NULL DEFAULT 5 COMMENT '1-10 confidence in accuracy',
  `last_verified` timestamp NULL DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `component_usage_tracking`
--

CREATE TABLE `component_usage_tracking` (
  `id` int(11) NOT NULL,
  `component_uuid` varchar(36) NOT NULL,
  `component_type` varchar(20) NOT NULL,
  `config_uuid` varchar(36) DEFAULT NULL COMMENT 'NULL if not assigned to configuration',
  `deployment_uuid` varchar(36) DEFAULT NULL COMMENT 'NULL if not deployed',
  `usage_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=Available, 1=Reserved, 2=In Use, 3=Maintenance, 4=Failed, 5=Retired',
  `assigned_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `usage_purpose` varchar(255) DEFAULT NULL,
  `expected_duration` int(11) DEFAULT NULL COMMENT 'Expected usage duration in days',
  `actual_duration` int(11) DEFAULT NULL COMMENT 'Actual usage duration in days',
  `performance_notes` text DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `maintenance_required` tinyint(1) NOT NULL DEFAULT 0,
  `cost_per_day` decimal(8,2) DEFAULT NULL,
  `total_cost` decimal(10,2) DEFAULT NULL,
  `billable_to` varchar(100) DEFAULT NULL COMMENT 'Department or project code',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cpuinventory`
--

CREATE TABLE `cpuinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(36) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where CPU is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cpuinventory`
--

INSERT INTO `cpuinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'd93a4790-959d-4cd4-8e95-bb1b9c85b9fd', 'CPU123456', 2, '03b02d6f-1690-47ef-98f1-1a04bfa6e6f2', 'Datacenter North', 'Rack A3-12', '2023-05-15', '2023-06-01', '2026-05-15', 'Production', 'Intel Xeon 8-core 3.2GHz', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(2, '41849749-8d19-4366-b41a-afda6fa46b58', 'CPU789012', 2, NULL, 'Warehouse East', 'Shelf B4', '2024-01-10', NULL, '2027-01-10', 'Backup', 'AMD EPYC 16-core 2.9GHz', '2025-05-11 11:42:52', '2025-08-06 06:23:55'),
(5, '545e143b-57b3-419e-86e5-1df6f7aa8fd3', 'CPU789013', 1, '', ' Warehouse East', 'Shelf B4', '2024-01-10', NULL, '2027-01-10', 'Backup', 'AMD EPYC 16-core 2.9GHz', '2025-05-31 22:27:20', '2025-05-31 22:27:20'),
(7, '545e143b-57b3-419e-86e5-1df6f7aa8fg4', 'CPU789022', 2, '', 'Warehouse East', 'Shelf B4', '2024-01-29', NULL, '2026-01-29', 'Backup', 'AMD EPYC 64-core 2.9GHz', '2025-06-19 19:56:10', '2025-06-19 19:56:10'),
(10, '545e143b-57b3-419e-86e5-1df6f7aa8fz3', 'CPU789029', 2, '', 'Warehouse East', 'Shelf B4', '2024-01-31', NULL, '2026-01-31', 'Backup', 'AMD EPYC 64-core 2.9GHz', '2025-06-27 22:46:53', '2025-06-27 22:46:53'),
(11, '545e143b-57b3-419e-86e5-1df6f7aa8fz5', 'CPU789021', 2, '', 'Warehouse East', 'Shelf B4', '2024-01-31', NULL, '2026-01-31', 'Backup', 'AMD EPYC 64-core 2.9GHz', '2025-07-10 14:46:15', '2025-07-10 14:46:15'),
(17, '139e9bcd-ac86-44e9-8e9b-3178e3be1fb8', 'CPU789060', 1, 'null', 'New Delhi, Delhi', 'Rack Z10', '2025-07-28', NULL, '2027-12-02', 'Backup', 'EPYC 9534 AMD CPUsss', '2025-07-27 08:57:02', '2025-07-28 04:44:43');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_log`
--

CREATE TABLE `inventory_log` (
  `id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `component_type` varchar(50) DEFAULT NULL,
  `component_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_data`)),
  `notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `inventory_log`
--

INSERT INTO `inventory_log` (`id`, `user_id`, `component_type`, `component_id`, `action`, `old_data`, `new_data`, `notes`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 01:29:56'),
(2, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 01:43:06'),
(3, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 01:45:19'),
(4, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 02:41:20'),
(5, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 03:57:32'),
(6, 37, 'auth', 37, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:00:57'),
(7, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:06:47'),
(8, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:12:06'),
(9, 38, 'user_management', 37, 'Role assigned', NULL, NULL, 'Assigned role 2 to user 37', '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:18:52'),
(10, 38, 'cpu', 16, 'Component created', NULL, '{\"UUID\":\"450437dc-af82-4d56-879c-6f341373a8b9\",\"SerialNumber\":\"CPU789089\",\"Status\":\"1\",\"ServerUUID\":\"\",\"Location\":\" Warehouse East\",\"RackPosition\":\"\",\"PurchaseDate\":null,\"WarrantyEndDate\":null,\"Flag\":\"Backup\",\"Notes\":\"\"}', 'Created new cpu component', '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:49:41'),
(11, 38, 'cpu', 16, 'Component deleted', '{\"ID\":16,\"UUID\":\"450437dc-af82-4d56-879c-6f341373a8b9\",\"SerialNumber\":\"CPU789089\",\"Status\":1,\"ServerUUID\":\"\",\"Location\":\" Warehouse East\",\"RackPosition\":\"\",\"PurchaseDate\":null,\"InstallationDate\":null,\"WarrantyEndDate\":null,\"Flag\":\"Backup\",\"Notes\":\"\",\"CreatedAt\":\"2025-07-25 07:49:41\",\"UpdatedAt\":\"2025-07-25 07:49:41\"}', NULL, 'Deleted cpu component', '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 07:50:31'),
(12, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36', '2025-07-25 08:08:35'),
(13, 25, 'auth', 25, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-25 14:39:09'),
(14, 25, 'auth', 25, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36', '2025-07-25 14:39:38'),
(15, 25, 'auth', 25, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36', '2025-07-25 14:40:09'),
(16, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Mobile Safari/537.36', '2025-07-25 17:35:05'),
(17, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-25 17:53:36'),
(18, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-25 17:53:52'),
(19, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 19:44:23'),
(20, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-25 19:51:05'),
(21, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-25 19:53:18'),
(22, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 07:19:14'),
(23, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-26 07:26:34'),
(24, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 08:44:47'),
(25, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:02:09'),
(26, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-26 18:06:16'),
(27, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:06:30'),
(28, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:15:16'),
(29, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-26 18:16:18'),
(30, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:16:57'),
(31, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:22:43'),
(32, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:26:19'),
(33, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:34:34'),
(34, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 18:37:25'),
(35, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 23:25:45'),
(36, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-26 23:29:31'),
(37, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-26 23:42:11'),
(38, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 01:06:06'),
(39, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 02:47:43'),
(40, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-27 02:49:31'),
(41, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:03:24'),
(42, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:35:58'),
(43, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:36:59'),
(44, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:39:39'),
(45, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:42:38'),
(46, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:48:21'),
(47, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:49:14'),
(48, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:50:22'),
(49, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 03:57:12'),
(50, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 04:05:54'),
(51, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 04:39:56'),
(52, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 05:06:46'),
(53, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 05:14:34'),
(54, 38, 'cpu', 15, 'Component deleted', '{\"ID\":15,\"UUID\":\"545e143b-57b3-419e-86e5-1df6f7aa8fy9\",\"SerialNumber\":\"CPU789032\",\"Status\":2,\"ServerUUID\":\"\",\"Location\":\" Warehouse East\",\"RackPosition\":\"Shelf B4\",\"PurchaseDate\":\"2024-01-31\",\"InstallationDate\":null,\"WarrantyEndDate\":\"2026-01-31\",\"Flag\":\"Backup\",\"Notes\":\"AMD EPYC 64-core 2.9GHz\",\"CreatedAt\":\"2025-07-22 19:50:05\",\"UpdatedAt\":\"2025-07-22 19:50:05\"}', NULL, 'Deleted cpu component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 05:36:24'),
(55, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 07:02:23'),
(56, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:03:02'),
(57, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:05:39'),
(58, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:37:10'),
(59, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:55:53'),
(60, 38, 'cpu', 17, 'Component created', NULL, '{\"UUID\":\"139e9bcd-ac86-44e9-8e9b-3178e3be1fb8\",\"SerialNumber\":\"CPU789060\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"PurchaseDate\":\"2025-07-28\",\"WarrantyEndDate\":\"2027-12-02\",\"Flag\":\"Backup\",\"Notes\":\"EPYC 9534 AMD CPU\"}', 'Created new cpu component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 08:57:02'),
(61, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 13:51:06'),
(62, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:05:07'),
(63, 38, 'storage', 4, 'Component created', NULL, '{\"UUID\":\"43e1ad0d-cf4a-49c9-a750-b50f73e773f7\",\"SerialNumber\":\"HDD789098\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z9\",\"PurchaseDate\":\"2025-07-30\",\"WarrantyEndDate\":\"2029-10-25\",\"Flag\":\"Backup\",\"Notes\":\"Type: HDD, Capacity: 960GB\\n\\nAdditional Notes: crucial nvme gen 4 \"}', 'Created new storage component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:06:01'),
(64, 38, 'motherboard', 4, 'Component created', NULL, '{\"UUID\":\"18527f82-7f18-4148-9cb8-7449b1e3cadf\",\"SerialNumber\":\"MOT2323882\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"New Delhi India\",\"RackPosition\":\"Rack Z10\",\"PurchaseDate\":\"2025-07-29\",\"WarrantyEndDate\":\"2029-11-15\",\"Flag\":\"Backup\",\"Notes\":\"Brand: GIGABYTE, Series: MZ, Model: MZ93-FS0\\n\\nAdditional Notes: gigabyte motherboard z790 godlike\"}', 'Created new motherboard component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:08:05'),
(65, 38, 'motherboard', 5, 'Component created', NULL, '{\"UUID\":\"67c845a3-d827-47d1-8441-0639ef10391b\",\"SerialNumber\":\"MB345688\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"Banglore\",\"RackPosition\":\"Rack Z10\",\"PurchaseDate\":\"2025-07-28\",\"WarrantyEndDate\":\"2025-08-02\",\"Flag\":\"Backup\",\"Notes\":\"Brand: Supermicro, Series: X13, Model: X13DRi-N\\n\\nAdditional Notes: gigabyte godlike z790 \"}', 'Created new motherboard component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:09:43'),
(66, 38, 'caddy', 4, 'Component created', NULL, '{\"UUID\":\"505c1ec9-35cc-4da9-b555-b7d15c0d9d06\",\"SerialNumber\":\"CDY789082\",\"Status\":\"1\",\"ServerUUID\":\"null\",\"Location\":\"Himachal\",\"RackPosition\":\"Rack Z5\",\"PurchaseDate\":\"2025-07-29\",\"WarrantyEndDate\":\"2025-07-17\",\"Flag\":\"Critical\",\"Notes\":\"Type: 3.5 Inch\\n\\nAdditional Notes: new caddy\"}', 'Created new caddy component', '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 14:11:44'),
(67, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 15:34:32'),
(68, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 15:50:36'),
(69, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 15:53:03'),
(70, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'PostmanRuntime/7.44.1', '2025-07-27 16:00:05'),
(71, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.162.169', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-27 20:06:04'),
(72, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:41:56'),
(73, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:47:35'),
(74, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:47:42'),
(75, 38, 'cpu', 17, 'Component updated', '{\"Status\":1,\"ServerUUID\":\"null\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"Notes\":\"EPYC 9534 AMD CPU\"}', '{\"Status\":\"2\",\"Notes\":\"EPYC 9534 AMD CPUsss\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"ServerUUID\":\"null\"}', 'Updated cpu component', '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:48:05'),
(76, 38, 'motherboard', 5, 'Component updated', '{\"Status\":1,\"ServerUUID\":\"null\",\"Location\":\"Banglore\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"Notes\":\"Brand: Supermicro, Series: X13, Model: X13DRi-N\\n\\nAdditional Notes: gigabyte godlike z790 \"}', '{\"Status\":\"2\",\"Notes\":\"Brand: Supermicro, Series: X13, Model: X13DRi-N good motherboard\\r\\n\\r\\nAdditional Notes: gigabyte godlike z790 \",\"Location\":\"Banglore\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"ServerUUID\":\"null\"}', 'Updated motherboard component', '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 00:48:55'),
(77, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:22:01'),
(78, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:23:43'),
(79, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:23:51'),
(80, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:24:58'),
(81, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:25:05'),
(82, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:26:27'),
(83, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 01:26:35'),
(84, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:19:25'),
(85, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:42:33'),
(86, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:42:54'),
(87, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:44:20'),
(88, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:44:32'),
(89, 38, 'cpu', 17, 'Component updated', '{\"Status\":2,\"ServerUUID\":\"null\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"Notes\":\"EPYC 9534 AMD CPUsss\"}', '{\"Status\":\"1\",\"Notes\":\"EPYC 9534 AMD CPUsss\",\"Location\":\"New Delhi, Delhi\",\"RackPosition\":\"Rack Z10\",\"Flag\":\"Backup\",\"ServerUUID\":\"null\"}', 'Updated cpu component', '106.215.161.226', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 04:44:43'),
(90, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '27.97.101.219', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 06:51:11'),
(91, 38, 'cpu', 18, 'Component created', NULL, '{\"UUID\":\"5f67a7a1-842d-4137-b1b6-fde29f5d49e7\",\"SerialNumber\":\"fhdjfhskjfhsjkdf\",\"Status\":\"2\",\"ServerUUID\":\"In Use\",\"Location\":\"Noida\",\"RackPosition\":\"Rack B4\",\"PurchaseDate\":\"2025-07-16\",\"WarrantyEndDate\":\"2025-08-08\",\"Flag\":\"Backup\",\"Notes\":\"Brand: Intel, Series: Xeon Scalable, Model: Platinum 8480+\"}', 'Created new cpu component', '27.97.101.219', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 06:59:25'),
(92, 38, 'cpu', 18, 'Component deleted', '{\"ID\":18,\"UUID\":\"5f67a7a1-842d-4137-b1b6-fde29f5d49e7\",\"SerialNumber\":\"fhdjfhskjfhsjkdf\",\"Status\":2,\"ServerUUID\":\"In Use\",\"Location\":\"Noida\",\"RackPosition\":\"Rack B4\",\"PurchaseDate\":\"2025-07-16\",\"InstallationDate\":null,\"WarrantyEndDate\":\"2025-08-08\",\"Flag\":\"Backup\",\"Notes\":\"Brand: Intel, Series: Xeon Scalable, Model: Platinum 8480+\",\"CreatedAt\":\"2025-07-28 06:59:25\",\"UpdatedAt\":\"2025-07-28 06:59:25\"}', NULL, 'Deleted cpu component', '27.97.101.219', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-28 06:59:35'),
(93, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 03:21:02'),
(94, 38, 'user_management', 37, 'Role assigned', NULL, NULL, 'Assigned role 2 to user 37', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 03:23:32'),
(95, 38, 'user_management', 39, 'User created', NULL, NULL, 'Created new user: Shubham', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 03:38:57'),
(96, 38, 'role', 3806, 'create', NULL, NULL, 'Created role: Media Manager', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 03:54:58'),
(97, 38, 'role', 3806, 'update_permissions', NULL, NULL, 'Updated permissions for role: media_manager', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:06:17'),
(98, 38, 'user_management', 39, 'Role assigned', NULL, NULL, 'Assigned role 3806 to user 39', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:17:03'),
(99, 38, 'user_management', 39, 'Role assigned', NULL, NULL, 'Assigned role 3806 to user 39', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:18:36'),
(100, 38, 'user_management', 39, 'Role removed', NULL, NULL, 'Removed role 3806 from user 39', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:18:48'),
(101, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:21:32'),
(102, 38, 'role', 3806, 'update', NULL, NULL, 'Updated role: Media Managers', '106.215.161.226', 'PostmanRuntime/7.44.1', '2025-07-29 04:27:40'),
(103, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-29 13:09:12'),
(104, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-29 13:23:40'),
(105, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-30 00:39:53'),
(106, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-30 01:12:47'),
(107, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-30 01:13:00'),
(108, 38, 'auth', 38, 'User login', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-31 16:32:55'),
(109, 38, 'auth', 38, 'User logout', NULL, NULL, NULL, '106.215.167.9', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-07-31 17:00:19');

-- --------------------------------------------------------

--
-- Table structure for table `jwt_blacklist`
--

CREATE TABLE `jwt_blacklist` (
  `id` int(11) NOT NULL,
  `jti` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `motherboardinventory`
--

CREATE TABLE `motherboardinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(36) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where motherboard is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `motherboardinventory`
--

INSERT INTO `motherboardinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, '79b1f3a2-c248-48ae-bec0-71bdfd170849', 'MB123456', 2, '03b02d6f-1690-47ef-98f1-1a04bfa6e6f2', 'Datacenter North', 'Rack A3-12', '2023-05-10', '2023-06-01', '2026-05-10', 'Production', 'Supermicro X12DPi-NT6', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(2, '92d2d69d-9101-4f15-a507-ab9effd93b6b', 'MB789012', 0, NULL, 'Repair Center', 'Bench 3', '2023-02-20', '2023-03-01', '2026-02-20', 'Repair', 'ASUS WS C621E SAGE - Under repair for BIOS issues', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(3, 'fa410f1c-ab12-46c5-add9-201fcc4985c7', 'MB345678', 1, NULL, 'Warehouse West', 'Shelf A1', '2024-02-10', NULL, '2027-02-10', 'Spare', 'MSI PRO B650-P WiFi', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(4, '18527f82-7f18-4148-9cb8-7449b1e3cadf', 'MOT2323882', 1, 'null', 'New Delhi India', 'Rack Z10', '2025-07-29', NULL, '2029-11-15', 'Backup', 'Brand: GIGABYTE, Series: MZ, Model: MZ93-FS0\n\nAdditional Notes: gigabyte motherboard z790 godlike', '2025-07-27 14:08:05', '2025-07-27 14:08:05'),
(5, '67c845a3-d827-47d1-8441-0639ef10391b', 'MB345688', 2, 'null', 'Banglore', 'Rack Z10', '2025-07-28', NULL, '2025-08-02', 'Backup', 'Brand: Supermicro, Series: X13, Model: X13DRi-N good motherboard\r\n\r\nAdditional Notes: gigabyte godlike z790 ', '2025-07-27 14:09:43', '2025-07-28 00:48:55');

-- --------------------------------------------------------

--
-- Table structure for table `nicinventory`
--

CREATE TABLE `nicinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(36) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where NIC is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `MacAddress` varchar(17) DEFAULT NULL COMMENT 'MAC address of the NIC',
  `IPAddress` varchar(45) DEFAULT NULL COMMENT 'IP address assigned to the NIC, if any',
  `NetworkName` varchar(100) DEFAULT NULL COMMENT 'Name of the network the NIC is connected to',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `nicinventory`
--

INSERT INTO `nicinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `MacAddress`, `IPAddress`, `NetworkName`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, '83f54a8c-690a-4a08-af6e-736dd5463f44', 'NIC123456', 2, '03b02d6f-1690-47ef-98f1-1a04bfa6e6f2', 'Datacenter North', 'Rack A3-12', '00:1A:2B:3C:4D:5E', '192.168.1.100', 'Internal-Production', '2023-05-12', '2023-06-01', '2026-05-12', 'Production', 'Intel X550-T2 10GbE Dual Port', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(2, 'c7c23681-255a-485c-8d21-e3653970076c', 'NIC789012', 1, NULL, 'Warehouse East', 'Shelf E3', '00:2C:3D:4E:5F:6A', NULL, NULL, '2024-02-15', NULL, '2027-02-15', 'Backup', 'Mellanox ConnectX-5 100GbE QSFP28', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(3, 'b47b0572-0319-40d9-85c3-d1b21addbfd8', 'NIC345678', 0, NULL, 'Disposal', 'Bin 1', '00:3E:4F:5A:6B:7C', NULL, NULL, '2022-06-20', '2022-07-01', '2025-06-20', 'Hardware Failure', 'Broadcom BCM57414 25GbE - Port 2 failure, scheduled for RMA', '2025-05-11 11:42:52', '2025-05-11 11:42:52');

-- --------------------------------------------------------

--
-- Table structure for table `pciecardinventory`
--

CREATE TABLE `pciecardinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(36) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where PCIe card is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `CardType` varchar(50) DEFAULT NULL COMMENT 'Type of PCIe card (NVMe adapter, RAID, GPU, NIC, etc.)',
  `Attachables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON data for attached components and slots' CHECK (json_valid(`Attachables`)),
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT 'general',
  `is_basic` tinyint(1) DEFAULT 0 COMMENT '1 = basic permission given to new roles',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `display_name`, `description`, `category`, `is_basic`, `created_at`) VALUES
(1, 'auth.login', 'Login to System', 'Basic login access', 'authentication', 1, '2025-07-24 00:05:50'),
(2, 'auth.logout', 'Logout from System', 'Logout access', 'authentication', 1, '2025-07-24 00:05:50'),
(3, 'auth.change_password', 'Change Own Password', 'Change own password', 'authentication', 1, '2025-07-24 00:05:50'),
(4, 'users.view', 'View Users', 'View user list and details', 'user_management', 0, '2025-07-24 00:05:50'),
(5, 'users.create', 'Create Users', 'Create new user accounts', 'user_management', 0, '2025-07-24 00:05:50'),
(6, 'users.edit', 'Edit Users', 'Edit user account details', 'user_management', 0, '2025-07-24 00:05:50'),
(7, 'users.delete', 'Delete Users', 'Delete user accounts', 'user_management', 0, '2025-07-24 00:05:50'),
(8, 'users.manage_roles', 'Manage User Roles', 'Assign/remove roles from users', 'user_management', 0, '2025-07-24 00:05:50'),
(9, 'roles.view', 'View Roles', 'View available roles', 'role_management', 0, '2025-07-24 00:05:50'),
(10, 'roles.create', 'Create Roles', 'Create new roles', 'role_management', 0, '2025-07-24 00:05:50'),
(11, 'roles.edit', 'Edit Roles', 'Edit role details and permissions', 'role_management', 0, '2025-07-24 00:05:50'),
(12, 'roles.delete', 'Delete Roles', 'Delete custom roles', 'role_management', 0, '2025-07-24 00:05:50'),
(13, 'cpu.view', 'View CPUs', 'View CPU inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(14, 'cpu.create', 'Add CPUs', 'Add new CPU components', 'inventory', 0, '2025-07-24 00:05:50'),
(15, 'cpu.edit', 'Edit CPUs', 'Edit CPU component details', 'inventory', 0, '2025-07-24 00:05:50'),
(16, 'cpu.delete', 'Delete CPUs', 'Delete CPU components', 'inventory', 0, '2025-07-24 00:05:50'),
(17, 'ram.view', 'View RAM', 'View RAM inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(18, 'ram.create', 'Add RAM', 'Add new RAM components', 'inventory', 0, '2025-07-24 00:05:50'),
(19, 'ram.edit', 'Edit RAM', 'Edit RAM component details', 'inventory', 0, '2025-07-24 00:05:50'),
(20, 'ram.delete', 'Delete RAM', 'Delete RAM components', 'inventory', 0, '2025-07-24 00:05:50'),
(21, 'storage.view', 'View Storage', 'View storage inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(22, 'storage.create', 'Add Storage', 'Add new storage components', 'inventory', 0, '2025-07-24 00:05:50'),
(23, 'storage.edit', 'Edit Storage', 'Edit storage component details', 'inventory', 0, '2025-07-24 00:05:50'),
(24, 'storage.delete', 'Delete Storage', 'Delete storage components', 'inventory', 0, '2025-07-24 00:05:50'),
(25, 'motherboard.view', 'View Motherboards', 'View motherboard inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(26, 'motherboard.create', 'Add Motherboards', 'Add new motherboard components', 'inventory', 0, '2025-07-24 00:05:50'),
(27, 'motherboard.edit', 'Edit Motherboards', 'Edit motherboard component details', 'inventory', 0, '2025-07-24 00:05:50'),
(28, 'motherboard.delete', 'Delete Motherboards', 'Delete motherboard components', 'inventory', 0, '2025-07-24 00:05:50'),
(29, 'nic.view', 'View NICs', 'View NIC inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(30, 'nic.create', 'Add NICs', 'Add new NIC components', 'inventory', 0, '2025-07-24 00:05:50'),
(31, 'nic.edit', 'Edit NICs', 'Edit NIC component details', 'inventory', 0, '2025-07-24 00:05:50'),
(32, 'nic.delete', 'Delete NICs', 'Delete NIC components', 'inventory', 0, '2025-07-24 00:05:50'),
(33, 'caddy.view', 'View Caddies', 'View caddy inventory', 'inventory', 1, '2025-07-24 00:05:50'),
(34, 'caddy.create', 'Add Caddies', 'Add new caddy components', 'inventory', 0, '2025-07-24 00:05:50'),
(35, 'caddy.edit', 'Edit Caddies', 'Edit caddy component details', 'inventory', 0, '2025-07-24 00:05:50'),
(36, 'caddy.delete', 'Delete Caddies', 'Delete caddy components', 'inventory', 0, '2025-07-24 00:05:50'),
(37, 'dashboard.view', 'View Dashboard', 'Access main dashboard', 'dashboard', 1, '2025-07-24 00:05:50'),
(38, 'reports.view', 'View Reports', 'View inventory reports', 'reports', 0, '2025-07-24 00:05:50'),
(39, 'reports.export', 'Export Reports', 'Export inventory data', 'reports', 0, '2025-07-24 00:05:50'),
(40, 'search.global', 'Global Search', 'Search across all components', 'utilities', 1, '2025-07-24 00:05:50'),
(41, 'search.advanced', 'Advanced Search', 'Advanced search capabilities', 'utilities', 0, '2025-07-24 00:05:50'),
(42, 'system.view_logs', 'View System Logs', 'View system activity logs', 'system', 0, '2025-07-24 00:05:50'),
(43, 'system.manage_settings', 'Manage Settings', 'Manage system settings', 'system', 0, '2025-07-24 00:05:50'),
(44, 'system.backup', 'System Backup', 'Create system backups', 'system', 0, '2025-07-24 00:05:50'),
(45, 'system.maintenance', 'System Maintenance', 'Perform system maintenance', 'system', 0, '2025-07-24 00:05:50'),
(95, 'dashboard.admin', 'Admin Dashboard Access', NULL, 'dashboard', 0, '2025-07-25 01:29:56'),
(128, 'roles.assign', 'Assign Roles to Users', NULL, 'user_management', 0, '2025-07-25 01:29:56'),
(133, 'system.settings', 'System Settings', NULL, 'system', 0, '2025-07-25 01:29:56'),
(134, 'system.logs', 'View System Logs', NULL, 'system', 0, '2025-07-25 01:29:56'),
(47431, 'server.view', 'View Server Configurations', 'View server configuration details', 'server_management', 0, '2025-08-02 15:10:03'),
(47432, 'server.create', 'Create Server Configurations', 'Create new server configurations', 'server_management', 0, '2025-08-02 15:10:03'),
(47433, 'server.edit', 'Edit Server Configurations', 'Modify existing server configurations', 'server_management', 0, '2025-08-02 15:10:03'),
(47434, 'server.delete', 'Delete Server Configurations', 'Delete server configurations', 'server_management', 0, '2025-08-02 15:10:03'),
(47435, 'server.view_all', 'View All Server Configurations', 'View server configurations created by other users', 'server_management', 0, '2025-08-02 15:10:03'),
(47436, 'server.delete_all', 'Delete Any Server Configuration', 'Delete server configurations created by other users', 'server_management', 0, '2025-08-02 15:10:03'),
(47437, 'server.view_statistics', 'View Server Statistics', 'View server configuration statistics and reports', 'server_management', 0, '2025-08-02 15:10:03'),
(47438, 'compatibility.check', 'Check Component Compatibility', 'Run compatibility checks between components', 'compatibility', 0, '2025-08-02 15:10:03'),
(47439, 'compatibility.view_statistics', 'View Compatibility Statistics', 'View compatibility check statistics', 'compatibility', 0, '2025-08-02 15:10:03'),
(47440, 'compatibility.manage_rules', 'Manage Compatibility Rules', 'Create and modify compatibility rules', 'compatibility', 0, '2025-08-02 15:10:03');

-- --------------------------------------------------------

--
-- Table structure for table `raminventory`
--

CREATE TABLE `raminventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(36) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where RAM is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `raminventory`
--

INSERT INTO `raminventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, '897472c6-7b40-411b-80ef-31a6ca3156ea', 'RAM123456', 2, '03b02d6f-1690-47ef-98f1-1a04bfa6e6f2', 'Datacenter North', 'Rack A3-12', '2023-05-15', '2023-06-01', '2026-05-15', 'Production', '32GB DDR4-3200', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(2, 'ef5798f2-fdc4-4e5d-9364-6971995002ea', 'RAM789012', 1, NULL, 'Warehouse East', 'Shelf C2', '2024-01-15', NULL, '2027-01-15', 'Backup', '64GB DDR4-3600', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(3, '82827c01-6e89-4d6a-bf2d-e62c929e2080', 'RAM456789', 2, '54b6c953-be12-4cd7-a87f-6ed835beb2c5', 'Datacenter South', 'Rack B2-5', '2023-08-20', '2023-09-01', '2026-08-20', 'Production', '32GB DDR4-3200 ECC', '2025-05-11 11:42:52', '2025-05-11 11:42:52');

-- --------------------------------------------------------

--
-- Table structure for table `refresh_tokens`
--

CREATE TABLE `refresh_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(6) UNSIGNED NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `is_system` tinyint(1) DEFAULT 0 COMMENT '1 = system role, cannot be deleted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `display_name`, `description`, `is_default`, `is_system`, `created_at`, `updated_at`) VALUES
(1, 'super_admin', 'Super Administrator', 'Full system access with all permissions', 0, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(2, 'admin', 'Administrator', 'Administrative access with most permissions', 0, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(3, 'manager', 'Manager', 'Management level access for inventory operations', 0, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(4, 'technician', 'Technician', 'Technical staff with component management access', 0, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(5, 'viewer', 'Viewer', 'Read-only access to inventory data', 1, 1, '2025-07-24 00:05:50', '2025-07-24 00:05:50'),
(3806, 'media_manager', 'Media Managers', '', 0, 0, '2025-07-29 03:54:58', '2025-07-29 04:27:40');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `granted` tinyint(1) DEFAULT 1 COMMENT '1 = granted, 0 = denied',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `granted`, `created_at`) VALUES
(1, 1, 4, 1, '2025-07-24 00:05:50'),
(2, 1, 5, 1, '2025-07-24 00:05:50'),
(3, 1, 6, 1, '2025-07-24 00:05:50'),
(4, 1, 7, 1, '2025-07-24 00:05:50'),
(5, 1, 8, 1, '2025-07-24 00:05:50'),
(6, 1, 9, 1, '2025-07-24 00:05:50'),
(7, 1, 10, 1, '2025-07-24 00:05:50'),
(8, 1, 11, 1, '2025-07-24 00:05:50'),
(9, 1, 12, 1, '2025-07-24 00:05:50'),
(10, 1, 14, 1, '2025-07-24 00:05:50'),
(11, 1, 15, 1, '2025-07-24 00:05:50'),
(12, 1, 16, 1, '2025-07-24 00:05:50'),
(13, 1, 18, 1, '2025-07-24 00:05:50'),
(14, 1, 19, 1, '2025-07-24 00:05:50'),
(15, 1, 20, 1, '2025-07-24 00:05:50'),
(16, 1, 22, 1, '2025-07-24 00:05:50'),
(17, 1, 23, 1, '2025-07-24 00:05:50'),
(18, 1, 24, 1, '2025-07-24 00:05:50'),
(19, 1, 26, 1, '2025-07-24 00:05:50'),
(20, 1, 27, 1, '2025-07-24 00:05:50'),
(21, 1, 28, 1, '2025-07-24 00:05:50'),
(22, 1, 30, 1, '2025-07-24 00:05:50'),
(23, 1, 31, 1, '2025-07-24 00:05:50'),
(24, 1, 32, 1, '2025-07-24 00:05:50'),
(25, 1, 34, 1, '2025-07-24 00:05:50'),
(26, 1, 35, 1, '2025-07-24 00:05:50'),
(27, 1, 36, 1, '2025-07-24 00:05:50'),
(28, 1, 38, 1, '2025-07-24 00:05:50'),
(29, 1, 39, 1, '2025-07-24 00:05:50'),
(30, 1, 41, 1, '2025-07-24 00:05:50'),
(31, 1, 42, 1, '2025-07-24 00:05:50'),
(32, 1, 43, 1, '2025-07-24 00:05:50'),
(33, 1, 44, 1, '2025-07-24 00:05:50'),
(34, 1, 45, 1, '2025-07-24 00:05:50'),
(35, 1, 1, 1, '2025-07-24 00:05:50'),
(36, 1, 2, 1, '2025-07-24 00:05:50'),
(37, 1, 3, 1, '2025-07-24 00:05:50'),
(38, 1, 13, 1, '2025-07-24 00:05:50'),
(39, 1, 17, 1, '2025-07-24 00:05:50'),
(40, 1, 21, 1, '2025-07-24 00:05:50'),
(41, 1, 25, 1, '2025-07-24 00:05:50'),
(42, 1, 29, 1, '2025-07-24 00:05:50'),
(43, 1, 33, 1, '2025-07-24 00:05:50'),
(44, 1, 37, 1, '2025-07-24 00:05:50'),
(45, 1, 40, 1, '2025-07-24 00:05:50'),
(64, 2, 3, 1, '2025-07-24 00:05:50'),
(65, 2, 1, 1, '2025-07-24 00:05:50'),
(66, 2, 2, 1, '2025-07-24 00:05:50'),
(67, 2, 34, 1, '2025-07-24 00:05:50'),
(68, 2, 36, 1, '2025-07-24 00:05:50'),
(69, 2, 35, 1, '2025-07-24 00:05:50'),
(70, 2, 33, 1, '2025-07-24 00:05:50'),
(71, 2, 14, 1, '2025-07-24 00:05:50'),
(72, 2, 16, 1, '2025-07-24 00:05:50'),
(73, 2, 15, 1, '2025-07-24 00:05:50'),
(74, 2, 13, 1, '2025-07-24 00:05:50'),
(75, 2, 37, 1, '2025-07-24 00:05:50'),
(76, 2, 26, 1, '2025-07-24 00:05:50'),
(77, 2, 28, 1, '2025-07-24 00:05:50'),
(78, 2, 27, 1, '2025-07-24 00:05:50'),
(79, 2, 25, 1, '2025-07-24 00:05:50'),
(80, 2, 30, 1, '2025-07-24 00:05:50'),
(81, 2, 32, 1, '2025-07-24 00:05:50'),
(82, 2, 31, 1, '2025-07-24 00:05:50'),
(83, 2, 29, 1, '2025-07-24 00:05:50'),
(84, 2, 18, 1, '2025-07-24 00:05:50'),
(85, 2, 20, 1, '2025-07-24 00:05:50'),
(86, 2, 19, 1, '2025-07-24 00:05:50'),
(87, 2, 17, 1, '2025-07-24 00:05:50'),
(88, 2, 39, 1, '2025-07-24 00:05:50'),
(89, 2, 38, 1, '2025-07-24 00:05:50'),
(90, 2, 10, 1, '2025-07-24 00:05:50'),
(91, 2, 11, 1, '2025-07-24 00:05:50'),
(92, 2, 9, 1, '2025-07-24 00:05:50'),
(93, 2, 41, 1, '2025-07-24 00:05:50'),
(94, 2, 40, 1, '2025-07-24 00:05:50'),
(95, 2, 22, 1, '2025-07-24 00:05:50'),
(96, 2, 24, 1, '2025-07-24 00:05:50'),
(97, 2, 23, 1, '2025-07-24 00:05:50'),
(98, 2, 21, 1, '2025-07-24 00:05:50'),
(99, 2, 43, 1, '2025-07-24 00:05:50'),
(100, 2, 42, 1, '2025-07-24 00:05:50'),
(101, 2, 5, 1, '2025-07-24 00:05:50'),
(102, 2, 7, 1, '2025-07-24 00:05:50'),
(103, 2, 6, 1, '2025-07-24 00:05:50'),
(104, 2, 8, 1, '2025-07-24 00:05:50'),
(105, 2, 4, 1, '2025-07-24 00:05:50'),
(127, 3, 1, 1, '2025-07-24 00:05:50'),
(128, 3, 2, 1, '2025-07-24 00:05:50'),
(129, 3, 3, 1, '2025-07-24 00:05:50'),
(130, 3, 4, 1, '2025-07-24 00:05:50'),
(131, 3, 13, 1, '2025-07-24 00:05:50'),
(132, 3, 14, 1, '2025-07-24 00:05:50'),
(133, 3, 15, 1, '2025-07-24 00:05:50'),
(134, 3, 16, 1, '2025-07-24 00:05:50'),
(135, 3, 17, 1, '2025-07-24 00:05:50'),
(136, 3, 18, 1, '2025-07-24 00:05:50'),
(137, 3, 19, 1, '2025-07-24 00:05:50'),
(138, 3, 20, 1, '2025-07-24 00:05:50'),
(139, 3, 21, 1, '2025-07-24 00:05:50'),
(140, 3, 22, 1, '2025-07-24 00:05:50'),
(141, 3, 23, 1, '2025-07-24 00:05:50'),
(142, 3, 24, 1, '2025-07-24 00:05:50'),
(143, 3, 25, 1, '2025-07-24 00:05:50'),
(144, 3, 26, 1, '2025-07-24 00:05:50'),
(145, 3, 27, 1, '2025-07-24 00:05:50'),
(146, 3, 28, 1, '2025-07-24 00:05:50'),
(147, 3, 29, 1, '2025-07-24 00:05:50'),
(148, 3, 30, 1, '2025-07-24 00:05:50'),
(149, 3, 31, 1, '2025-07-24 00:05:50'),
(150, 3, 32, 1, '2025-07-24 00:05:50'),
(151, 3, 33, 1, '2025-07-24 00:05:50'),
(152, 3, 34, 1, '2025-07-24 00:05:50'),
(153, 3, 35, 1, '2025-07-24 00:05:50'),
(154, 3, 36, 1, '2025-07-24 00:05:50'),
(155, 3, 37, 1, '2025-07-24 00:05:50'),
(156, 3, 38, 1, '2025-07-24 00:05:50'),
(157, 3, 39, 1, '2025-07-24 00:05:50'),
(158, 3, 40, 1, '2025-07-24 00:05:50'),
(159, 3, 41, 1, '2025-07-24 00:05:50'),
(190, 4, 1, 1, '2025-07-24 00:05:50'),
(191, 4, 2, 1, '2025-07-24 00:05:50'),
(192, 4, 3, 1, '2025-07-24 00:05:50'),
(193, 4, 9, 1, '2025-07-24 00:05:50'),
(194, 4, 10, 1, '2025-07-24 00:05:50'),
(195, 4, 11, 1, '2025-07-24 00:05:50'),
(196, 4, 13, 1, '2025-07-24 00:05:50'),
(197, 4, 14, 1, '2025-07-24 00:05:50'),
(198, 4, 15, 1, '2025-07-24 00:05:50'),
(199, 4, 17, 1, '2025-07-24 00:05:50'),
(200, 4, 18, 1, '2025-07-24 00:05:50'),
(201, 4, 19, 1, '2025-07-24 00:05:50'),
(202, 4, 21, 1, '2025-07-24 00:05:50'),
(203, 4, 22, 1, '2025-07-24 00:05:50'),
(204, 4, 23, 1, '2025-07-24 00:05:50'),
(205, 4, 25, 1, '2025-07-24 00:05:50'),
(206, 4, 26, 1, '2025-07-24 00:05:50'),
(207, 4, 27, 1, '2025-07-24 00:05:50'),
(208, 4, 29, 1, '2025-07-24 00:05:50'),
(209, 4, 30, 1, '2025-07-24 00:05:50'),
(210, 4, 31, 1, '2025-07-24 00:05:50'),
(211, 4, 33, 1, '2025-07-24 00:05:50'),
(212, 4, 34, 1, '2025-07-24 00:05:50'),
(213, 4, 35, 1, '2025-07-24 00:05:50'),
(214, 4, 37, 1, '2025-07-24 00:05:50'),
(215, 4, 38, 1, '2025-07-24 00:05:50'),
(216, 4, 40, 1, '2025-07-24 00:05:50'),
(221, 5, 1, 1, '2025-07-24 00:05:50'),
(222, 5, 2, 1, '2025-07-24 00:05:50'),
(223, 5, 3, 1, '2025-07-24 00:05:50'),
(224, 5, 4, 1, '2025-07-24 00:05:50'),
(225, 5, 9, 1, '2025-07-24 00:05:50'),
(226, 5, 13, 1, '2025-07-24 00:05:50'),
(227, 5, 17, 1, '2025-07-24 00:05:50'),
(228, 5, 21, 1, '2025-07-24 00:05:50'),
(229, 5, 25, 1, '2025-07-24 00:05:50'),
(230, 5, 29, 1, '2025-07-24 00:05:50'),
(231, 5, 33, 1, '2025-07-24 00:05:50'),
(232, 5, 37, 1, '2025-07-24 00:05:50'),
(233, 5, 38, 1, '2025-07-24 00:05:50'),
(234, 5, 40, 1, '2025-07-24 00:05:50'),
(237, 2, 12, 1, '2025-07-25 01:27:40'),
(240, 3, 9, 1, '2025-07-25 01:27:40'),
(243, 4, 41, 1, '2025-07-25 01:27:40'),
(245, 2, 44, 1, '2025-07-25 01:29:56'),
(246, 2, 45, 1, '2025-07-25 01:29:56'),
(247, 2, 95, 1, '2025-07-25 01:29:56'),
(248, 2, 128, 1, '2025-07-25 01:29:56'),
(249, 2, 133, 1, '2025-07-25 01:29:56'),
(250, 2, 134, 1, '2025-07-25 01:29:56'),
(253, 3, 95, 1, '2025-07-25 01:29:56'),
(259, 3, 10, 1, '2025-07-25 01:29:56'),
(260, 3, 5, 1, '2025-07-25 01:29:56'),
(262, 3, 11, 1, '2025-07-25 01:29:56'),
(263, 3, 6, 1, '2025-07-25 01:29:56'),
(270, 4, 4, 1, '2025-07-25 01:29:56'),
(271, 4, 5, 1, '2025-07-25 01:29:56'),
(272, 4, 6, 1, '2025-07-25 01:29:56'),
(937, 1, 95, 1, '2025-07-25 07:17:21'),
(938, 1, 128, 1, '2025-07-25 07:17:21'),
(939, 1, 133, 1, '2025-07-25 07:17:21'),
(940, 1, 134, 1, '2025-07-25 07:17:21'),
(19338, 3806, 40, 1, '2025-07-29 04:06:17'),
(21259, 1, 47438, 1, '2025-08-02 15:10:03'),
(21260, 1, 47440, 1, '2025-08-02 15:10:03'),
(21261, 1, 47439, 1, '2025-08-02 15:10:03'),
(21262, 1, 47432, 1, '2025-08-02 15:10:03'),
(21263, 1, 47434, 1, '2025-08-02 15:10:03'),
(21264, 1, 47436, 1, '2025-08-02 15:10:03'),
(21265, 1, 47433, 1, '2025-08-02 15:10:03'),
(21266, 1, 47431, 1, '2025-08-02 15:10:03'),
(21267, 1, 47435, 1, '2025-08-02 15:10:03'),
(21268, 1, 47437, 1, '2025-08-02 15:10:03'),
(21274, 2, 47431, 1, '2025-08-02 15:10:03'),
(21275, 2, 47432, 1, '2025-08-02 15:10:04'),
(21276, 2, 47433, 1, '2025-08-02 15:10:04'),
(21277, 2, 47434, 1, '2025-08-02 15:10:04'),
(21278, 2, 47437, 1, '2025-08-02 15:10:04'),
(21279, 2, 47438, 1, '2025-08-02 15:10:04'),
(21280, 2, 47439, 1, '2025-08-02 15:10:04'),
(21281, 3, 47431, 1, '2025-08-02 15:10:04'),
(21282, 3, 47432, 1, '2025-08-02 15:10:04'),
(21283, 3, 47433, 1, '2025-08-02 15:10:04'),
(21284, 3, 47437, 1, '2025-08-02 15:10:04'),
(21285, 3, 47438, 1, '2025-08-02 15:10:04'),
(21286, 4, 47431, 1, '2025-08-02 15:10:04'),
(21287, 4, 47432, 1, '2025-08-02 15:10:04'),
(21288, 4, 47438, 1, '2025-08-02 15:10:04'),
(21289, 5, 47431, 1, '2025-08-02 15:10:04'),
(21290, 5, 47438, 1, '2025-08-02 15:10:04'),
(21291, 2, 47435, 1, '2025-08-02 15:14:35'),
(21292, 2, 47436, 1, '2025-08-02 15:14:35'),
(21293, 2, 47440, 1, '2025-08-02 15:14:35'),
(21312, 4, 47433, 1, '2025-08-02 15:14:35');

-- --------------------------------------------------------

--
-- Table structure for table `server_build_templates`
--

CREATE TABLE `server_build_templates` (
  `id` int(11) NOT NULL,
  `template_uuid` varchar(36) NOT NULL,
  `template_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL COMMENT 'Web Server, Database Server, Storage Server, etc.',
  `created_by` int(11) NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=Private, 1=Public',
  `use_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Number of times template was used',
  `template_configuration` longtext NOT NULL COMMENT 'JSON configuration template',
  `minimum_requirements` longtext DEFAULT NULL COMMENT 'JSON of minimum hardware requirements',
  `recommended_specs` longtext DEFAULT NULL COMMENT 'JSON of recommended specifications',
  `tags` varchar(500) DEFAULT NULL COMMENT 'Comma-separated tags',
  `estimated_cost_min` decimal(12,2) DEFAULT NULL,
  `estimated_cost_max` decimal(12,2) DEFAULT NULL,
  `estimated_power_consumption` decimal(8,2) DEFAULT NULL,
  `version` varchar(20) NOT NULL DEFAULT '1.0',
  `parent_template_id` int(11) DEFAULT NULL COMMENT 'For template versioning',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=Draft, 1=Active, 2=Deprecated, 3=Archived',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `server_build_templates`
--

INSERT INTO `server_build_templates` (`id`, `template_uuid`, `template_name`, `description`, `category`, `created_by`, `is_public`, `use_count`, `template_configuration`, `minimum_requirements`, `recommended_specs`, `tags`, `estimated_cost_min`, `estimated_cost_max`, `estimated_power_consumption`, `version`, `parent_template_id`, `status`, `approved_by`, `approved_at`, `created_at`, `updated_at`) VALUES
(1, '745c73d1-726e-11f0-9219-309c239ceca6', 'Basic Web Server', 'Standard web server configuration for small to medium websites', 'Web Server', 1, 1, 0, '{\"cpu\": {\"cores\": 4, \"frequency\": \"2.0GHz\"}, \"ram\": {\"capacity\": \"8GB\", \"type\": \"DDR4\"}, \"storage\": {\"capacity\": \"500GB\", \"type\": \"SSD\"}, \"network\": {\"ports\": 2}}', '{\"cpu_cores\": 2, \"ram_gb\": 4, \"storage_gb\": 250}', '{\"cpu_cores\": 8, \"ram_gb\": 16, \"storage_gb\": 1000}', 'web,server,basic,production', NULL, NULL, NULL, '1.0', NULL, 1, NULL, NULL, '2025-08-06 02:38:37', '2025-08-06 02:38:37'),
(2, '745c7829-726e-11f0-9219-309c239ceca6', 'Database Server', 'High-performance database server with redundant storage', 'Database Server', 1, 1, 0, '{\"cpu\": {\"cores\": 8, \"frequency\": \"3.0GHz\"}, \"ram\": {\"capacity\": \"32GB\", \"type\": \"DDR4\"}, \"storage\": {\"capacity\": \"2TB\", \"type\": \"NVMe SSD\", \"redundancy\": \"RAID1\"}, \"network\": {\"ports\": 4}}', '{\"cpu_cores\": 4, \"ram_gb\": 16, \"storage_gb\": 500}', '{\"cpu_cores\": 16, \"ram_gb\": 64, \"storage_gb\": 4000}', 'database,server,performance,raid', NULL, NULL, NULL, '1.0', NULL, 1, NULL, NULL, '2025-08-06 02:38:37', '2025-08-06 02:38:37'),
(3, '745c79ae-726e-11f0-9219-309c239ceca6', 'Storage Server', 'Large capacity storage server with multiple drive bays', 'Storage Server', 1, 1, 0, '{\"cpu\": {\"cores\": 4, \"frequency\": \"2.5GHz\"}, \"ram\": {\"capacity\": \"16GB\", \"type\": \"DDR4\"}, \"storage\": {\"capacity\": \"20TB\", \"type\": \"SATA\", \"drives\": 8, \"redundancy\": \"RAID6\"}, \"network\": {\"ports\": 2, \"speed\": \"10Gb\"}}', '{\"cpu_cores\": 2, \"ram_gb\": 8, \"storage_gb\": 2000}', '{\"cpu_cores\": 8, \"ram_gb\": 32, \"storage_gb\": 50000}', 'storage,server,raid,backup', NULL, NULL, NULL, '1.0', NULL, 1, NULL, NULL, '2025-08-06 02:38:37', '2025-08-06 02:38:37');

-- --------------------------------------------------------

--
-- Table structure for table `server_configurations`
--

CREATE TABLE `server_configurations` (
  `id` int(11) NOT NULL,
  `config_uuid` varchar(36) NOT NULL COMMENT 'Unique identifier for server configuration',
  `server_name` varchar(255) NOT NULL COMMENT 'Server name for the configuration',
  `description` text DEFAULT NULL COMMENT 'Description of the server configuration',
  `cpu_uuid` varchar(36) DEFAULT NULL COMMENT 'Selected CPU UUID',
  `cpu_id` int(11) DEFAULT NULL COMMENT 'CPU inventory ID',
  `motherboard_uuid` varchar(36) DEFAULT NULL COMMENT 'Selected motherboard UUID',
  `motherboard_id` int(11) DEFAULT NULL COMMENT 'Motherboard inventory ID',
  `ram_configuration` longtext DEFAULT NULL COMMENT 'JSON array of RAM modules',
  `storage_configuration` longtext DEFAULT NULL COMMENT 'JSON array of storage devices',
  `nic_configuration` longtext DEFAULT NULL COMMENT 'JSON array of network cards',
  `caddy_configuration` longtext DEFAULT NULL COMMENT 'JSON array of caddies',
  `additional_components` longtext DEFAULT NULL COMMENT 'JSON for future component types',
  `configuration_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Draft, 1=Validated, 2=Built, 3=Deployed',
  `total_cost` decimal(12,2) DEFAULT NULL COMMENT 'Total estimated cost',
  `power_consumption` int(11) DEFAULT NULL COMMENT 'Estimated power consumption in watts',
  `compatibility_score` decimal(3,2) DEFAULT NULL COMMENT 'Overall compatibility score',
  `validation_results` longtext DEFAULT NULL COMMENT 'JSON validation results',
  `created_by` int(6) UNSIGNED DEFAULT NULL COMMENT 'User who created the configuration',
  `updated_by` int(6) UNSIGNED DEFAULT NULL COMMENT 'User who last updated the configuration',
  `built_date` datetime DEFAULT NULL COMMENT 'When the server was physically built',
  `deployed_date` datetime DEFAULT NULL COMMENT 'When the server was deployed',
  `location` varchar(100) DEFAULT NULL COMMENT 'Physical location of built server',
  `rack_position` varchar(20) DEFAULT NULL COMMENT 'Rack position if deployed',
  `notes` text DEFAULT NULL COMMENT 'Additional notes about the configuration',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Server configurations and builds';

--
-- Dumping data for table `server_configurations`
--

INSERT INTO `server_configurations` (`id`, `config_uuid`, `server_name`, `description`, `cpu_uuid`, `cpu_id`, `motherboard_uuid`, `motherboard_id`, `ram_configuration`, `storage_configuration`, `nic_configuration`, `caddy_configuration`, `additional_components`, `configuration_status`, `total_cost`, `power_consumption`, `compatibility_score`, `validation_results`, `created_by`, `updated_by`, `built_date`, `deployed_date`, `location`, `rack_position`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'd95e2554-8eb6-4a3c-90d7-45881af2a9d3', 'My Production Server', 'Web server for production workloads', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, 38, NULL, NULL, NULL, NULL, NULL, NULL, '2025-08-06 05:41:26', '2025-08-06 05:41:26');

--
-- Triggers `server_configurations`
--
DELIMITER $$
CREATE TRIGGER `tr_server_config_validation` BEFORE UPDATE ON `server_configurations` FOR EACH ROW BEGIN
    -- Auto-update the updated_at timestamp
    SET NEW.updated_at = CURRENT_TIMESTAMP;
    
    -- Log the configuration change if there's a status change
    IF OLD.configuration_status != NEW.configuration_status THEN
        INSERT INTO `compatibility_log` 
        (`operation_type`, `component_uuid_1`, `user_id`, `created_at`) 
        VALUES ('status_change', NEW.config_uuid, NEW.updated_by, CURRENT_TIMESTAMP);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `server_configuration_components`
--

CREATE TABLE `server_configuration_components` (
  `id` int(11) NOT NULL,
  `config_uuid` varchar(36) NOT NULL,
  `component_type` varchar(20) NOT NULL COMMENT 'cpu, motherboard, ram, storage, nic, caddy',
  `component_uuid` varchar(36) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `slot_position` varchar(50) DEFAULT NULL COMMENT 'Slot or position identifier',
  `notes` text DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `server_configuration_components`
--

INSERT INTO `server_configuration_components` (`id`, `config_uuid`, `component_type`, `component_uuid`, `quantity`, `slot_position`, `notes`, `added_at`, `updated_at`) VALUES
(1, 'd95e2554-8eb6-4a3c-90d7-45881af2a9d3', 'cpu', '41849749-8d19-4366-b41a-afda6fa46b58', 1, 'CPU_1', '', '2025-08-06 06:23:55', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `server_configuration_history`
--

CREATE TABLE `server_configuration_history` (
  `id` int(11) NOT NULL,
  `config_uuid` varchar(36) NOT NULL,
  `action` varchar(50) NOT NULL COMMENT 'created, updated, component_added, component_removed, validated, etc.',
  `user_id` int(11) NOT NULL,
  `changes` longtext DEFAULT NULL COMMENT 'JSON of changes made',
  `old_values` longtext DEFAULT NULL COMMENT 'JSON of old values',
  `new_values` longtext DEFAULT NULL COMMENT 'JSON of new values',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `server_deployments`
--

CREATE TABLE `server_deployments` (
  `id` int(11) NOT NULL,
  `deployment_uuid` varchar(36) NOT NULL,
  `config_uuid` varchar(36) NOT NULL,
  `deployment_name` varchar(255) NOT NULL,
  `environment` varchar(50) NOT NULL COMMENT 'production, staging, development, testing',
  `location` varchar(255) DEFAULT NULL COMMENT 'Physical location or datacenter',
  `rack_position` varchar(50) DEFAULT NULL,
  `deployment_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=Planned, 1=In Progress, 2=Deployed, 3=Decommissioned',
  `deployed_by` int(11) DEFAULT NULL,
  `deployed_at` timestamp NULL DEFAULT NULL,
  `decommissioned_by` int(11) DEFAULT NULL,
  `decommissioned_at` timestamp NULL DEFAULT NULL,
  `ip_addresses` longtext DEFAULT NULL COMMENT 'JSON array of assigned IP addresses',
  `hostname` varchar(255) DEFAULT NULL,
  `domain` varchar(255) DEFAULT NULL,
  `os_type` varchar(100) DEFAULT NULL,
  `os_version` varchar(100) DEFAULT NULL,
  `installed_software` longtext DEFAULT NULL COMMENT 'JSON array of installed software',
  `monitoring_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `backup_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_maintenance` timestamp NULL DEFAULT NULL,
  `next_maintenance` timestamp NULL DEFAULT NULL,
  `maintenance_notes` text DEFAULT NULL,
  `cpu_utilization` decimal(5,2) DEFAULT NULL COMMENT 'Average CPU utilization percentage',
  `memory_utilization` decimal(5,2) DEFAULT NULL COMMENT 'Average memory utilization percentage',
  `storage_utilization` decimal(5,2) DEFAULT NULL COMMENT 'Average storage utilization percentage',
  `uptime_percentage` decimal(5,2) DEFAULT NULL COMMENT 'Uptime percentage',
  `monthly_cost` decimal(10,2) DEFAULT NULL,
  `annual_cost` decimal(12,2) DEFAULT NULL,
  `cost_center` varchar(100) DEFAULT NULL,
  `budget_code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `storageinventory`
--

CREATE TABLE `storageinventory` (
  `ID` int(11) NOT NULL,
  `UUID` varchar(36) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where storage is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
  `WarrantyEndDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `storageinventory`
--

INSERT INTO `storageinventory` (`ID`, `UUID`, `SerialNumber`, `Status`, `ServerUUID`, `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`, `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 'ee9b23f6-5960-4691-b411-e81987c12da0', 'SSD123456', 2, '03b02d6f-1690-47ef-98f1-1a04bfa6e6f2', 'Datacenter North', 'Rack A3-12', '2023-05-12', '2023-06-01', '2026-05-12', 'Production', 'Samsung 980 PRO 2TB NVMe', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(2, '9ee83441-f1dc-4ac1-82dd-6319b0725737', 'HDD789012', 1, NULL, 'Warehouse East', 'Shelf D1', '2024-01-05', NULL, '2027-01-05', 'Backup', 'Seagate IronWolf 8TB NAS HDD', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(3, 'e383d2a8-6ce7-46af-8ead-73f2f2921545', 'SSD987654', 0, NULL, 'Disposal', 'Bin 3', '2020-08-10', '2020-09-01', '2023-08-10', 'Decommissioned', 'WD Black 1TB NVMe - SMART errors detected', '2025-05-11 11:42:52', '2025-05-11 11:42:52'),
(4, '43e1ad0d-cf4a-49c9-a750-b50f73e773f7', 'HDD789098', 1, 'null', 'New Delhi, Delhi', 'Rack Z9', '2025-07-30', NULL, '2029-10-25', 'Backup', 'Type: HDD, Capacity: 960GB\n\nAdditional Notes: crucial nvme gen 4 ', '2025-07-27 14:06:01', '2025-07-27 14:06:01');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(6) UNSIGNED NOT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `username` varchar(30) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `firstname`, `lastname`, `username`, `password`, `email`, `created_at`) VALUES
(2, NULL, NULL, 'anshit_231', '$2y$10$49afdS31qberiejobMDGq.2bO.7Apsxn/0NvsdWo.QDHdRqpcyx6W', 'johnmater842002@gmail.com', '2025-03-24 17:57:43'),
(3, NULL, NULL, 'testuser', '$2y$10$e1YluvO9QmuYJ7MFXkzzW.27HOihwL51ygolzGfF1CucYloqOwUxS', 'test@example.com', '2025-04-09 07:15:22'),
(4, NULL, NULL, 'anurag', '$2y$10$IaC5Ck4aoiAtkv7q8nSDsurxfDQuH08ycbg3jYaYVe.3cyF8Mx/J6', 'anurag@example.com', '2025-04-09 10:54:03'),
(5, NULL, NULL, 'admin', '$2y$10$KOv6I6jirzJAoaJKSfRIp.4YRqxunh7o3cLJp3bV2cbXNE1uvHFQq', 'admin@example.com', '2025-05-08 12:44:51'),
(25, 'shubham', 'gurjar', 'a', '$2y$10$Cc7EaZcYgyUm0qzHTCOSmu.cxdtEm/UxHEfsqZAMiXgIItUKUiT/G', 'a@example.com', '2025-05-16 08:52:04'),
(26, 'a', 'a', 'aaa', '$2y$10$L3CpwB1bDWzcAsumdfdvv.31pEvl/utXjUsbGNmZ7j/Q0JPqVmjeq', 'patel69@gmail.com', '2025-05-25 11:36:13'),
(27, 'Admin', 'Test', 'admin_test', '$2y$10$hash_here', 'admin@test.com', '2025-06-02 17:08:53'),
(28, 'Tech', 'Test', 'tech_test', '$2y$10$hash_here', 'tech@test.com', '2025-06-02 17:08:53'),
(29, 'Viewer', 'Test', 'viewer_test', '$2y$10$hash_here', 'viewer@test.com', '2025-06-02 17:08:53'),
(37, 'John', 'Administrator', 'johnadmin', '$2y$10$zqBAUSMszh.D.Bl.pu.RO./wFf64RZqNWYVcW8hFSIKDpytWxhJC2', 'john.admin@company.com', '2025-06-18 11:03:56'),
(38, 'Super', 'Administrator', 'superadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin@yourcompany.com', '2025-07-25 07:03:57'),
(39, 'Shubham', 'Gurjar', 'Shubham', '$2y$10$UqRHeAJPqAdzbhg1hwL/l.Q9tKgRGQNVVRqSIteI4nwmcpGwb3hYW', 'shubham@bharatdatacenter.com', '2025-07-29 03:38:57');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL,
  `user_id` int(6) UNSIGNED NOT NULL,
  `role_id` int(11) NOT NULL,
  `assigned_by` int(6) UNSIGNED DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`id`, `user_id`, `role_id`, `assigned_by`, `assigned_at`) VALUES
(1, 37, 5, NULL, '2025-07-25 00:20:53'),
(2, 25, 5, NULL, '2025-07-25 01:27:40'),
(3, 26, 5, NULL, '2025-07-25 01:27:40'),
(4, 5, 5, NULL, '2025-07-25 01:27:40'),
(5, 27, 5, NULL, '2025-07-25 01:27:40'),
(6, 2, 5, NULL, '2025-07-25 01:27:40'),
(7, 4, 5, NULL, '2025-07-25 01:27:40'),
(8, 28, 5, NULL, '2025-07-25 01:27:40'),
(9, 3, 5, NULL, '2025-07-25 01:27:40'),
(10, 29, 5, NULL, '2025-07-25 01:27:40'),
(17, 38, 1, NULL, '2025-07-25 07:04:41'),
(18, 37, 2, 38, '2025-07-29 03:23:32'),
(20, 39, 5, NULL, '2025-07-29 03:38:57');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_server_config_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_server_config_summary` (
`id` int(11)
,`config_uuid` varchar(36)
,`server_name` varchar(255)
,`configuration_status` tinyint(1)
,`cpu_uuid` varchar(36)
,`motherboard_uuid` varchar(36)
,`total_cost` decimal(12,2)
,`compatibility_score` decimal(3,2)
,`created_by` int(6) unsigned
,`created_at` timestamp
,`status_name` varchar(9)
,`created_by_username` varchar(30)
);

-- --------------------------------------------------------

--
-- Structure for view `v_server_config_summary`
--
DROP TABLE IF EXISTS `v_server_config_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`cpses_shm8oquv6t`@`localhost` SQL SECURITY DEFINER VIEW `v_server_config_summary`  AS SELECT `sc`.`id` AS `id`, `sc`.`config_uuid` AS `config_uuid`, `sc`.`server_name` AS `server_name`, `sc`.`configuration_status` AS `configuration_status`, `sc`.`cpu_uuid` AS `cpu_uuid`, `sc`.`motherboard_uuid` AS `motherboard_uuid`, `sc`.`total_cost` AS `total_cost`, `sc`.`compatibility_score` AS `compatibility_score`, `sc`.`created_by` AS `created_by`, `sc`.`created_at` AS `created_at`, CASE WHEN `sc`.`configuration_status` = 0 THEN 'Draft' WHEN `sc`.`configuration_status` = 1 THEN 'Validated' WHEN `sc`.`configuration_status` = 2 THEN 'Built' WHEN `sc`.`configuration_status` = 3 THEN 'Deployed' END AS `status_name`, `u`.`username` AS `created_by_username` FROM (`server_configurations` `sc` left join `users` `u` on(`sc`.`created_by` = `u`.`id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `acl_permissions`
--
ALTER TABLE `acl_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_name` (`permission_name`);

--
-- Indexes for table `acl_roles`
--
ALTER TABLE `acl_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `fk_user` (`user_id`);

--
-- Indexes for table `caddyinventory`
--
ALTER TABLE `caddyinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `UUID` (`UUID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `idx_caddy_status` (`Status`);

--
-- Indexes for table `compatibility_log`
--
ALTER TABLE `compatibility_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session_id` (`session_id`),
  ADD KEY `idx_operation_type` (`operation_type`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `compatibility_rules`
--
ALTER TABLE `compatibility_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rule_name` (`rule_name`),
  ADD KEY `idx_rule_type` (`rule_type`),
  ADD KEY `idx_rule_priority` (`rule_priority`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `component_compatibility`
--
ALTER TABLE `component_compatibility`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_compatibility` (`component_type_1`,`component_uuid_1`,`component_type_2`,`component_uuid_2`),
  ADD KEY `idx_comp_type_1` (`component_type_1`),
  ADD KEY `idx_comp_type_2` (`component_type_2`),
  ADD KEY `idx_comp_uuid_1` (`component_uuid_1`),
  ADD KEY `idx_comp_uuid_2` (`component_uuid_2`),
  ADD KEY `idx_compatibility_status` (`compatibility_status`);

--
-- Indexes for table `component_specifications`
--
ALTER TABLE `component_specifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_component_spec` (`component_uuid`,`specification_key`),
  ADD KEY `idx_component_uuid` (`component_uuid`),
  ADD KEY `idx_component_type` (`component_type`),
  ADD KEY `idx_specification_key` (`specification_key`),
  ADD KEY `idx_is_searchable` (`is_searchable`),
  ADD KEY `idx_is_comparable` (`is_comparable`),
  ADD KEY `idx_verified_by` (`verified_by`);

--
-- Indexes for table `component_usage_tracking`
--
ALTER TABLE `component_usage_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_component_uuid` (`component_uuid`),
  ADD KEY `idx_component_type` (`component_type`),
  ADD KEY `idx_config_uuid` (`config_uuid`),
  ADD KEY `idx_deployment_uuid` (`deployment_uuid`),
  ADD KEY `idx_usage_status` (`usage_status`),
  ADD KEY `idx_assigned_by` (`assigned_by`),
  ADD KEY `idx_assigned_at` (`assigned_at`);

--
-- Indexes for table `cpuinventory`
--
ALTER TABLE `cpuinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `UUID` (`UUID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `idx_cpu_status` (`Status`);

--
-- Indexes for table `inventory_log`
--
ALTER TABLE `inventory_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `jwt_blacklist`
--
ALTER TABLE `jwt_blacklist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `jti` (`jti`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `motherboardinventory`
--
ALTER TABLE `motherboardinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `UUID` (`UUID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `idx_motherboard_status` (`Status`);

--
-- Indexes for table `nicinventory`
--
ALTER TABLE `nicinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `UUID` (`UUID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `idx_nic_status` (`Status`);

--
-- Indexes for table `pciecardinventory`
--
ALTER TABLE `pciecardinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `UUID` (`UUID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `idx_pciecard_status` (`Status`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_permission_name` (`name`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_is_basic` (`is_basic`),
  ADD KEY `idx_permissions_category_basic` (`category`,`is_basic`);

--
-- Indexes for table `raminventory`
--
ALTER TABLE `raminventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `UUID` (`UUID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `idx_ram_status` (`Status`);

--
-- Indexes for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_role_name` (`name`),
  ADD KEY `idx_is_default` (`is_default`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_permission` (`role_id`,`permission_id`),
  ADD KEY `idx_role_id` (`role_id`),
  ADD KEY `idx_permission_id` (`permission_id`),
  ADD KEY `idx_role_permissions_lookup` (`role_id`,`permission_id`,`granted`);

--
-- Indexes for table `server_build_templates`
--
ALTER TABLE `server_build_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `template_uuid` (`template_uuid`),
  ADD KEY `idx_template_uuid` (`template_uuid`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_is_public` (`is_public`),
  ADD KEY `idx_use_count` (`use_count`),
  ADD KEY `idx_parent_template` (`parent_template_id`);

--
-- Indexes for table `server_configurations`
--
ALTER TABLE `server_configurations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `config_uuid` (`config_uuid`),
  ADD UNIQUE KEY `idx_config_uuid_unique` (`config_uuid`),
  ADD KEY `idx_config_status` (`configuration_status`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_cpu_id` (`cpu_id`),
  ADD KEY `idx_motherboard_id` (`motherboard_id`);

--
-- Indexes for table `server_configuration_components`
--
ALTER TABLE `server_configuration_components`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_config_component` (`config_uuid`,`component_type`,`component_uuid`),
  ADD KEY `idx_config_uuid` (`config_uuid`),
  ADD KEY `idx_component_type` (`component_type`),
  ADD KEY `idx_component_uuid` (`component_uuid`),
  ADD KEY `idx_added_at` (`added_at`);

--
-- Indexes for table `server_configuration_history`
--
ALTER TABLE `server_configuration_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_config_uuid` (`config_uuid`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `server_deployments`
--
ALTER TABLE `server_deployments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `deployment_uuid` (`deployment_uuid`),
  ADD KEY `idx_deployment_uuid` (`deployment_uuid`),
  ADD KEY `idx_config_uuid` (`config_uuid`),
  ADD KEY `idx_environment` (`environment`),
  ADD KEY `idx_deployment_status` (`deployment_status`),
  ADD KEY `idx_deployed_by` (`deployed_by`),
  ADD KEY `idx_hostname` (`hostname`),
  ADD KEY `idx_location` (`location`);

--
-- Indexes for table `storageinventory`
--
ALTER TABLE `storageinventory`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `UUID` (`UUID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `idx_storage_status` (`Status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_permission` (`user_id`,`permission_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_role_id` (`role_id`),
  ADD KEY `fk_user_roles_assigned_by` (`assigned_by`),
  ADD KEY `idx_user_roles_lookup` (`user_id`,`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `acl_permissions`
--
ALTER TABLE `acl_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `acl_roles`
--
ALTER TABLE `acl_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=97;

--
-- AUTO_INCREMENT for table `caddyinventory`
--
ALTER TABLE `caddyinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `compatibility_log`
--
ALTER TABLE `compatibility_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `compatibility_rules`
--
ALTER TABLE `compatibility_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `component_compatibility`
--
ALTER TABLE `component_compatibility`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `component_specifications`
--
ALTER TABLE `component_specifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `component_usage_tracking`
--
ALTER TABLE `component_usage_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cpuinventory`
--
ALTER TABLE `cpuinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `inventory_log`
--
ALTER TABLE `inventory_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

--
-- AUTO_INCREMENT for table `jwt_blacklist`
--
ALTER TABLE `jwt_blacklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `motherboardinventory`
--
ALTER TABLE `motherboardinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `nicinventory`
--
ALTER TABLE `nicinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pciecardinventory`
--
ALTER TABLE `pciecardinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49861;

--
-- AUTO_INCREMENT for table `raminventory`
--
ALTER TABLE `raminventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4387;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22989;

--
-- AUTO_INCREMENT for table `server_build_templates`
--
ALTER TABLE `server_build_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `server_configurations`
--
ALTER TABLE `server_configurations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `server_configuration_components`
--
ALTER TABLE `server_configuration_components`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `server_configuration_history`
--
ALTER TABLE `server_configuration_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `server_deployments`
--
ALTER TABLE `server_deployments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storageinventory`
--
ALTER TABLE `storageinventory`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD CONSTRAINT `fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_log`
--
ALTER TABLE `inventory_log`
  ADD CONSTRAINT `inventory_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `refresh_tokens`
--
ALTER TABLE `refresh_tokens`
  ADD CONSTRAINT `fk_refresh_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `server_configurations`
--
ALTER TABLE `server_configurations`
  ADD CONSTRAINT `fk_server_config_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_user_roles_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
