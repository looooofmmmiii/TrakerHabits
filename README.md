# TrackerHabits

**TrackerHabits** is a lightweight, minimal habit tracker web app designed to help users create, track, and improve daily routines. The focus is on **simplicity**, a clear user experience, and reliable database logic — so users can quickly mark habits as done, view progress, and stay motivated.

---

## ✨ Features
- Add / Update / Delete habits (title, description, frequency).  
- Mark daily progress with **Mark Completed** buttons on the Dashboard.  
- Dashboard quick stats: **Total Habits** and **Completed Today**.  
- Progress overview with recent completion percentages.  
- Secure session-based auth and DB access via PDO prepared statements.  
- Clean, extensible frontend (ready for AJAX or SPA refactor).  

---

## 🛠 Tech Stack
- **PHP (vanilla)** — backend logic & routing  
- **MySQL / MariaDB** — database  
- **HTML / CSS** — simple responsive UI  
- Optional: **Docker** / **VirtualBox** for local development  

---

## 📋 Requirements
- PHP 8.x or newer  
- MySQL / MariaDB  
- Web server (Apache, Nginx, or PHP built-in server)  
- (Optional) Composer if adding third-party libraries  

---

## ⚙️ Installation & Setup

### 1. Clone the repository
bash


git clone https://your-repo/TrackerHabits.git
cd TrackerHabits

```
CREATE TABLE `habits` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `frequency` enum('daily','weekly','monthly') NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `habit_tracking` (
  `id` int NOT NULL,
  `habit_id` int NOT NULL,
  `track_date` date NOT NULL,
  `completed` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `kanban_columns` (
  `id` int NOT NULL,
  `workspace_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `kanban_tasks` (
  `id` int NOT NULL,
  `column_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `kanban_workspaces` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `tasks` (
  `id` int NOT NULL,
  `title` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `is_done` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `priority` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `users` (
  `id` int NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `user_remember_tokens` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `selector` char(18) NOT NULL,
  `validator_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_agent` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

