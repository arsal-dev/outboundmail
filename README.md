# Outbound Email Monitor (WHM Plugin)

Deployable package for cPanel/WHM servers to:

- log outbound Exim messages to MySQL
- show recent outbound mail in WHM
- block/unblock senders or domains using `uapi Email::suspend_outgoing` and `Email::unsuspend_outgoing`

Repository: [arsal-dev/outboundmail](https://github.com/arsal-dev/outboundmail)

## What This Installs

- WHM plugin at `/usr/local/cpanel/whostmgr/docroot/cgi/addons/outboundmail/`
- Exim logging filter at `/usr/local/bin/log_outbound_mail.php`
- shared DB config at `/etc/outboundmail_db.conf`
- MySQL database/tables (`outbound_mail`, `messages`, `blocked_senders`)

## Repository Layout

- `install.sh` - idempotent installer
- `uninstall.sh` - remove installed files
- `sql/schema.sql` - MySQL schema
- `plugin/` - WHM plugin files (`index.php`, `style.css`, `plugin.conf`)
- `bin/log_outbound_mail.php` - Exim `transport_filter` logging script
- `config/outboundmail_db.conf.example` - DB config template
- `exim/exim_snippet.conf` - example router/transport snippet for a smart host relay
- `hooks/postupcp_outboundmail.sh` - optional post-update reinstall hook

## Quick Install (Production)

Run on the target cPanel/WHM server as `root`:

```bash
cd /root
git clone https://github.com/arsal-dev/outboundmail.git
cd outboundmail
chmod +x install.sh uninstall.sh
./install.sh --db-pass 'CHANGE_TO_STRONG_PASSWORD'
```

If MySQL root/admin has a password:

```bash
./install.sh --db-pass 'CHANGE_TO_STRONG_PASSWORD' --mysql-root-pass 'MYSQL_ROOT_PASSWORD'
```

## Exim Setup

1. WHM -> Exim Configuration Manager -> Advanced Editor
2. Paste the router/transport from `exim/exim_snippet.conf`
3. Remove/disable conflicting old smart host routers if present
4. Save and restart Exim

## Test-Server Setup (Direct DNS Delivery)

If you want to test first without any external relay, add this in Exim Advanced Editor:

```exim
outbound_log_router:
  driver = manualroute
  domains = ! +local_domains
  transport = log_and_send_smtp
  route_list = * * bydns
  no_more

log_and_send_smtp:
  driver = smtp
  transport_filter = /usr/local/bin/log_outbound_mail.php
```

This logs outbound messages while still delivering directly by normal DNS routing.

## Validation Checklist

1. Send outbound test email from a cPanel mailbox.
2. Verify DB entries:
   ```sql
   SELECT id, timestamp, sender, recipient, subject, spam_score, status
   FROM outbound_mail.messages
   ORDER BY id DESC
   LIMIT 10;
   ```
3. Open WHM -> Plugins -> **Outbound Email Monitor**
4. Test **Block** and **Unblock** actions

## Security and Permissions

Installer applies:

- `/etc/outboundmail_db.conf` -> `640 root:mailnull`
- `/usr/local/bin/log_outbound_mail.php` -> `755 root:mailnull`
- plugin files under `/usr/local/cpanel/whostmgr/docroot/cgi/addons/outboundmail/` -> `root:root`

## Keep It Across cPanel Updates (Optional)

Install the post-update hook:

```bash
chmod +x hooks/postupcp_outboundmail.sh
cp hooks/postupcp_outboundmail.sh /scripts/postupcp
chmod 700 /scripts/postupcp
```

If your repo path is not `/root/outboundmail`, edit `REPO_DIR` in `/scripts/postupcp`.

## Upgrade

On server:

```bash
cd /root/outboundmail
git pull
./install.sh --skip-db
```

Use `--skip-db` when you only want to refresh plugin/filter/config files.

## Uninstall

```bash
cd /root/outboundmail
./uninstall.sh
```

Note: uninstall intentionally does not drop MySQL data or remove Exim custom routes.
