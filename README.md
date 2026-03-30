# MailPilot ✉️

A self-hosted email marketing tool built with PHP & MySQL. Manage campaigns, contact lists, and send bulk emails through your own SMTP servers (Hostinger, Namecheap, etc.) — completely free.

## Features

- 📧 **SMTP Account Manager** — Connect multiple email servers (Hostinger, Namecheap, any SMTP)
- 👥 **Contact Lists** — Import from CSV with custom field mapping
- ✉️ **Campaign Builder** — WYSIWYG editor (TinyMCE) with image upload
- 🖼️ **Inline Images** — Images embedded as CID attachments (no privacy warnings)
- ⏰ **Smart Scheduler** — Random delays between emails to avoid spam filters
- 🔗 **Click Tracking** — Track link clicks via redirect URLs
- 📊 **Real-time Stats** — Live progress, sent/failed/pending counts
- 🔒 **Secure** — Encrypted passwords, CSRF protection, prepared statements

## Requirements

- PHP 7.4+
- MySQL 5.7+
- Web hosting with cron job support (Hostinger, Namecheap, etc.)

## Quick Setup

1. Upload files to your web hosting
2. Create a MySQL database
3. Edit `config.php` with your database credentials
4. Visit `install.php` in your browser
5. Set up a cron job to run `cron/process-queue.php` every minute

## Tech Stack

| Component | Technology |
|---|---|
| Backend | PHP (vanilla) |
| Database | MySQL |
| Email | PHPMailer (SMTP) |
| Editor | TinyMCE 6 |
| Frontend | HTML + CSS + JS |
| Scheduling | Cron Jobs |

## Configuration

Edit `config.php` before deploying:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
define('APP_URL', 'https://yourdomain.com/email-tool');
define('ENCRYPTION_KEY', 'change-this-to-random-32-chars');
define('CRON_SECRET', 'change-this-too');
```

## Cron Job

Set up in your hosting panel (runs every minute):

```
* * * * * php /path/to/cron/process-queue.php secret=YOUR_CRON_SECRET
```

## License

MIT
