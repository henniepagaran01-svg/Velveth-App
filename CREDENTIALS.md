# Velveth Lending v17 — Login Credentials
## Default Passwords (all accounts use: password)

### Admin Account
| Field    | Value                  |
|----------|------------------------|
| URL      | /admin_login.php       |
| Email    | admin@velveth.com      |
| Password | **password**           |

### Sample Customer Accounts
| Name           | Email                | Password     |
|----------------|----------------------|--------------|
| Harley Armand  | harley@email.com     | **password** |
| Hannie Stroha  | hannie@email.com     | **password** |
| Jenny Williams | jenny@email.com      | **password** |
| Boldem Johnson | boldem@email.com     | **password** |
| Danta Grunda   | danta@email.com      | **password** |

> All seeded passwords use Laravel's default `password` hash for `password_verify()`.
> The hash is: `$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi`

---

## v17 Bug Fixes Applied
- **HTTP 500 on Register** — Fixed: `sendGmailNotification()` was called before
  `gmail_notify.php` was loaded. Now loaded via `config.php` and wrapped with
  `function_exists()` + `@` to suppress fatal errors even if PHPMailer is missing.
- **Missing columns on register** — Fixed: Added `IF EXISTS` column detection so
  registration works even on original v16 schema that lacks `date_of_birth` etc.
- **Old font (DM Sans/Playfair)** — Replaced: All pages now use **Inter** (Google Fonts).
- **Not responsive on mobile** — Fixed: New `shared.css` with full breakpoints
  (phone ≤640, tablet ≤1024, desktop +), hamburger sidebar toggle, mobile bottom nav.

## Gmail / PHPMailer Setup
Edit `gmail_notify.php`:
1. Set `GMAIL_USERNAME` = your Gmail address
2. Set `GMAIL_APP_PASSWORD` = 16-char App Password from Google Account settings
3. Enable 2-Step Verification on your Google account first
4. App Passwords: https://myaccount.google.com/apppasswords
