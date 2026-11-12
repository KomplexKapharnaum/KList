# 📮 KXKM Mailing List Manager

A lightweight, self-hosted mailing list manager built with **pure PHP 8** and **SQLite**. Perfect for small to medium organizations who want full control over their mailing lists without complex dependencies.

## 🔄 How It Works

This tool runs on any standard PHP web hosting and relies on a **dedicated IMAP mailbox** that centralizes all mailing list traffic.

### Architecture Overview

```
                    ┌─────────────────────────────────────────┐
                    │         Your Mail Server                │
                    │                                         │
  geeks@domain.com ─┼──► alias ──┐                            │
  admin@domain.com ─┼──► alias ──┼──► listes@domain.com ◄─────┼─── IMAP/SMTP
  news@domain.com  ─┼──► alias ──┘        (main mailbox)      │
                    └─────────────────────────────────────────┘
                                              ▲
                                              │
                                              ▼
                    ┌─────────────────────────────────────────┐
                    │         PHP Web Hosting                 │
                    │                                         │
                    │   ┌─────────┐    ┌─────────────────┐    │
                    │   │ cron.php│───►│ KList Manager   │    │
                    │   └─────────┘    │ (this tool)     │    │
                    │        ▲         └─────────────────┘    │
                    └────────┼────────────────────────────────┘
                             │
                    Scheduled task (every 5-15 min)
```

### Key Concepts

1. **Dedicated Mailbox**  
   You need to have a dedicated email account (e.g., `listes@yourdomain.com`) with IMAP access. This mailbox:
   - Receives all incoming list emails
   - Sends all outgoing emails to subscribers
   - Stores processed messages in IMAP folders
   
2. **Email Aliases (⚠️ Manual Setup Required)**  
   Each mailing list needs a corresponding **email alias** pointing to the main mailbox.  
   
   | List Name | Alias to Create | Points To |
   |-----------|-----------------|-----------|
   | `geeks` | `geeks@yourdomain.com` | `listes@yourdomain.com` |
   | `news` | `news@yourdomain.com` | `listes@yourdomain.com` |
   | `team` | `team@yourdomain.com` | `listes@yourdomain.com` |
   
   > ⚠️ **Important:** Creating email aliases must be done manually in your mail server/hosting panel (cPanel, Plesk, etc.). This tool does not create aliases automatically!

3. **Periodic Processing**  
   The tool processes emails by calling `https://yourdomain.com/listes/cron.php?key=YOUR_CRON_KEY`. This can be:
   - Automated via a cron job / scheduled task
   - Triggered manually for testing
   
   You can find the actual URL with the CRON_KEY in Settings → Cron Configuration

   Each run: fetches new emails → identifies target list → applies moderation → forwards to subscribers

## ✨ Features

- **📋 Multiple mailing lists** - Create unlimited lists with individual settings
- **👥 Subscriber management** - Add, remove, search, and export subscribers
- **🛡️ Moderation** - Optional moderation for each list before forwarding
- **🚫 Blocklist** - Automatic blocking of bouncing emails
- **📧 Discussion lists** - Replies go to the list (discussion) or to the sender (broadcast); subscribers' addresses are never shown
- **📎 The original message, untouched** - Inline images, attachments and encodings reach subscribers exactly as sent
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
- **IMAP/SMTP access** to a dedicated email account for the lists

## 🚀 Quick Installation

### 1. Download

```bash
git clone https://github.com/KomplexKapharnaum/KList.git listes
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

### 4. First Access & Setup Wizard

Navigate to your installation URL (e.g., `https://yourdomain.com/listes/`)

On first access, the **setup wizard** will guide you through:

1. **Admin account** - Create your email/password (also used for moderation notifications)
2. **IMAP configuration** - Connect to your dedicated mailbox
3. **SMTP configuration** - Configure email sending (a test email will be sent)

The database is only created after all settings are validated and connections tested.

### 5. Setup Cron Job

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
   - **Liste de Discussion** - Replies go to the list (otherwise to the sender)
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
       └───────────────────┐                   │
                           ▼                   ▼
                    ┌─────────────────────────────┐
                    │      Forward to             │
                    │      subscribers            │
                    └─────────────────────────────┘
                                  │
                                  ▼
                          ┌─────────────┐
                          │   DONE      │
                          │ (archived)  │
                          └─────────────┘
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

### What subscribers receive

Each list gets the **original message**, body byte for byte (inline images, attachments, encodings
untouched), with only its headers rewritten:

- `From: "Alice via mylist" <mylist@yourdomain.com>` (the sender's name, else their address) — the
  server cannot send as the sender's own address (DMARC); `X-Original-From` keeps it
- `Reply-To:` the list on a discussion list, the sender on a broadcast list
- `Subject:` `[mylist]` once, after any `Re:`
- `To`/`Cc` as the sender wrote them; subscribers are only in the envelope, never in the headers
- `List-Id`, `List-Post`, `List-Unsubscribe`, `Precedence: list`, `X-Loop`; the sender's
  `DKIM-Signature` is dropped (your provider signs the copy)

Nobody gets two copies: the sender, whoever the original To/Cc already reached, and anyone another
list of the same message reached are left out. Each list's send is recorded in the `relay_journal`
table; a message moves to `DONE` once every list it names is sent, and a retry skips what went out.
A recipient the server refuses is logged (and blocked if unknown), never a reason to send again.

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
└── tmp/                # Temporary files (no longer used for sending)
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

| Issue | Solutions |
|-------|-----------|
| **IMAP connection failed** | Verify credentials in Settings • Check IMAP is enabled on mail server • Try `mail.domain.com` vs `domain.com` • Ensure firewall allows port 993 |
| **Cron not running** | Verify cron key in Settings → Cron Configuration • Test URL in browser • Check server cron logs |
| **Emails not sending** | Check SMTP settings • Verify SPF/DKIM for sender email • Check ERRORS folder on IMAP |
| **Memory exhausted** | Reduce `$maxMessagesPerRun` in `MailProcessor.php` • Increase PHP memory limit |

## 📜 License

GPLv3 License - See [LICENSE](LICENSE) file

## 👤 Author

**Thomas BOHL** ([@maigre](https://github.com/maigre))  
[KomplexKapharnaüm](https://kxkm.net) – Arts numériques & Spectacle vivant

## 🙏 Credits

- [PHPMailer](https://github.com/PHPMailer/PHPMailer) – Email sending library
- [Fetch](https://github.com/tedious/Fetch) – IMAP library
- [Material Icons](https://fonts.google.com/icons) – UI icons
