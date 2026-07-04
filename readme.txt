=== BackupFlow – Easy Backup, Restore & Migration for WordPress ===
Contributors: codefreex
Tags: backup, restore, migration, google drive, ftp
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WordPress backup, restore, and migration plugin with local backups, FTP upload, Google Drive storage, and one-click restore.

== Description ==

BackupFlow is an easy WordPress backup, restore, and migration plugin built for website owners, freelancers, agencies, and developers who want a clear backup workflow without complicated setup.

Create complete WordPress backups, download backup ZIP files, restore compatible backups, move a website to a new domain, and store backup files on your website server, FTP server, or Google Drive using your own credentials.

BackupFlow is designed to make WordPress backup management simple:

* Create a full website backup before updates.
* Back up only the WordPress database.
* Back up only WordPress files.
* Store backups locally on your server.
* Upload backups to FTP.
* Upload backups to Google Drive.
* Restore compatible BackupFlow ZIP files.
* Migrate a site by restoring a backup on a new domain.
* Replace the old site URL with the current site URL during migration.
* Track backup and restore progress from the WordPress dashboard.

Whether you run a business website, blog, WooCommerce store, client website, local development site, staging website, or multisite-style workflow, BackupFlow helps you create safer backup points before making important changes.

BackupFlow gives WordPress users a simple backup system without forcing them into a complex dashboard. The plugin focuses on the core backup actions most site owners need every day: backup, download, restore, migrate, and store.

= Why use BackupFlow? =

Many WordPress backup plugins are difficult to configure, overloaded with settings, or confusing for non-technical users.

BackupFlow is built around a clean backup flow:

1. Choose what to back up.
2. Choose where to store it.
3. Start the backup.
4. Watch the live progress.
5. Restore or migrate when needed.

The goal is simple: help you protect your WordPress website before updates, plugin changes, theme edits, server changes, malware cleanup, or website migration.

= Free WordPress backup plugin =

BackupFlow includes the essential backup features site owners expect from a reliable WordPress backup plugin.

You can create:

* Full website backups
* Database-only backups
* Files-only backups

You can store backups in:

* Website server storage
* FTP storage
* Google Drive storage

You can restore:

* Compatible BackupFlow ZIP backups
* Database backups
* File backups
* Full website backups

You can also use BackupFlow for WordPress migration by restoring a BackupFlow backup on a new WordPress install and replacing the original site URL with the current site URL.

= WordPress backup features =

BackupFlow helps protect your website with practical backup tools:

* Full WordPress website backup
* WordPress database backup
* WordPress files backup
* Local backup storage
* FTP backup upload
* Google Drive backup upload
* Backup ZIP archive generation
* Backup download from WordPress admin
* Backup delete option
* Backup library inside WordPress
* Live backup status
* Backup progress logs
* Admin dashboard
* First-run setup wizard
* Secure backup directory protection
* Restore screen
* Migration import screen
* URL replacement during migration
* Serialized-aware database replacement
* WordPress admin notices
* Clean plugin settings flow

= WordPress restore features =

BackupFlow includes one-click restore support for compatible BackupFlow backup archives.

Restore features include:

* Restore full website backups
* Restore database backups
* Restore files backups
* Restore uploaded BackupFlow ZIP files
* Restore from the backup library
* Restore status tracking
* Restore progress logs
* Restore confirmation step
* URL replacement for migration restores
* Serialized data handling during database restore

BackupFlow is useful when you need to recover a site after a broken update, failed plugin change, theme issue, accidental file change, or migration test.

= WordPress migration features =

BackupFlow can help move a WordPress website from one domain to another.

Common migration use cases:

* Move WordPress from staging to live.
* Move WordPress from local development to production.
* Move WordPress from an old domain to a new domain.
* Move WordPress from one hosting provider to another.
* Restore a client site on a new server.
* Create a backup on one site and restore it on another WordPress installation.

During database restore, BackupFlow can replace the source website URL with the current website URL. This helps update internal links, media URLs, and saved WordPress content during migration.

BackupFlow uses serialized-aware replacement during database restore, which is important for WordPress because themes, plugins, widgets, page builders, and settings often store serialized data inside the database.

= Local backup storage =

