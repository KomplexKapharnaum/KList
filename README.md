# 📮 KXKM Mailing List Manager

A lightweight, self-hosted mailing list manager built with **pure PHP 8** and **SQLite**. Perfect for small to medium organizations who want full control over their mailing lists without complex dependencies.

## ✨ Features

- **📋 Multiple mailing lists** - Create unlimited lists with individual settings
- **👥 Subscriber management** - Add, remove, search, and export subscribers
- **🛡️ Moderation** - Optional moderation for each list before forwarding
- **🚫 Blocklist** - Automatic blocking of bouncing emails
- **📧 Discussion lists** - Reply-to-all mode (expose recipients) or broadcast mode (BCC)
- **🔍 Subscriber search** - Find subscribers across all lists with partial matching
- **⚡ Live search** - Real-time filtering as you type
- **📊 Dashboard** - Overview with cron status, subscriber counts, and last activity
- **🌙 Dark theme** - Modern, eye-friendly interface
- **🔐 Secure** - CSRF protection, prepared statements, session security

## 📋 Requirements

- **PHP 8.0+** with extensions:
  - `imap` (for email fetching)
  - `sqlite3` or `pdo_sqlite`
  - `openssl` (for SMTP/IMAP TLS)
  - `mbstring` (recommended)
- **Web server** with `.htaccess` support (Apache/LiteSpeed) or equivalent nginx config
- **IMAP/SMTP access** to an email account for the lists

## 🚀 Quick Installation

### 1. Download

```bash
git clone https://github.com/your-org/mailing-list-manager.git listes
cd listes
```

Or download and extract the ZIP file.

### 2. Upload to Web Server

Upload the entire folder to your web hosting (via FTP, SFTP, or SSH).

**Example structure:**
```
public_html/
└── listes/           ← Upload here
    ├── index.php
    ├── config.php
    ├── cron.php
    ├── assets/
    ├── data/
    ├── lib/
    ├── src/
    ├── templates/
    └── tmp/
```

### 3. Set Permissions

```bash
# Ensure data and tmp directories are writable
chmod 755 data/
chmod 755 tmp/
chmod 644 data/.htaccess
chmod 644 tmp/.htaccess
```

### 4. First Access

Navigate to your installation URL (e.g., `https://yourdomain.com/listes/`)

**Default login credentials:**
- **Email:** `admin@example.com`
- **Password:** `changeme`

⚠️ **Change these immediately in Settings → Admin Credentials**

### 5. Configure Settings

In the Settings panel, configure:

| Setting | Description |
|---------|-------------|
| **IMAP Host** | Your mail server (e.g., `mail.yourdomain.com`) |
| **IMAP Port** | Usually `993` for SSL |
| **IMAP User** | The email account for lists (e.g., `listes@yourdomain.com`) |
| **IMAP Password** | Password for the email account |
| **SMTP Host** | Usually same as IMAP host |
| **SMTP Port** | Usually `587` for TLS |
| **SMTP User/Password** | Same as IMAP or different if needed |
| **Domains** | Comma-separated list of domains (e.g., `yourdomain.com,yourdomain.net`) |

### 6. Setup Cron Job

The cron job processes incoming emails and forwards approved messages.

**Using cPanel Cron:**
```bash
*/5 * * * * curl -s "https://yourdomain.com/listes/cron.php?key=YOUR_CRON_KEY" > /dev/null
```

**Using wget:**
```bash
*/5 * * * * wget -q -O /dev/null "https://yourdomain.com/listes/cron.php?key=YOUR_CRON_KEY"
```

**Find your cron key:** Settings → Cron Configuration

💡 **Tip:** Run every 5 minutes for near real-time processing, or every 15-30 minutes for lower priority lists.

## 📖 Usage Guide

### Creating a Mailing List

1. Click **"Nouvelle liste"** in the sidebar
2. Enter a **name** (lowercase, no spaces - becomes the email prefix)
3. Configure options:
   - **Modération** - Require approval before forwarding
   - **Liste de Discussion** - Recipients see each other (reply-to-all works)
   - **Active** - Enable/disable the list
4. Add subscribers (one per line, or comma/semicolon separated)

### How Email Processing Works

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   INBOX     │ ──▶ │   PENDING   │ ──▶ │  APPROVED   │
│ (incoming)  │     │ (moderated) │     │ (ready)     │
└─────────────┘     └─────────────┘     └─────────────┘
       │                                       │
       │ (auto-approved)                       │
       ▼                                       ▼
