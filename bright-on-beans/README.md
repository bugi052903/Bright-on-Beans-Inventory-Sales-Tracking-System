# Bright on Beans - Coffee House Inventory and Sales System

A complete Point of Sale and Inventory Tracking system built for Bright on Beans Coffee House. Features include automatic ingredient deduction based on recipe, low stock alerts, user role management, and sales reporting.

## Prerequisites
- XAMPP (Apache + MySQL / MariaDB)
- PHP 7.4 or higher

## Setup Instructions

1. **Copy Files**: Place the `bright-on-beans` folder inside your XAMPP `htdocs` directory (`C:\xampp\htdocs\bright-on-beans`).
2. **Start Servers**: Open XAMPP Control Panel and start **Apache** and **MySQL**.
3. **Run Installer**: Open your web browser and go to:
   `http://localhost/bright-on-beans/install.php`
4. **Install**: Click the "Install System" button. This will automatically create the database (`bright_on_beans`), required tables, and the default administrator account.
5. **Login**: 
   - Username: `admin`
   - Password: `admin123`
6. **Security Cleanup**: After successful installation, delete the `install.php` file from the root directory to prevent unauthorized resets.

### Existing installations

The application creates the tables and ingredient archive field needed for batch-specific expiration tracking when the sales pages or related APIs are first used. Alternatively, you can import `database/migrations/20261031_track_active_stock_batches.sql` into the `bright_on_beans` database with phpMyAdmin or the MySQL command line. Existing active stock keeps its current expiration date; newly staged pallet stock is tracked separately by batch. Deleting an out-of-stock ingredient that is still used in a recipe archives it so the recipe remains intact.

## Features
- **Role-based Access**: Admin and Staff accounts with specific permissions.
- **Point of Sale (POS)**: Intuitive cart system for processing customer orders.
- **Automatic Ingredient Deduction (AID)**: Sales automatically update stock levels based on product recipes.
- **Batch Expiration Safety**: Checkout deducts only unexpired ingredient batches and keeps newly staged stock separate from older active stock.
- **Inventory Management**: Track ingredients, units, minimum levels, and manual stock adjustments.
- **Reports**: Daily, weekly, and monthly sales charts, plus staff performance tracking.
- **Low Stock Alerts**: Warnings appear when ingredient stocks fall below defined minimums.
