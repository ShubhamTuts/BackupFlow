# BackupFlow UI/UX Guide

## Visual Thesis

BackupFlow should feel calm, technical, and trustworthy: dark recovery-console confidence, mint action states, white operational panels, and visible progress at every destructive or long-running step.

## Product Position

BackupFlow is a simple backup, restore, and migration plugin for WordPress. The free experience must feel complete for manual protection:

- Full local backup.
- FTP backup upload.
- Google Drive backup upload.
- Files-only backup.
- Database-only backup.
- One-click restore.
- Migration restore with URL rewrite.
- Live export and import status.

Premium and add-on features should be shown as coming soon, never as broken or fake enabled controls.

## First-Run Flow

After install and activation, BackupFlow redirects administrators to `BackupFlow > Welcome Wizard`.

The wizard uses a three-step setup:

1. What: choose Files, Database, or both.
2. When: run now in free; automatic schedules are coming soon.
3. Where: Website Server, FTP, or Google Drive in free; other providers are shown as coming soon.

The final action starts a real backup job and opens the live progress modal. The user should not land on a blank dashboard after activation.

## Admin Information Architecture

Top-level menu: BackupFlow.

Pages:

- Dashboard: current backup status, quick full backup, latest restore points.
- Create Backup: reusable three-step backup builder.
- Backups: restore, download, delete, and inspect backup points.
- Restore & Migrate: import a BackupFlow ZIP and restore to the current domain.
- Storage: local, FTP, and Google Drive setup with future providers visible.
- Settings: retention, excludes, restore safety behavior.
- Add-ons: premium roadmap and future storage/schedule surface.

## Interaction Principles

- Every long operation opens a modal with progress percentage and a terminal-style event log.
- Backup and restore jobs must be resumable across AJAX calls instead of relying on a single frozen page request.
- Restore always asks for confirmation because it can overwrite files and database tables.
- Migration copy must explain the source URL to current URL rewrite in product language, not implementation jargon.
- Coming soon providers stay disabled and visually distinct.

## Brand System

Logo: `assets/img/BackupFlow.png`.

Primary colors:

- Deep console: `#001f1d`.
- Mint action: `#36e58a`.
- Ink: `#092724`.
- Neutral surface: `#ffffff`.
- Admin background: `#f4f7f6`.

Use mint only for primary actions, selected states, and success. Use blue only for progress bars and links that need secondary emphasis. Use warm amber for roadmap labels and red only for destructive or failed states.

## Component Rules

- Buttons use 8px radius and direct command labels.
- Repeated backup rows use table layout, not card mosaics.
- Storage providers use icon-led tiles with short operational copy.
- The wizard stepper is dark and vertical on desktop, stacked on mobile.
- The modal log is dark, fixed-height, and scrollable so the interface remains stable.
- Text must wrap inside table cells and controls on small admin screens.

## Restore And Migration UX

Restore status must show these stages when applicable:

- Manifest verified.
- Safety database backup created.
- Files restoring.
- Database importing.
- URL rewrite running.
- Rewrite rules flushed.
- Restore complete.

If the source and destination URLs differ, the UI copy should say BackupFlow will rewrite the source URL to the current WordPress URL during database restore.

## Sidebar Promotion Rules

The sidebar promotes the CodeFreex ecosystem without interrupting backup work:

- BackupFlow Free feature summary.
- PageForge Programmatic SEO plugin with icon and WordPress.org link.
- Immersa Builder theme and Immersa Core Starter Templates & AI plugin with icon and WordPress.org links.

Promos must stay in the sidebar and must not block backup, restore, import, or settings actions.
