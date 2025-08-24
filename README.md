# TrackerHabits

**TrackerHabits** — a lightweight, easy-to-use habit tracker web app designed to help users manage, track and improve daily routines. Focused on simplicity, clear UX, and reliable database logic so users can quickly mark habits as done, view progress, and stay motivated.

---

## Features
- Add / Update / Delete habits (title, description, frequency).
- Mark daily progress with **Mark Completed** buttons on the Dashboard.
- Dashboard with quick stats: **Total Habits** and **Completed Today**.
- Progress overview (percent completion for recent days) for consistency insight.
- Session-based auth and secure DB access via PDO prepared statements.
- Clean, extensible frontend ready for AJAX upgrades or SPA refactor.

---

## Tech stack
- PHP (vanilla) — backend logic and routing  
- MySQL / MariaDB — database (with PDO)  
- HTML / CSS — UI (simple, responsive)  
- Optional: Docker / VirtualBox for local dev environment

---

## Requirements
- PHP 8.x or newer  
- MySQL / MariaDB  
- Web server (Apache, Nginx) or PHP built-in server for local testing  
- (Optional) Composer if you add third-party libraries

---

## Installation & Setup

1. Clone the repo:
    
    ```bash
    git clone https://your-repo/TrackerHabits.git
    cd TrackerHabits
    ```

2. Create the database and tables

### Create the database and set charset/collation
Use `utf8mb4` to support full Unicode (emoji, etc.).

    ```sql
    CREATE DATABASE trackerhabits CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    USE trackerhabits;
    ```

### Users table
Stores registered users. Passwords must be stored as hashed values (e.g. `password_hash()` in PHP).

    ```sql
    CREATE TABLE users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(255) UNIQUE NOT NULL,
      password_hash VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
    ```

**Notes:**
- `email` is unique to avoid duplicate accounts.  
- Use a secure hashing algorithm on registration (e.g., `password_hash()` / `PASSWORD_BCRYPT`).

### Habits table
Holds user habits and basic metadata.

    ```sql
    CREATE TABLE habits (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      title VARCHAR(255) NOT NULL,
      description TEXT,
      frequency ENUM('daily','weekly','custom') DEFAULT 'daily',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;
    ```

**Notes:**
- `ON DELETE CASCADE` ensures habits are removed when a user is deleted.  
- Consider indexing `user_id` for faster lookups (InnoDB creates an index automatically for FK).

### Habit tracking table
Tracks per-day completion for a habit. Unique constraint prevents duplicate entries per habit+date.

    ```sql
    CREATE TABLE habit_tracking (
      id INT AUTO_INCREMENT PRIMARY KEY,
      habit_id INT NOT NULL,
      track_date DATE NOT NULL,
      completed TINYINT(1) DEFAULT 1,
      UNIQUE KEY unique_habit_date (habit_id, track_date),
      FOREIGN KEY (habit_id) REFERENCES habits(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;
    ```

**Notes:**
- `unique_habit_date` enables `ON DUPLICATE KEY UPDATE` pattern to mark completed without duplicate rows.  
- `completed` allows storing 0/1 (useful if you later add unmarking).

### Optional: seed data
    ```sql
    INSERT INTO users (email, password_hash) VALUES ('test@example.com', '<hashed_password>');
    INSERT INTO habits (user_id, title, description, frequency) VALUES (1, 'Read 20 pages', 'Read a book daily', 'daily');
    INSERT INTO habit_tracking (habit_id, track_date, completed) VALUES (1, '2025-08-24', 1);
    ```

### Indexing & performance tips
- Add an index on `habit_tracking(track_date)` if you query by date ranges often:
    
    ```sql
    CREATE INDEX idx_track_date ON habit_tracking(track_date);
    ```
- For progress queries by user over ranges, a composite index combining `habit_id, track_date` helps.  
- Use `EXPLAIN` to profile heavy queries and add indexes as needed.

---

3. Configure DB connection  
Copy `config/db.example.php` → `config/db.php` and set credentials:

    ```php
    <?php
    $host = '127.0.0.1';
    $db   = 'trackerhabits';
    $user = 'dbuser';
    $pass = 'dbpass';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
    ```

---

4. Run app locally

    ```bash
    php -S localhost:8000 -t public
    ```
Open `http://localhost:8000/dashboard.php`.

---

## Project structure
TrackerHabits/
├─ public/ # optional web root
├─ config/
│ └─ db.php
├─ functions/
│ └─ habit_functions.php # core functions: getHabits, addHabit, trackHabit, getHabitProgress, deleteHabit
├─ auth/ # login/register flows
├─ dashboard.php
├─ habits.php
└─ README.md


---

## Core functions (summary)
Located in `functions/habit_functions.php`:

- `getHabits($user_id)` — returns all habits for a user.  
- `addHabit($user_id, $title, $description, $frequency)` — create a habit.  
- `updateHabit($habit_id, $title, $description, $frequency)` — edit a habit.  
- `deleteHabit($habit_id)` — delete a habit.  
- `trackHabit($habit_id, $date)` — mark habit completed for a date (uses `ON DUPLICATE KEY UPDATE`).  
- `getHabitProgress($user_id)` — fetch joined habit + tracking rows for progress view.

---

## How to use
1. Register and log in (session-based).  
2. Go to **Manage Habits** to add habits with title, description, and frequency.  
3. Open **Dashboard** to see habits for today and press **Mark Completed** to track completion.  
4. To add unmarking, implement `untrackHabit($habit_id, $date)` (see roadmap).

---

## Recommended improvements / Roadmap
- **AJAX Mark Completed**: convert the POST toggle to AJAX for instant UI update without page reload.  
- **Unmark (toggle)**: add `untrackHabit()` and a toggle button.  
- **Streaks & reminders**: add streak calculation and optional email / push reminders.  
- **Charts & analytics**: weekly/monthly charts (Chart.js) and CSV export.  
- **Auth hardening**: password reset, email verification, rate limiting.  
- **Dockerize**: provide `docker-compose` for reproducible dev/prod environments.

---

## Contributing
Contributions welcome! Please:
- Fork repo, open a PR, and include tests for critical DB logic where possible.  
- Keep functions single-purpose and use prepared statements for DB security.  
- Document any DB/schema changes in migrations or SQL files.

---

## License
MIT License — free for personal and educational use. For commercial adaptation, please retain attribution and review license terms.
