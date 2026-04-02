# SplitEase - Payment Management System

A comprehensive web application for managing shared expenses and tracking payments between users.

## Features

- User authentication (register/login)
- Create and manage payments
- Split expenses among multiple users
- Real-time balance tracking
- Settlement system with partial payments
- AJAX user search
- Responsive Material Design UI
- Dark theme with green accents

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)

## Installation

1. Upload all files to your web server
2. Navigate to `http://yourdomain.com/install.php`
3. Enter installation password (default: admin123)
4. Provide MySQL database credentials
5. Complete installation
6. Delete `install.php` for security

## File Structure
/
├── index.php # Main controller
├── install.php # Installation script
├── config.php # Database configuration (created during install)
├── .htaccess # URL rewriting and security
├── /assets/ # CSS, JS, images
├── /templates/ # HTML templates
├── /includes/ # PHP classes
└── /logs/ # Application logs

## Default Login

After installation, register a new user to start using the application.

## Security Notes

- Change the installation password in install.php
- Delete install.php after installation
- Use strong passwords
- Keep your config.php secure

## Support

For issues and questions, please check the documentation or contact support.