BackupFlow can store backup files directly on your WordPress server.

Local backups are stored in:

`wp-content/backupflow/backups`

BackupFlow also creates protective files in its storage directories to help prevent direct directory browsing.

Local backups are useful when you want quick access to backup files before updating WordPress core, plugins, themes, WooCommerce, or custom code.

For best protection, download important backup files to your computer or send them to remote storage.

= FTP backup storage =

BackupFlow supports FTP backup upload.

FTP storage is useful when you want to keep a copy of your WordPress backup outside the main website server.

Typical FTP backup uses:

* Store backups on another hosting account.
* Store backups on a private backup server.
* Keep a remote copy of important WordPress files.
* Maintain off-site backups for client websites.
* Move backups between servers.

You can configure FTP credentials from the BackupFlow storage settings screen.

= Google Drive backup storage =

BackupFlow supports Google Drive storage using your own Google OAuth credentials.

You can connect Google Drive by providing:

* Google Client ID
* Google Client Secret
* Refresh token from the Google Drive connection flow

This gives site owners control over their own Google Drive connection and backup destination.

Google Drive storage is useful for:

* Off-site WordPress backups
* Client backup workflows
* Personal backup archives
* Safer backup storage outside the website server
* Migration backup transfer

= Backup before WordPress updates =

BackupFlow is especially useful before making important website changes.

Create a backup before:

* Updating WordPress core
* Updating WooCommerce
* Updating plugins
* Updating themes
* Editing theme files
* Installing a new plugin
* Changing page builder layouts
* Importing demo content
* Migrating hosting
* Changing domain names
* Cleaning malware
* Testing custom code
* Making database changes

A fresh backup gives you a safer rollback point if something breaks.

= Backup for WooCommerce sites =

WooCommerce websites need careful backup handling because they store products, orders, customers, settings, coupons, shipping rules, tax rules, and payment settings inside WordPress.

BackupFlow can create full website backups or database-only backups before you update WooCommerce, install payment plugins, change checkout settings, edit product data, or modify store design.

For busy WooCommerce stores, always consider the timing of your backup and restore process because new orders and customer activity can happen while changes are being made.

= Backup for agencies and freelancers =

BackupFlow is useful for agencies, freelancers, and WordPress service providers who manage multiple client websites.

Use BackupFlow to:

* Create a backup before client revisions.
* Create a backup before plugin updates.
* Move a client site from staging to live.
* Download a full ZIP backup before handover.
* Store backup copies on FTP or Google Drive.
* Keep a simple backup workflow inside WordPress.
* Restore a compatible backup when testing changes.

BackupFlow keeps the interface simple enough for clients while still giving developers useful backup and migration controls.

= Backup for staging and development =

BackupFlow can help developers and site builders move websites between local, staging, and live environments.

Common workflows:

* Local to staging
* Staging to production
* Production to staging
* Old host to new host
* Old domain to new domain

The migration restore flow helps replace the original site URL with the current site URL during database restore.

= Simple backup dashboard =

BackupFlow includes a clean WordPress admin experience designed around the backup process.

The dashboard includes:

* Backup creation options
* Storage selection
* Backup type selection
* Backup progress status
* Backup logs
* Backup library
* Restore actions
* Download actions
* Delete actions
* Storage settings
* Migration restore screen
* First-run wizard

The plugin is built to reduce confusion and help users complete the backup process quickly.

= Backup types =

BackupFlow supports three main backup types.

Full website backup:

* WordPress database
* WordPress files
* Uploads
* Themes
* Plugins
* Core WordPress files where applicable
* Backup metadata needed for restore

Database-only backup:

* WordPress database export
* Posts
* Pages
* Products
* Orders
* Users
* Settings
* Plugin options
* Theme options
* Page builder content
* WordPress configuration data stored in the database

Files-only backup:

* WordPress files
* Uploads
* Themes
* Plugins
* Media files
* Site assets

Choose the backup type based on the change you are about to make.

= Security-focused backup handling =

BackupFlow stores local backup archives inside its own plugin storage directory and adds protective files to storage folders.

The plugin is designed to keep backup management inside the WordPress admin area and avoid exposing backup actions to public visitors.

