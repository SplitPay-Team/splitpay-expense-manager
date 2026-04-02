# SplitPay — Group Expense Sharing & Settlement System

> A full-stack PHP/MySQL web application for managing, tracking, and settling shared expenses among groups.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | HTML5, CSS3, Vanilla JavaScript (no frameworks) |
| Backend | PHP 8.x with PDO |
| Database | MySQL 8 (InnoDB) |
| Auth | PHP Sessions + bcrypt |
| Hosting | Standard shared hosting / Apache |

---

## Quick Start

### 1. Upload Files

Upload the entire `SPLIT_PAY/` folder to your web server's document root (or a subdirectory).

### 2. Configure Web Root

Point your virtual host document root to `/SPLIT_PAY/` (the folder containing `.htaccess`).

Make sure `mod_rewrite` is enabled on Apache.

### 3. Run Installation

Visit `http://yourdomain.com/install.php` in your browser.

Fill in your MySQL credentials and click **Run Installation**. The installer will:
- Create the `splitpay` database
- Run the full schema (8 tables)
- Write `config/config.php` with your credentials

### 4. Delete install.php

⚠️ **Important:** Delete `install.php` immediately after setup.

### 5. Register & Start

Visit `/pages/register.php` to create your first account, then sign in at `/pages/login.php`.

---

## Manual Database Setup

If you prefer, run the schema manually:

```bash
mysql -u your_user -p < schema.sql
```

Then copy and edit the config:

```bash
cp config/config.php config/config.php
# Edit config/config.php with your credentials
```

---

## File Structure

```
SPLIT_PAY/
├── assets/
│   ├── css/
│   │   └── style.css          # All styles — luxury dark theme
│   └── js/
│       └── main.js            # API helper, Toast, Modal, Form utilities
├── config/
│   └── config.php             # DB credentials (git-ignored)
├── includes/
│   ├── db.php                 # PDO singleton
│   ├── auth_check.php         # Session guard + CSRF helpers
│   ├── helpers.php            # jsonSuccess, jsonError, notify, money…
│   ├── api_auth.php           # register, login, logout, update_profile
│   ├── api_groups.php         # create, list, add_member, remove_member
│   ├── api_projects.php       # create, list
│   ├── api_expenses.php       # add, edit, confirm/reject, list
│   ├── api_settlements.php    # settle (greedy algorithm), report
│   └── api_notifications.php  # list, mark_read
├── templates/
│   ├── header.php             # Sidebar + topbar layout header
│   ├── footer.php             # Scripts + closing tags
│   ├── login.html             # Sign-in page
│   ├── register.html          # Registration page
│   ├── dashboard.php          # User home — groups + pending actions
│   ├── group.php              # Group detail — members + projects
│   ├── group-create.php       # New group form
│   ├── project.php            # Project detail — expenses + settle
│   ├── expense-detail.php     # Full expense view + confirm/reject
│   ├── notifications.php      # Paginated notification inbox
│   └── profile.php            # Account settings + password change
├── logs/                      # (empty, writable by web server)
├── schema.sql                 # Full MySQL DDL — 8 tables
├── .htaccess                  # URL routing + security headers
├── .gitignore                 # Excludes config.php and logs/
├── install.php                # One-click installer (delete after use)
└── README.md                  # This file
```

---

## Pages & Routes

| URL | Page | Access |
|-----|------|--------|
| `/pages/login.php` | Sign In | Public |
| `/pages/register.php` | Create Account | Public |
| `/pages/dashboard.php` | Dashboard | Auth |
| `/pages/group.php?id=N` | Group Detail | Member |
| `/pages/group-create.php` | New Group | Auth |
| `/pages/project.php?id=N` | Project Detail | Member |
| `/pages/expense-detail.php?id=N` | Expense Detail | Participant |
| `/pages/notifications.php` | Notification Inbox | Auth |
| `/pages/profile.php` | Profile Settings | Auth |

---

## API Endpoints

All endpoints are PHP files proxied via `.htaccess`. All state-changing requests require a valid CSRF token.

| Method | URL | Action |
|--------|-----|--------|
| POST | `/api/auth.php?action=register` | Register |
| POST | `/api/auth.php?action=login` | Login |
| POST | `/api/auth.php?action=logout` | Logout |
| POST | `/api/auth.php?action=update_profile` | Update name |
| POST | `/api/auth.php?action=change_password` | Change password |
| GET | `/api/groups.php?action=list` | List my groups |
| POST | `/api/groups.php?action=create` | Create group |
| POST | `/api/groups.php?action=add_member` | Add member |
| POST | `/api/groups.php?action=remove_member` | Remove member |
| GET | `/api/groups.php?action=search_users` | User search |
| GET | `/api/projects.php?action=list&group_id=N` | List projects |
| POST | `/api/projects.php?action=create` | Create project |
| GET | `/api/expenses.php?action=list&project_id=N` | List expenses |
| POST | `/api/expenses.php?action=add` | Add expense |
| POST | `/api/expenses.php?action=edit` | Edit expense |
| POST | `/api/expenses.php?action=confirm` | Confirm/reject |
| POST | `/api/settlements.php?action=settle` | Settle project |
| GET | `/api/settlements.php?action=report&project_id=N` | Settlement report |
| GET | `/api/notifications.php?action=list` | List notifications |
| POST | `/api/notifications.php?action=mark_read` | Mark as read |

---

## Settlement Algorithm

The system uses a **greedy debt-minimisation algorithm** (SRS §16) that produces the minimum number of transactions:

1. Collect all confirmed expenses for the project
2. Compute total amount each user **paid**
3. Compute each user's fair **share** (amount ÷ participant count per expense)
4. Calculate **net balance** = paid − owed
5. Separate into creditors (+) and debtors (−)
6. Greedily match largest debtor with largest creditor, record payment, repeat
7. Persist results to `settlements` table; mark project settled

**Example:** Alice pays $90 (3 people), Bob pays $60 (2 people) → only 1 transaction needed: Carol pays Alice $60.

---

## Security

- Passwords hashed with `password_hash()` / `PASSWORD_BCRYPT`
- All SQL via PDO prepared statements — no raw concatenation
- CSRF tokens on every state-changing form
- `htmlspecialchars(ENT_QUOTES)` on all output
- Session regeneration on login (`session_regenerate_id(true)`)
- `Content-Security-Policy` header via `.htaccess`
- Config excluded from VCS via `.gitignore`
- API endpoints verify role server-side on every request

---

## Requirements

- PHP 8.0+
- MySQL 8.0+ or MariaDB 10.5+
- Apache with `mod_rewrite` enabled
- PDO and PDO_MySQL PHP extensions

---

*SplitPay v1.0 — University Web Technology Project · March 2026*
