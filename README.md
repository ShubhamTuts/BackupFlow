# BackupFlow – Easy Backup, Restore & Migration for WordPress

<p align="center">
  <img src="assets/img/BackupFlow.png" alt="BackupFlow WordPress backup plugin logo" width="130" />
</p>

<h3 align="center">Backup, Restore, and Migrate WordPress Without the Confusion</h3>

<p align="center">
  BackupFlow is a clean WordPress backup plugin for full site backups, database backups, files backups, local storage, FTP upload, Google Drive upload, one-click restore, and migration URL rewrite.
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/">
    <img alt="WordPress Plugin" src="https://img.shields.io/badge/WordPress-Plugin-21759B?logo=wordpress&logoColor=white">
  </a>
  <img alt="Requires WordPress 6.5+" src="https://img.shields.io/badge/WordPress-6.5%2B-21759B?logo=wordpress&logoColor=white">
  <img alt="Tested up to WordPress 7.0" src="https://img.shields.io/badge/Tested%20up%20to-WP%207.0-00A32A">
  <img alt="Requires PHP 7.4+" src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white">
  <img alt="License GPLv2 or later" src="https://img.shields.io/badge/License-GPLv2%2B-2ea44f">
  <a href="https://www.paypal.com/ncp/payment/EA96GMSSWBPAA">
    <img alt="Donate" src="https://img.shields.io/badge/Donate-Support%20BackupFlow-ffc439?logo=paypal&logoColor=003087">
  </a>
</p>

<p align="center">
  <a href="#why-backupflow">Why BackupFlow</a> •
  <a href="#core-features">Features</a> •
  <a href="#backup-workflows">Workflows</a> •
  <a href="#restore-and-migration">Restore & Migration</a> •
  <a href="#installation">Installation</a> •
  <a href="#roadmap">Roadmap</a>
</p>

---

## Protect WordPress Before Something Breaks

Plugin update failed? Theme change broke the layout? WooCommerce checkout stopped working? Moving a site to a new host?

BackupFlow gives WordPress users a simple backup workflow before risky changes happen.

With BackupFlow, you can create a full website backup, back up only the database, back up only files, store backups locally, upload to FTP, send backups to Google Drive, download ZIP archives, restore compatible BackupFlow backups, and migrate a site with URL replacement.

---

## Why BackupFlow?

Most WordPress backup plugins become complicated exactly when you need them most.

BackupFlow focuses on the core actions that site owners, agencies, freelancers, developers, and WooCommerce store owners actually need:

* Create a backup before risky updates.
* Download a portable backup ZIP from WordPress admin.
* Restore a compatible BackupFlow archive with live progress.
* Upload a backup on another WordPress site for migration.
* Replace the old site URL with the current site URL during restore.
* Back up only the database when files are unchanged.
* Back up only files when the database is stable.
* Store backups locally, on FTP, or on Google Drive.
* Keep the admin workflow clean, visible, and easy to understand.

---

## Core Features

| Feature                                           | Status                 |
| ------------------------------------------------- | ---------------------- |
| Full WordPress website backups                    | Included               |
| Database-only backups                             | Included               |
| Files-only backups                                | Included               |
| Local website server storage                      | Included               |
| FTP backup upload                                 | Included               |
| Google Drive backup upload                        | Included               |
| Backup ZIP download                               | Included               |
| Backup library                                    | Included               |
| Backup delete option                              | Included               |
| Import BackupFlow ZIP files                       | Included               |
| One-click restore workflow                        | Included               |
| Restore confirmation step                         | Included               |
| Migration restore screen                          | Included               |
| Source URL to current URL replacement             | Included               |
| Serialized-aware database URL replacement         | Included               |
| Live backup progress                              | Included               |
| Live restore progress                             | Included               |
| Backup and restore logs                           | Included               |
| Welcome wizard after activation                   | Included               |
| Clean WordPress admin dashboard                   | Included               |
| Storage settings for local, FTP, and Google Drive | Included               |
| Translation-ready admin UI                        | Included               |
| Multisite migration                               | Not included in v1.0.0 |

---

## Built for Real WordPress Use Cases

BackupFlow is useful before:

