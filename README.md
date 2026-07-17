# 💰 Sajjad Finance — Setup Guide

## What You're Getting
- **Telegram Bot** — type or voice your transactions naturally in any language
- **Web Dashboard** — dark-themed, shows all accounts, charts, reports
- **Auto Reports** — daily/weekly/monthly summaries sent to your Telegram
- **Excel Import** — sync from your existing Money Manager app

---

## Step 1 — Prepare Your Credentials

You need 3 things. **Never share these in chat.**

### A) New Anthropic API Key
1. Go to https://console.anthropic.com
2. API Keys → Create new key
3. Copy it

### B) New Telegram Bot Token
1. Open Telegram → search @BotFather
2. Send `/newbot` (or `/mybots` → select bot → Revoke token)
3. Copy the new token

### C) Your Telegram User ID
1. Open Telegram → search @userinfobot
2. Send `/start` — it replies with your numeric ID (e.g. 123456789)
3. Copy that number

---

## Step 2 — Create MySQL Database on Exonhost

1. Login to Exonhost cPanel
2. Go to **MySQL Databases**
3. Create database: e.g. `sajjad_finance`
4. Create user: e.g. `sajjad_user` with a strong password
5. Add user to database with **All Privileges**
6. Note down: DB name, DB user, DB password

---

## Step 3 — Edit config.php

Open `config.php` and fill in your values:

```php
define('ANTHROPIC_API_KEY', 'sk-ant-YOUR-NEW-KEY');
define('TELEGRAM_BOT_TOKEN', '1234567890:YOUR-NEW-TOKEN');
define('YOUR_TELEGRAM_ID',   123456789);   // your numeric ID

define('DB_HOST', 'localhost');
define('DB_NAME', 'sajjad_finance');
define('DB_USER', 'sajjad_user');
define('DB_PASS', 'your_db_password');

define('DASHBOARD_PASSWORD', 'choose_a_strong_password');
```

---

## Step 4 — Upload Files to Exonhost

1. Login to Exonhost cPanel → **File Manager**
2. Navigate to `public_html`
3. Create folder: `finance`
4. Upload all these files maintaining folder structure:
   ```
   finance/
   ├── config.php          ← fill in your secrets first!
   ├── .htaccess
   ├── database.sql
   ├── api/
   │   ├── db.php
   │   └── parser.php
   ├── bot/
   │   ├── webhook.php
   │   ├── callback.php
   │   └── telegram.php
   ├── dashboard/
   │   ├── index.php
   │   ├── import.php
   │   └── setup.php
   └── cron/
       └── report.php
   ```

**Your site will be at:** `https://finance.sajjad.bd`

---

## Step 5 — Run Database Setup

1. Go to Exonhost cPanel → **phpMyAdmin**
2. Select your database
3. Click **Import** tab
4. Upload `database.sql`
5. Click Go

This creates all tables and seeds your accounts from the screenshots.

---

## Step 6 — Register Telegram Webhook

1. Open your browser: `https://finance.sajjad.bd/dashboard/setup.php`
2. Login with your `DASHBOARD_PASSWORD`
3. Click **"Test Bot"** — should show your bot's name
4. Click **"Set Webhook"** — should show `{"ok":true}`
5. Open Telegram, message your bot: `/start`

---

## Step 7 — Set Up Auto Reports (Cron Jobs)

In Exonhost cPanel → **Cron Jobs**, add these 3 jobs:

| When | Command |
|------|---------|
| Daily 9pm | `php /home/YOUR_USERNAME/public_html/finance/cron/report.php daily` |
| Every Friday 8pm | `php /home/YOUR_USERNAME/public_html/finance/cron/report.php weekly` |
| 1st of month 9am | `php /home/YOUR_USERNAME/public_html/finance/cron/report.php monthly` |

Replace `YOUR_USERNAME` with your Exonhost cPanel username.

---

## How to Use the Bot

### Text transactions (examples):
```
spent 6 BHD grocery ILA
ILA 3.5 food eating out
salary 893.5 bisb
credimax 8.36 lulu grocery
transfer 50 BBK to ILA
1200 BDT brac bank eating out
medical 3 BHD doctor bisb
```

### Commands:
```
/balance    — all account balances
/today      — today's transactions
/monthly    — this month summary
/report     — last 7 days
/accounts   — list all accounts
/rates      — exchange rates
/help       — show all commands
```

### Voice messages:
Send a voice message — the bot will transcribe it automatically.
*(Voice-to-text uses Telegram's built-in transcription.)*

---

## Dashboard

Visit: `https://finance.sajjad.bd/dashboard/`

Features:
- Net worth (BHD equivalent)
- Monthly income vs expense charts
- Category breakdown pie chart
- All accounts with balances
- Recent 20 transactions
- Excel import from Money Manager

---

## Troubleshooting

**Bot not responding?**
→ Check webhook: Dashboard → Setup → "Check Webhook"
→ Make sure config.php has correct bot token

**"Account not found" error?**
→ Bot uses fuzzy matching. Use partial names: "bisb", "ila", "brac"
→ Add missing accounts via phpMyAdmin

**Transactions not saving?**
→ Check DB credentials in config.php
→ Make sure database.sql was imported

**Dashboard not loading?**
→ Check PHP version in cPanel (needs PHP 7.4+)
→ Make sure files are in `public_html/finance/`

---

## Security Notes

- `config.php` is protected by `.htaccess` — cannot be accessed via browser
- Only your Telegram ID can use the bot (set `YOUR_TELEGRAM_ID` in config)
- Dashboard has password protection
- All DB queries use prepared statements (SQL injection safe)
