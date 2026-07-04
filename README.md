# BackupFlow - Easy Backup, Restore & Migration for WordPress

<p align="center">
  <img src="assets/img/BackupFlow.png" alt="BackupFlow WordPress backup plugin logo" width="120" />
</p>

<p align="center">
  <strong>Simple WordPress backup, restore, and migration plugin with local backups, FTP storage, Google Drive upload, one-click restore, database backups, files backups, and migration URL rewrite.</strong>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/"><img alt="WordPress Plugin" src="https://img.shields.io/badge/WordPress-Plugin-21759B?logo=wordpress&logoColor=white"></a>
  <img alt="Requires PHP 7.4+" src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white">
  <img alt="Tested up to WordPress 7.0" src="https://img.shields.io/badge/Tested%20up%20to-WP%207.0-00A32A">
  <img alt="License GPLv2 or later" src="https://img.shields.io/badge/License-GPLv2%2B-2ea44f">
</p>

BackupFlow is a WordPress backup plugin built for site owners, agencies, freelancers, and developers who need a clean way to create backups, restore a website, or migrate WordPress to another domain or host.

It creates portable BackupFlow ZIP archives, stores backups locally or on connected storage, and provides live backup and restore progress inside the WordPress admin.

## Languages

BackupFlow ships with a translation template and compiled language packs so the admin experience is ready for multilingual WordPress sites.

| Flag | Language | WordPress locale | Files |
| --- | --- | --- | --- |
| 🇺🇸 | English (US) | `en_US` | `.po` + `.mo` |
| 🇬🇧 | English (UK) | `en_GB` | `.po` + `.mo` |
| 🇫🇷 | French | `fr_FR` | `.po` + `.mo` |
| 🇩🇪 | German | `de_DE` | `.po` + `.mo` |
| 🇧🇷 | Portuguese (Brazil) | `pt_BR` | `.po` + `.mo` |
| 🇪🇸 | Spanish | `es_ES` | `.po` + `.mo` |

The source template is available at `languages/backupflow.pot` for new translations.

## Why BackupFlow?

Most WordPress backup plugins are either too heavy, too technical, or hide the important restore steps until something breaks. BackupFlow focuses on the workflows people actually need:

- Create a full WordPress website backup before risky updates.
- Download a backup archive from WordPress admin.
- Restore a compatible backup with live progress.
- Import a BackupFlow ZIP on another site for migration.
- Rewrite source URLs to the current WordPress URL during restore.
- Back up only the database when files are unchanged.
- Back up only files when database content is not needed.
- Store backups locally, on FTP, or on Google Drive.

## Core Features

| Feature | Included |
| --- | --- |
| Full website backups | Yes |
| Database-only backups | Yes |
| Files-only backups | Yes |
| Local server storage | Yes |
| FTP backup storage | Yes |
| Google Drive backup upload | Yes |
| Backup ZIP download | Yes |
| Import BackupFlow ZIP files | Yes |
| One-click restore | Yes |
| Migration URL rewrite | Yes |
| Serialized data URL replacement | Yes |
| Live backup and restore logs | Yes |
| Cancel confirmation flow | Yes |
| Paginated backup tables | Yes |
| Translation-ready admin UI | Yes |
| Multisite migration | Not included in this release |

## Backup Workflows

### Full WordPress Backup

Create a complete backup that can include:

- WordPress core files
- Plugins
- Themes
- Uploads and media
- Must-use plugins
- Custom site files inside the WordPress installation
- Posts, pages, users, settings, widgets, menus, plugin options, CPT data, WooCommerce data, and page-builder data stored in the database

### Database Backup

Use database-only backup when you need a compact restore point before editing content, updating plugins, changing settings, or testing migrations.

BackupFlow exports the database in resumable SQL parts instead of relying on one giant SQL file.

### Files Backup

Use files-only backup when your database is stable but you need to protect media, plugins, themes, templates, or custom code.

## Restore and Migration

BackupFlow restore is designed for clear, visible progress. During restore, the admin modal shows each major step:

1. Restore job preparation
2. Optional safety database backup
3. File extraction
4. Database import
5. Serialized-aware URL rewrite
6. Final verification

Migration is handled by importing a BackupFlow ZIP on the destination WordPress site. BackupFlow can replace the source site URL with the current destination URL during restore.