Recommended best practices:

* Download important backups to your local computer.
* Keep remote backup copies when possible.
* Do not rely only on the same server for critical backups.
* Delete old backup files you no longer need.
* Keep WordPress, plugins, and themes updated.
* Test restore workflows on staging when possible.

= Documentation =

Documentation and setup guides are available at:

https://themefreex.com/backupflow/

The documentation explains setup, storage configuration, backup creation, restore, migration, FTP connection, Google Drive connection, and recommended backup practices.

= Who should use BackupFlow? =

BackupFlow is built for:

* WordPress site owners
* WooCommerce store owners
* Bloggers
* Agencies
* Freelancers
* Developers
* Website maintenance teams
* Hosting support teams
* Local business websites
* SaaS marketing websites
* Landing page websites
* Client websites
* Staging websites
* Development websites

Use BackupFlow when you need a straightforward WordPress backup, restore, and migration plugin that keeps the workflow simple.

= What can BackupFlow help with? =

BackupFlow can help with:

* WordPress backup
* WordPress restore
* WordPress migration
* Website backup
* Website restore
* Website migration
* WordPress database backup
* WordPress files backup
* WooCommerce backup
* FTP backup
* Google Drive backup
* Local backup
* Staging to live migration
* Domain migration
* Hosting migration
* Backup download
* Backup ZIP creation
* Restore after failed update
* Restore after broken plugin change
* Backup before theme changes

= Important restore note =

Before restoring any backup, make sure you understand what the restore will replace.

A restore can overwrite current website files and database content. If your current site has new orders, new users, new form entries, or new content after the backup was created, those changes may be replaced during restore.

For important production websites, test restore and migration workflows on a staging environment first.

== Installation ==

= Automatic installation =

1. Log in to your WordPress dashboard.
2. Go to Plugins > Add New.
3. Search for BackupFlow.
4. Click Install Now.
5. Click Activate.
6. Open the BackupFlow setup wizard.
7. Choose your backup type.
8. Choose your storage location.
9. Create your first backup.

= Manual installation =

1. Download the BackupFlow plugin ZIP file.
2. Log in to your WordPress dashboard.
3. Go to Plugins > Add New.
4. Click Upload Plugin.
5. Upload the BackupFlow ZIP file.
6. Click Install Now.
7. Click Activate.
8. Open BackupFlow from the WordPress admin menu.
9. Complete the first-run setup wizard.
10. Create your first backup.

= FTP installation =

1. Upload the plugin folder to `/wp-content/plugins/backupflow`.
2. Log in to your WordPress dashboard.
3. Go to Plugins.
4. Activate BackupFlow.
5. Open the setup wizard.
6. Configure your preferred backup storage.
7. Create your first backup.

== Frequently Asked Questions ==

= What does BackupFlow do? =

BackupFlow helps you back up, restore, and migrate WordPress websites. You can create full website backups, database-only backups, or files-only backups, then store them locally, upload them to FTP, or upload them to Google Drive.

= Is BackupFlow free? =

Yes. BackupFlow is a free WordPress backup, restore, and migration plugin.

= Can I create a full WordPress website backup? =

Yes. BackupFlow can create a full website backup that includes the WordPress database and website files.

= Can I create a database-only backup? =

Yes. BackupFlow includes database-only backup support. This is useful before changing content, settings, WooCommerce data, page builder content, plugin options, or theme options.

= Can I create a files-only backup? =

Yes. BackupFlow includes files-only backup support. This is useful before changing themes, plugins, uploads, media files, or custom code.

= Where are local backups stored? =

Local backups are stored in:

`wp-content/backupflow/backups`

BackupFlow creates protective `index.php` and `.htaccess` files in its storage directories.

= Can I download backup files? =

Yes. BackupFlow includes a backup library where you can manage compatible backup files, including download actions when available.

= Can I delete old backups? =

Yes. BackupFlow allows you to delete backup files from the backup library.

= Does BackupFlow support FTP backups? =

Yes. BackupFlow supports FTP backup upload using your FTP credentials.

= Does BackupFlow support Google Drive backups? =

Yes. BackupFlow supports Google Drive backup upload using user-provided Google OAuth credentials.