┌─────────────┐                        ┌─────────────┐
│   DONE      │ ◀────────────────────  │  Forward    │
│ (archived)  │                        │  to subs    │
└─────────────┘                        └─────────────┘
```

**Special IMAP folders created automatically:**
- `INBOX` - Incoming messages
- `PENDING` - Awaiting moderation
- `APPROVED` - Ready to forward
- `DONE` - Successfully sent
- `DISCARDED` - Rejected by moderator or unauthorized
- `ERRORS` - Bounce messages / errors
- `ARCHIVE` - Round-back prevention
- `OTHERS` - No matching list

### Unsubscribing

Users can unsubscribe by sending an email to the list with **STOP** as the subject.

Example: Send to `mylist@yourdomain.com` with subject `STOP`

### Searching Subscribers

The **Subscriber Search** page lets you:
- Find subscribers by partial email match
- See all lists a subscriber belongs to
- Unsubscribe from individual lists or all at once

## 📁 Directory Structure

```
listes/
├── index.php           # Main entry point & router
├── config.php          # Configuration & helpers
├── cron.php            # Email processing endpoint
├── .htaccess           # Security rules
├── .gitignore          # Git ignore rules
│
├── data/               # SQLite database (protected)
│   ├── .htaccess       # Deny all access
│   └── listes.db       # Database file (auto-created)
│
├── src/                # PHP classes (protected)
│   ├── Auth.php        # Authentication
│   ├── Database.php    # SQLite wrapper
│   ├── ListManager.php # List operations
│   ├── BlocklistManager.php
│   └── MailProcessor.php  # Email logic
│
├── templates/          # HTML templates (protected)
│   ├── layout.php      # Base layout
│   ├── dashboard.php
│   ├── login.php
│   ├── list-edit.php
│   ├── subscribers.php
│   ├── moderation.php
│   ├── errors.php
│   └── settings.php
│
├── assets/             # Public CSS/JS/images
│   └── css/
│       └── style.css   # Dark theme
│
├── lib/                # Third-party libraries
│   ├── PHPMailer/      # Email sending
│   └── Fetch/          # IMAP fetching
│
└── tmp/                # Temporary files (attachments)
    └── .htaccess       # Deny all access
```

## 🔒 Security Features

| Feature | Implementation |
|---------|----------------|
| **SQL Injection** | All queries use PDO prepared statements |
| **XSS Protection** | All output escaped with `htmlspecialchars()` |
| **CSRF Protection** | Token validation on all forms |
| **Session Security** | HttpOnly, Secure, SameSite flags |
| **Password Hashing** | bcrypt via `password_hash()` |
| **Directory Protection** | `.htaccess` blocks `data/`, `src/`, `templates/`, `tmp/` |
| **Cron Security** | Secret key required for cron endpoint |

## ⚙️ Configuration Reference

### Settings (in Admin Panel)

| Setting | Description |
|---------|-------------|
| `site_title` | Application title |
| `admin_email` | Login email |
| `admin_password` | Hashed password |
| `imap_host` | IMAP server hostname |
| `imap_port` | IMAP port (usually 993) |
| `smtp_host` | SMTP server hostname |
| `smtp_port` | SMTP port (usually 587) |
| `domains` | Allowed domains (comma-separated) |
| `cron_key` | Secret key for cron URL |

## 🔧 Troubleshooting

### "IMAP connection failed"

1. Verify IMAP credentials in Settings
2. Check if IMAP is enabled on your mail server
3. Some hosts require `mail.domain.com` vs `domain.com`
4. Check firewall allows port 993

### "Cron not running"

1. Verify cron key matches Settings → Cron Configuration
2. Test URL directly in browser (should show processing output)
3. Check server cron logs
4. Ensure `allow_url_fopen` is enabled

### "Emails not sending"

1. Check SMTP settings
2. Verify sender email is authorized (SPF/DKIM)
3. Check cron output for errors
4. Look in ERRORS folder on IMAP

### "Memory exhausted"

The cron processor is optimized for low memory (tested on 640MB). If issues persist:
1. Reduce `$maxMessagesPerRun` in `MailProcessor.php`
2. Large attachments (>10MB) are automatically skipped
3. Increase PHP memory limit if possible

## 📜 License

MIT License - See [LICENSE](LICENSE) file

## 🙏 Credits

- [PHPMailer](https://github.com/PHPMailer/PHPMailer) - Email sending library
- [Fetch](https://github.com/tedious/Fetch) - IMAP library
- [Material Icons](https://fonts.google.com/icons) - UI icons

---

Made with ❤️ by KXKM
