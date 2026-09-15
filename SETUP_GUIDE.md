# 🚀 GNCP Enrollment System Setup & Running Guide

This guide will walk you through setting up and running the Go-on National College of the Philippines (GNCP) Enrollment System on a laptop using XAMPP.

---

## 🛠️ Step-by-Step Installation

### Step 1: Install XAMPP
Ensure that **XAMPP** is installed on the laptop. If not:
1. Download XAMPP for Windows (with PHP 8.1 or higher) from [Apache Friends](https://www.apachefriends.org/).
2. Run the installer and finish the setup (default path is usually `C:\xampp`).

---

### Step 2: Copy the Project Files
1. Extract your zipped folder.
2. Make sure the folder is named **`systemforsia`**.
3. Move/Copy this entire folder into the XAMPP web directory:
    👉 **`C:\xampp\htdocs\systemforsia`**

> ⚠️ **CRITICAL WARNING — THE GITHUB ZIP NAME BUG:**
> When you download this repository as a ZIP from GitHub, it extracts as a folder named **`systemforsia-main`** by default.
> * If you keep the folder name as **`systemforsia-main`**, all URLs will change to: `http://localhost/systemforsia-main/...`
> * **RECOMMENDED FIX:** Rename the extracted folder inside `htdocs` from `systemforsia-main` to **`systemforsia`** so it matches all standard URLs.
> * Make sure you do **NOT** have a double folder nesting (e.g., `C:\xampp\htdocs\systemforsia\systemforsia\index.html`).
>
> The correct path layout must look exactly like this:
> - `C:\xampp\htdocs\systemforsia\school-website\index.html`
> - `C:\xampp\htdocs\systemforsia\enrollment-system\index.html`
> - `C:\xampp\htdocs\systemforsia\stations\backend\api.php`

---

### Step 3: Start XAMPP Services
1. Open the **XAMPP Control Panel** application.
2. Click the **Start** buttons next to **Apache** and **MySQL**.
3. Verify that both modules turn green.

---

### Step 4: Import the Database
1. Open your web browser and go to: **[http://localhost/phpmyadmin/](http://localhost/phpmyadmin/)**
2. Click on the **Import** tab in the top menu bar.
3. Click **Choose File** and navigate to your project database folder:
   📂 `C:\xampp\htdocs\systemforsia\database\schema.sql`
4. Scroll to the bottom of the page and click **Import** (or **Go**).
5. phpMyAdmin will report success, creating the database `gncp_portal` and all required tables automatically.

---

## 🔗 System Access Links (URLs)

Once Apache and MySQL are running, open your web browser to access the system:

| Module / Station | Browser URL |
| :--- | :--- |
| **🌐 GNCP Public Website** | [http://localhost/systemforsia/school-website/index.html](http://localhost/systemforsia/school-website/index.html) |
| **📝 Student Enrollment Portal** | [http://localhost/systemforsia/enrollment-system/index.html](http://localhost/systemforsia/enrollment-system/index.html) |
| **🔑 1. Registrar Verification Console** | [http://localhost/systemforsia/registrar/index.html](http://localhost/systemforsia/registrar/index.html) |
| **💬 2. Academic Advising** | [http://localhost/systemforsia/stations/tlc-helpdesk/index.html](http://localhost/systemforsia/stations/tlc-helpdesk/index.html) |
| **🏥 3. Medical Clinic** | [http://localhost/systemforsia/stations/medical-checkup/index.html](http://localhost/systemforsia/stations/medical-checkup/index.html) |
| **🎓 4. Scholarship Verification** | [http://localhost/systemforsia/stations/scholarship-verification/index.html](http://localhost/systemforsia/stations/scholarship-verification/index.html) |
| **💰 5. Cashier Payment** | [http://localhost/systemforsia/stations/payment-processing/index.html](http://localhost/systemforsia/stations/payment-processing/index.html) |
| **💻 6. IT Center (Portal Activation)** | [http://localhost/systemforsia/stations/it-center/index.html](http://localhost/systemforsia/stations/it-center/index.html) |
| **⚙️ System Admin Dashboard** | [http://localhost/systemforsia/admin/index.html](http://localhost/systemforsia/admin/index.html) |

---

## 🔑 Station Login Credentials

Here are the pre-configured accounts you can use to log into each workstation panel:

| Station | Username | Password |
| :--- | :--- | :--- |
| **⚙️ System Admin** | `admin` | `admin12345` |
| **📋 Registrar Verification** | `kriz` | `kriz123` |
| **💬 Academic Advising** | `tristan` | `tristan123` |
| **🏥 Medical Clinic** | `ethan` | `ethan123` |
| **🎓 Scholarship Verification** | `thirdy` | `thirdy123` |
| **💰 Cashier Payment** | `cashier` | `cashier123` |
| **💻 IT Center** | `it_officer` | `itpassword` |

---

## 💡 Troubleshooting & Common Issues

#### ❌ "Object not found! 404" or directory not found:
* Double-check your spelling in the URL.
* Check if your folder is named `systemforsia` (all lowercase, no spaces).
* Make sure it is inside `C:\xampp\htdocs\`.

#### ❌ "Database connection failed" error in stations:
* Make sure **MySQL** is started in the XAMPP Control Panel.
* Check that you successfully imported `database/schema.sql` into phpMyAdmin.

#### ❌ Apache server fails to start (Port conflict):
* If you have Skype, Zoom, or IIS running, they might block Port 80. Close those applications and try starting Apache again, or change Apache's port in XAMPP config.