* Updating WordPress core
* Updating WooCommerce
* Updating plugins
* Updating themes
* Editing theme files
* Changing checkout settings
* Installing a new plugin
* Importing demo content
* Moving from staging to live
* Moving to a new host
* Changing domain names
* Testing custom code
* Making database changes
* Handing over a client website
* Creating a restore point before major work

---

## Backup Workflows

### Full WordPress Backup

Create a complete WordPress backup that can include:

* WordPress core files
* Plugins
* Themes
* Uploads and media
* Must-use plugins
* Custom files inside the WordPress installation
* Posts and pages
* Users
* Settings
* Widgets
* Menus
* Plugin options
* Custom post type data
* WooCommerce data
* Page builder data stored in the database

Use a full backup before major updates, migrations, theme changes, WooCommerce changes, client handovers, or production deployments.

---

### Database Backup

Use database-only backup when you need a compact restore point before editing content, changing settings, updating WooCommerce, testing migrations, or making database-level changes.

A database backup can include:

* Posts
* Pages
* Users
* WordPress options
* Plugin settings
* WooCommerce data
* Page builder layouts
* Menus
* Widgets
* Custom post type content

BackupFlow exports the database in resumable SQL parts instead of relying on one giant SQL file.

---

### Files Backup

Use files-only backup when your database is stable but you need to protect website files.

A files backup can include:

* Uploads
* Media files
* Themes
* Plugins
* Templates
* Custom code
* WordPress site files

This is useful before changing themes, replacing plugin files, editing templates, or moving file assets between environments.

---

## Restore and Migration

BackupFlow restore is designed to make progress visible.

During restore, BackupFlow shows each major step inside the WordPress admin:

1. Restore job preparation
2. Optional safety database backup
3. File extraction
4. Database import
5. Serialized-aware URL rewrite
6. Final verification

### WordPress Migration

BackupFlow can help move a WordPress website from one location to another.

Common migration workflows include:

* Local site to live site
* Staging site to production site
* Old domain to new domain
* Old host to new host
* Client website handover
* Development website to production
* Backup transfer between WordPress installs

During migration restore, BackupFlow can replace the source website URL with the current WordPress site URL. This helps update links, media paths, internal URLs, and saved WordPress content during the migration process.

BackupFlow also uses serialized-aware replacement because WordPress themes, plugins, widgets, page builders, and settings often store serialized data inside the database.

---

## Storage Options

### Website Server Storage

Store backups directly on your WordPress server.

Local backups are stored in:

```text
wp-content/backupflow/backups
```

BackupFlow creates protective files such as `index.php` and `.htaccess` inside storage directories.

Local storage is useful for quick restore points before updates, edits, testing, and short-term protection.

For better safety, keep important backup copies outside the main website server as well.

---

### FTP Backup Storage

BackupFlow supports FTP backup upload.

FTP storage is useful when you want to keep a backup copy away from the main website server.

Use FTP storage to:

* Store backups on another hosting account.
* Store backups on a private backup server.
* Move backup files between servers.
* Keep off-site backup copies.
* Maintain client backup archives.
* Prepare backup files for migration.

---

### Google Drive Backup Storage

BackupFlow supports Google Drive upload using your own Google OAuth credentials.

To connect Google Drive, you can use:

* Google Client ID
* Google Client Secret
* Google refresh token

Google Drive storage is useful for:

* Off-site WordPress backup storage
* Personal backup archives
* Client backup workflows
* Migration backup transfer
* Safer storage outside your hosting account

Google Drive is optional and only used after it is configured inside BackupFlow storage settings.

---

## Large Backup Readiness

BackupFlow is built around resumable jobs and chunked operations:

* AJAX-driven backup and restore jobs
* Chunked browser uploads for imported BackupFlow ZIP files
* Database export in SQL parts
* Incremental database import
* Incremental serialized-safe URL rewrite
* Chunked file scanning and ZIP packaging
* HTTP Range support for large backup downloads
* Google Drive resumable upload flow
* FTP upload with resume-aware transfer handling

Large backups still depend on the hosting environment.

For high-volume websites, confirm that the server has enough disk space, 64-bit PHP, the PHP `ZipArchive` extension, database access, writable storage, and stable network access for remote storage.

---

## WooCommerce Backup Support

WooCommerce stores contain important business data such as:

* Products
* Customers
* Orders
* Coupons
* Payment settings
* Shipping settings
* Tax settings
* Checkout configuration

