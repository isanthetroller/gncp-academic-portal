# 🗄️ GNCP Academic Database Guide

This folder contains the central database schemas and connection specifications for the Go-on National College of the Philippines (GNCP) academic systems.

---

## ⚙️ Connection Configurations

All system components (Online Wizard, Validation Workstations, Registrar Dashboard) connect using the following properties:
*   **Database Host:** `localhost` (127.0.0.1)
*   **Database Username:** `root`
*   **Database Password:** `""` (Empty - default XAMPP configuration)
*   **Database Name:** `gncp_portal`
*   **Charset:** `utf8mb4`

---

## 🚀 How to Setup (XAMPP & phpMyAdmin)

Follow these simple steps to initialize the database:

1.  Open the **XAMPP Control Panel** on your computer.
2.  Start the **Apache** and **MySQL** modules (click the "Start" buttons next to them).
3.  Open your web browser and go to:  
    👉 `http://localhost/phpmyadmin/`
4.  In phpMyAdmin, click on **Import** in the top navigation tab.
5.  Click **Choose File** (or Browse) and select the consolidated schema file:  
    📂 **`database/schema.sql`**
6.  Scroll down to the bottom and click the **Import** (or Go) button.

The script will automatically create the database `gncp_portal`, initialize all tables (enrollments, courses, students, class sections), add the JSON progression data structures, and pre-seed mock records.
