# Outbound Email Monitor (WHM Plugin)

Deployable package for cPanel/WHM servers to:

- log outbound Exim messages to MySQL
- show recent outbound mail in WHM
- block/unblock senders or domains using `uapi Email::suspend_outgoing` and `Email::unsuspend_outgoing`

## Repository Contents

- `install.sh` - idempotent installer
- `uninstall.sh` - remove installed files
- `sql/schema.sql` - MySQL schema
- `plugin/` - WHM plugin files (`index.php`, `style.css`, `plugin.conf`)
- `bin/log_outbound_mail.php` - Exim `transport_filter` logging script
- `config/outboundmail_db.conf.example` - DB config template
- `exim/exim_snippet.conf` - router/transport snippet for MailBaby
- `hooks/postupcp_outboundmail.sh` - optional post-update reinstall hook

## Install

1. Clone repo on server (example):
   ```bash
   cd /root
   git clone <your-repo-url> outboundmail
   cd outboundmail
   ```
2. Run installer:
   ```bash
   chmod +x install.sh uninstall.sh
   ./install.sh --db-pass 'CHANGE_TO_STRONG_PASSWORD'
   ```

If MySQL root has password:

```bash
./install.sh --db-pass 'CHANGE_TO_STRONG_PASSWORD' --mysql-root-pass 'MYSQL_ROOT_PASSWORD'
```

## Exim Configuration

Add snippet from `exim/exim_snippet.conf` in WHM Exim Advanced Editor and remove old `mailbaby_smarthost` router.

Then rebuild/restart Exim from WHM.

## First Validation

1. Send outbound test email.
2. Verify DB:
   ```sql
   SELECT id, timestamp, sender, recipient, subject, spam_score, status
   FROM outbound_mail.messages
   ORDER BY id DESC
   LIMIT 10;
   ```
3. Open WHM -> Plugins -> **Outbound Email Monitor**.
4. Test Block and Unblock actions.

## Security

Installer applies:

- `/etc/outboundmail_db.conf` -> `640 root:mailnull`
- `/usr/local/bin/log_outbound_mail.php` -> `755 root:mailnull`
- plugin files under `/usr/local/cpanel/whostmgr/docroot/cgi/addons/outboundmail/` -> `root:root`

## Optional: Survive cPanel Updates

Install post-update hook:

```bash
chmod +x hooks/postupcp_outboundmail.sh
cp hooks/postupcp_outboundmail.sh /scripts/postupcp
chmod 700 /scripts/postupcp
```

If repo path differs, edit `REPO_DIR` in `/scripts/postupcp`.

## Uninstall

```bash
./uninstall.sh
```

Note: database tables and Exim custom config are left intact intentionally.
