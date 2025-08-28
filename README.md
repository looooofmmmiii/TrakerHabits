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
```bash
git clone https://your-repo/TrackerHabits.git
cd TrackerHabits