## Storage Options

### Website Server

Backups can be stored locally in protected `wp-content/backupflow` storage.

### FTP

BackupFlow can upload completed backup archives to an FTP storage location configured by the site administrator.

### Google Drive

BackupFlow supports Google Drive upload using your own Google OAuth credentials. Google Drive is optional and only used after it is configured in BackupFlow storage settings.

See `readme.txt` for the Google Drive privacy and terms disclosure used for WordPress.org review.

## Large Backup Readiness

BackupFlow is built around resumable jobs and chunked operations:

- AJAX-driven backup and restore jobs
- Chunked browser uploads for imported BackupFlow ZIP files
- Database export in SQL parts
- Incremental database import
- Incremental serialized-safe URL rewrite
- Chunked file scanning and ZIP packaging
- HTTP Range support for large backup downloads
- Google Drive resumable upload flow
- FTP upload with resume-aware transfer handling

Large backups still depend on the hosting environment. For high-volume sites, confirm that the server has enough disk space, 64-bit PHP, the PHP `ZipArchive` extension, database access, writable storage, and stable network access for remote storage.

## Requirements

- WordPress 6.5 or newer
- Tested up to WordPress 7.0
- PHP 7.4 or newer
- PHP `ZipArchive` extension
- 64-bit PHP for very large archives
- Writable `wp-content` storage
- FTP extension for FTP storage
- Outbound HTTP access for Google Drive storage

## Installation

1. Upload the `BackupFlow` folder to `wp-content/plugins/`.
2. Activate **BackupFlow** from the WordPress Plugins screen.
3. Open **BackupFlow** in the WordPress admin menu.
4. Use the setup wizard to create your first backup.

## Development Setup

This repository contains the plugin source directly. No build step is required for the current PHP, CSS, and JavaScript assets.

Useful checks:

```bash
php -l backupflow.php
php -l includes/class-backupflow-admin.php
node --check assets/js/admin.js
```

For WordPress.org submission, also run WordPress Plugin Check and WordPress Coding Standards in a WordPress development environment.

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

## Security Notes

BackupFlow follows WordPress admin security patterns:

- Admin-only capability checks
- AJAX nonce checks
- Sanitized request data
- Protected backup storage directories
- Download nonce verification
- Path safety checks before file operations
- Encrypted storage for saved secrets when WordPress auth keys and OpenSSL are available

Backups may contain sensitive website files and database data. Store archives carefully and delete old backups you no longer need.

## WordPress.org Notes

The WordPress.org-facing plugin description, FAQ, privacy disclosure, changelog, and tags are maintained in `readme.txt`.

The GitHub README is intentionally longer and more technical so developers, agencies, and contributors can understand the project quickly.

## Roadmap

BackupFlow is structured for future add-ons and premium integrations:

- Automatic backup schedules
- SFTP and FTPS storage
- Dropbox
- Microsoft OneDrive
- Amazon S3
- S3-compatible storage
- Cloudflare R2
- Website cloning
- WP-CLI support
- Professional support workflows

## Frequently Asked Questions

### Is BackupFlow a WordPress backup plugin?

Yes. BackupFlow creates WordPress backups from the admin dashboard and supports full backups, database-only backups, and files-only backups.

### Can BackupFlow restore a WordPress website?

Yes. BackupFlow can restore compatible BackupFlow ZIP archives and shows live restore progress for files, database import, and URL rewrite steps.

### Can BackupFlow migrate WordPress to another domain?

Yes. Import a BackupFlow ZIP on the destination site, then run restore. BackupFlow can rewrite the source URL to the current site URL during migration.

### Does BackupFlow support Google Drive backups?

Yes. Google Drive upload is supported when the site administrator configures Google OAuth credentials and connects the storage account.

### Does BackupFlow support FTP backups?

Yes. BackupFlow can upload completed backups to an FTP destination configured in the plugin storage settings.

### Does BackupFlow support multisite?

Multisite migration is not included in this release. BackupFlow detects multisite and shows a clear unsupported notice for migration flows.

## License

BackupFlow is licensed under the GPLv2 or later.

See `readme.txt` and the plugin header in `backupflow.php` for WordPress.org metadata.