BackupFlow can create full website backups or database-only backups before WooCommerce changes.

Use BackupFlow before:

* Updating WooCommerce
* Updating payment plugins
* Changing checkout settings
* Importing products
* Editing product data
* Updating shipping rules
* Changing tax settings
* Testing conversion plugins
* Migrating a WooCommerce store

For active WooCommerce stores, choose backup and restore timing carefully because new orders and customer data may be created after the backup is taken.

---

## Languages

BackupFlow ships with a translation template and compiled language files so the admin experience is ready for multilingual WordPress websites.

| Flag | Language          | WordPress locale | Files         |
| ---- | ----------------- | ---------------- | ------------- |
| 🇺🇸 | English US        | `en_US`          | `.po` + `.mo` |
| 🇬🇧 | English UK        | `en_GB`          | `.po` + `.mo` |
| 🇫🇷 | French            | `fr_FR`          | `.po` + `.mo` |
| 🇩🇪 | German            | `de_DE`          | `.po` + `.mo` |
| 🇪🇸 | Spanish           | `es_ES`          | `.po` + `.mo` |
| 🇧🇷 | Portuguese Brazil | `pt_BR`          | `.po` + `.mo` |

The source translation template is available at:

```text
languages/backupflow.pot
```

---

## Requirements

* WordPress 6.5 or newer
* Tested up to WordPress 7.0
* PHP 7.4 or newer
* PHP `ZipArchive` extension
* 64-bit PHP for very large archives
* Writable `wp-content` storage
* FTP extension for FTP storage
* Outbound HTTP access for Google Drive storage

---

## Installation

### Install from WordPress Dashboard

1. Log in to your WordPress dashboard.
2. Go to **Plugins > Add New**.
3. Search for **BackupFlow**.
4. Click **Install Now**.
5. Click **Activate**.
6. Open the BackupFlow setup wizard.
7. Choose your backup type.
8. Choose your storage location.
9. Create your first backup.

### Manual Installation

1. Download the BackupFlow plugin ZIP file.
2. Log in to your WordPress dashboard.
3. Go to **Plugins > Add New**.
4. Click **Upload Plugin**.
5. Upload the BackupFlow ZIP file.
6. Click **Install Now**.
7. Click **Activate**.
8. Open **BackupFlow** from the WordPress admin menu.
9. Complete the setup wizard.
10. Create your first backup.

### FTP Installation

1. Upload the plugin folder to:

```text
/wp-content/plugins/backupflow
```

2. Log in to your WordPress dashboard.
3. Go to **Plugins**.
4. Activate **BackupFlow**.
5. Open BackupFlow from the WordPress admin menu.
6. Configure your preferred backup storage.
7. Create your first backup.

---

## Development Setup

This repository contains the plugin source directly. No build step is required for the current PHP, CSS, and JavaScript assets.

Useful checks:

```bash
php -l backupflow.php
php -l includes/class-backupflow-admin.php
node --check assets/js/admin.js
```

For WordPress.org submission, also run WordPress Plugin Check and WordPress Coding Standards in a WordPress development environment.

---

## File Structure

```text
BackupFlow/
├── assets/
│   ├── css/
│   ├── img/
│   └── js/
├── includes/
│   ├── class-backupflow-admin.php
│   ├── class-backupflow-backup-manager.php
│   ├── class-backupflow-database.php
│   ├── class-backupflow-file-system.php
│   ├── class-backupflow-migrator.php
│   ├── class-backupflow-preflight.php
│   ├── class-backupflow-restore-manager.php
│   └── helpers.php
├── languages/
├── backupflow.php
├── readme.txt
└── uninstall.php
```

---

## Security Notes

BackupFlow follows WordPress admin security patterns:

* Admin-only capability checks
* AJAX nonce checks
* Sanitized request data
* Protected backup storage directories
* Download nonce verification
* Path safety checks before file operations
* Encrypted storage for saved secrets when WordPress auth keys and OpenSSL are available

Backups may contain sensitive website files and database data. Store backup archives carefully and delete old backups you no longer need.

---

## Important Restore Notice

A restore can replace current website files and database content.

For important production websites:

* Test restore workflows on staging first.
* Confirm you have enough disk space.
* Avoid restoring during active WooCommerce order activity.
* Download important backup files before deleting anything.
* Keep at least one off-site copy of critical backups.

---

## Documentation

Read the BackupFlow documentation:

[BackupFlow Documentation](https://themefreex.com/backupflow/)

The documentation covers backup setup, restore, migration, FTP storage, Google Drive connection, local backups, and recommended backup workflows.

---

## Support BackupFlow

BackupFlow is free to use.

If BackupFlow helps protect your website, saves you time, or supports your client workflow, you can support development here:

[Donate via PayPal](https://www.paypal.com/ncp/payment/EA96GMSSWBPAA)

Your support helps improve BackupFlow with better storage integrations, stronger restore workflows, better documentation, and future automation features.

---

## Roadmap

BackupFlow is structured for future add-ons and premium integrations:

* Automatic backup schedules
* SFTP and FTPS storage
* Dropbox storage
* Microsoft OneDrive storage
* Amazon S3 storage
* S3-compatible storage
* Cloudflare R2 storage
* Website cloning workflows
* WP-CLI support
* Advanced agency workflows
* Professional support workflows

---

## Frequently Asked Questions

### Is BackupFlow a WordPress backup plugin?

Yes. BackupFlow creates WordPress backups from the admin dashboard and supports full backups, database-only backups, and files-only backups.

### Is BackupFlow free?

Yes. BackupFlow is a free WordPress backup, restore, and migration plugin.

### Can BackupFlow create a full WordPress website backup?

Yes. BackupFlow can create a full website backup that includes your WordPress database and website files.

### Can BackupFlow restore a WordPress website?

Yes. BackupFlow can restore compatible BackupFlow ZIP archives and shows live restore progress for files, database import, and URL rewrite steps.

### Can BackupFlow migrate WordPress to another domain?

Yes. Create a backup on the source website, install BackupFlow on the destination WordPress website, upload the compatible BackupFlow backup ZIP, and run the restore process.

### Does BackupFlow replace the old domain during migration?

Yes. During migration restore, BackupFlow can replace the source website URL with the current website URL.

### Does BackupFlow handle serialized data replacement?

Yes. During database restore, BackupFlow uses serialized-aware replacement when rewriting the source home URL to the current site URL.

### Does BackupFlow support Google Drive backups?

Yes. Google Drive upload is supported when the site administrator configures Google OAuth credentials and connects the storage account.

### Does BackupFlow support FTP backups?

Yes. BackupFlow can upload completed backups to an FTP destination configured in the plugin storage settings.

### Does BackupFlow support WooCommerce backups?

Yes. BackupFlow can back up WordPress websites that use WooCommerce. For active stores, be careful when restoring because new orders or customer data created after the backup may be replaced.

### Does BackupFlow support multisite?

Multisite migration is not included in this release. BackupFlow detects multisite and shows a clear unsupported notice for migration flows.

### Where are local backups stored?

Local backups are stored in:

```text
wp-content/backupflow/backups
```

BackupFlow also creates protective `index.php` and `.htaccess` files in its storage directories.

### Does uninstall delete backup files?

No. Uninstall removes plugin options and job records only. Backup archives are intentionally preserved to help avoid accidental data loss.

### What PHP version does BackupFlow require?

BackupFlow requires PHP 7.4 or higher.

### What WordPress version does BackupFlow require?

BackupFlow requires WordPress 6.5 or higher.

### What WordPress version is BackupFlow tested with?

BackupFlow is tested up to WordPress 7.0.

---

## Changelog

### 1.0.0

Initial public release of BackupFlow.

Added:

* Full WordPress website backup
* WordPress database-only backup
* WordPress files-only backup
* Local website server backup storage
* FTP backup upload
* Google Drive backup upload with user-provided OAuth credentials
* Compatible BackupFlow ZIP restore
* Migration restore with URL replacement
* Serialized-aware database URL replacement
* Backup library
* Restore and migration import screen
* Welcome wizard after activation
* Admin dashboard
* Storage settings
* Live backup and restore status modal
* Backup and restore progress logs
* Protective files for plugin backup storage directories
* Translation-ready admin UI

---

## License

BackupFlow is licensed under the GPLv2 or later.

See `readme.txt` and the plugin header in `backupflow.php` for WordPress.org metadata.