= Can Google Drive work without a Google OAuth app? =

No. Google Drive connection requires a Google Client ID, Client Secret, and refresh token generated through the Google Drive connection flow.

= Does BackupFlow support one-click restore? =

Yes. BackupFlow supports one-click restore for compatible BackupFlow ZIP backup files.

= Can I upload a backup ZIP and restore it? =

Yes. BackupFlow includes a restore and migration import screen for compatible BackupFlow backup ZIP files.

= Can I migrate a WordPress website with BackupFlow? =

Yes. You can create a backup from one WordPress website, install BackupFlow on another WordPress website, upload the compatible backup ZIP, and run the restore process.

= Does BackupFlow replace the old domain during migration? =

Yes. During migration restore, BackupFlow can replace the source site URL with the current site URL.

= Does BackupFlow handle serialized data replacement? =

Yes. During database restore, BackupFlow uses serialized-aware replacement when rewriting the source home URL to the current site URL.

= Can I use BackupFlow before updating WordPress? =

Yes. BackupFlow is useful before updating WordPress core, plugins, themes, WooCommerce, or page builder plugins.

= Can I use BackupFlow for WooCommerce backups? =

Yes. BackupFlow can back up WordPress websites that use WooCommerce. For active stores, choose backup and restore timing carefully because new orders may be created after the backup is taken.

= Can I use BackupFlow on staging websites? =

Yes. BackupFlow is useful for staging websites, test websites, development websites, and local-to-live migration workflows.

= Can I use BackupFlow to move from one host to another? =

Yes. BackupFlow can help move a WordPress site between hosting providers by creating a backup on the source site and restoring it on the destination WordPress installation.

= Does BackupFlow remove backups when I uninstall the plugin? =

No. Uninstall removes plugin options and job records only. Backup archives are intentionally preserved to help avoid accidental data loss.

= Does BackupFlow protect the backup directory? =

BackupFlow creates protective files such as `index.php` and `.htaccess` in its storage directories.

= What PHP version is required? =

BackupFlow requires PHP 7.4 or higher.

= What WordPress version is required? =

BackupFlow requires WordPress 6.5 or higher.

= What is the tested WordPress version? =

BackupFlow is tested up to WordPress 7.0.

= Does BackupFlow work with page builders? =

BackupFlow backs up WordPress files and database content. Page builder layouts are usually stored inside the WordPress database and uploads directory, so they are included when you create a full backup.

= Should I test restore before using it on a live website? =

Yes. For important production websites, testing restore on a staging environment is recommended before restoring on the live site.

= What happens if my website has very large files? =

Backup success can depend on hosting limits such as PHP execution time, memory limit, disk space, file permissions, and server configuration. For large websites, make sure your server has enough available resources before starting a backup.

= Why should I keep remote backups? =

Local backups are useful, but if your website server fails, local backup files may also become unavailable. FTP and Google Drive storage help keep backup copies outside the main WordPress server.

= Where can I find documentation? =

BackupFlow documentation is available at:

https://themefreex.com/backupflow/

== Screenshots ==

1. BackupFlow welcome wizard for creating the first WordPress backup.
2. Live backup progress modal with real-time backup status and logs.
3. Backup library with restore, download, and delete actions.
4. Restore and migrate import screen for compatible BackupFlow ZIP files.
5. Storage settings for Website Server, FTP, and Google Drive.

== Changelog ==

= 1.0.0 =

* Initial release.
* Added full WordPress website backup.
* Added WordPress database-only backup.
* Added WordPress files-only backup.
* Added local website server backup storage.
* Added FTP backup upload.
* Added Google Drive backup upload with user-provided OAuth credentials.
* Added compatible BackupFlow ZIP restore.
* Added migration restore with URL replacement.
* Added serialized-aware database URL replacement.
* Added backup library.
* Added restore and migration import screen.
* Added welcome wizard after activation.
* Added admin dashboard.
* Added storage settings.
* Added live backup and restore status modal.
* Added backup and restore progress logs.
* Added protective files for plugin backup storage directories.

== Upgrade Notice ==

= 1.0.0 =
Initial release of BackupFlow with WordPress backup, restore, migration, local storage, FTP upload, and Google Drive upload.
