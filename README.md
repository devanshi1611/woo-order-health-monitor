# WooCommerce Order Health Monitor

A WordPress / WooCommerce admin plugin that finds orders needing attention—failed payments and orders that have stayed in pending, processing, or on-hold longer than you allow—then tracks those findings as issues with scan history and reports.

Built as a standalone plugin (PHP, WordPress admin UI, WooCommerce CRUD APIs). No front-end JavaScript framework.

## What it addresses

Store operators often miss:

- Orders stuck in **failed** payment status
- **Pending payment** carts that never complete
- **Processing** or **on-hold** orders that sit longer than the store’s operational SLA

This plugin scans eligible orders on a schedule or on demand, records issues without changing order statuses, and surfaces counts, history, and CSV exports in wp-admin.

## Features

- Configurable detection rules (enable/disable + age thresholds where applicable)
- Manual **Scan Now** and WP-Cron scheduled scans (batched, lock-protected, resumable)
- Issue lifecycle: **open** → **resolved** (automatic when the condition clears) or **dismissed** / **reopened** (admin)
- Dashboard with open-issue summaries and last-scan outcome
- Issues list with filters, pagination, and order edit links
- Reports: period summary, scan history, detailed issue report, filters, CSV export
- Soft WooCommerce dependency (loads and warns if WooCommerce is inactive)
- Declares WooCommerce **HPOS** (`custom_order_tables`) compatibility and reads orders only via WooCommerce APIs

## Requirements

| Requirement | Declared in plugin header |
|-------------|---------------------------|
| WordPress | 6.5+ (`Requires at least`) |
| PHP | 7.4+ (`Requires PHP`) |
| WooCommerce | Active for scanning and order enrichment (soft dependency; not declared as `Requires Plugins`) |

No “Tested up to” / “WC tested up to” versions are claimed in the header—verify on your stack.

## Installation

1. Copy this folder to `wp-content/plugins/woo-order-health-monitor/`.
2. Activate **WooCommerce Order Health Monitor** under **Plugins**.
3. Activate WooCommerce if it is not already active.
4. Open **Order Health** in the admin menu.

**On activation:** creates `{prefix}ohm_issues` and `{prefix}ohm_scan_history`, stores default settings, schedules recurring scans.
**On deactivation:** clears cron events and scan locks; settings and stored issues remain.

## Configuration and first scan

1. Go to **Order Health → Settings**.
2. Enable/disable rules and set thresholds (hours). Defaults:

| Rule | Default |
|------|---------|
| Failed orders | Enabled (no age threshold) |
| Pending payment | Enabled, **24** hours (`date_created`) |
| Processing | Enabled, **72** hours (status-entry meta, else `date_modified`) |
| On hold | Enabled, **48** hours (same age strategy as processing) |
| Scan frequency | Hourly |
| Batch size | 50 (10–200) |
| Scan history retention | 30 days |

3. Save, then open **Dashboard** and click **Scan Now**.

Invalid settings are rejected with an admin notice. **Reset to defaults** restores options only (not issue/scan rows).

## Issues: create, resolve, dismiss

| State | Meaning |
|-------|---------|
| Open | Condition currently matches an enabled rule |
| Resolved | Condition no longer matches (or order missing); set on scan |
| Dismissed | Admin dismissed; re-detection keeps history and does **not** auto-reopen |

- Stable key `{order_id}:{issue_type}` prevents duplicate rows across scans.
- The **Issues** screen defaults to **Open** only; use filters for resolved/dismissed.
- The plugin does **not** change order status, refund, or retry payment.

## Reports and CSV export

**Order Health → Reports**

- **Scan history** metrics use stored scan rows (`started_at`, status, trigger, counters).
- **Issue** metrics/tables use issue timestamps (`first_detected` for the table/date filter; period cards also use `resolved_at` / `dismissed_at`).
- Issues are **not** linked to a scan ID in the database.
- CSV exports (scan history + issue report) use the same filters as the tables, stream all matching rows, require capability + nonce, and omit billing/email/phone/payment tokens.

See [TESTING.md](TESTING.md) for a manual QA checklist covering reports and exports.

## Architecture (high level)

| Piece | Role |
|-------|------|
| `woo-order-health-monitor.php` | Bootstrap, activation hooks, HPOS compatibility declaration |
| `OHM_Scanner` / `OHM_Rules` / `OHM_Scheduler` | Batched scans, detection, WP-Cron |
| `OHM_Issue_Repository` / `OHM_Scan_Repository` | Plugin-owned tables via prepared SQL |
| `OHM_Settings` | Options API |
| Admin page classes | Dashboard, Issues, Reports, Settings |
| `OHM_Csv_Exporter` / `OHM_Order_Enricher` | Secure CSV streaming; order fields via `wc_get_orders` / order objects |

Design choices: WooCommerce CRUD only (no direct WC order-table SQL); soft WC dependency; WP-Cron with documented traffic dependency; status-entry meta (`_ohm_entered_status_at`) when status changes for better age accuracy.

## Security practices in code

Capability checks (`manage_woocommerce` when WC is active, else `manage_options` for soft-dependency UI), nonces on mutating/export actions, sanitization/escaping, prepared SQL for plugin tables, CSV formula-injection neutralization. This is not a formal security audit.

## Limitations

- Processing/on-hold age falls back to `date_modified` when status-entry meta is missing (edits can under-report age).
- WP-Cron is traffic-dependent; not wall-clock precise.
- Created vs reopened are one scan counter—cannot be split historically.
- No per-scan issue ID linkage.
- No built-in test-data generator or uninstall table drop UI.
- Author metadata in the plugin header may still be a placeholder until you set it.

## Verification status

Development included PHP syntax checks and some local smoke checks against a WooCommerce site. **Full manual QA is documented in [TESTING.md](TESTING.md)** and should be run (or re-run) on your environment before relying on the plugin in a real store. Do not treat this repository as “fully tested” or “production-certified.”

## Project layout

```
woo-order-health-monitor/
├── woo-order-health-monitor.php
├── includes/          # PHP classes (OHM_*)
├── assets/css/        # Admin styles
├── README.md
├── TESTING.md
└── .gitignore
```

## License

No `LICENSE` file is included yet. WordPress.org plugins are typically **GPL-2.0-or-later** (or GPL-compatible). Confirm the copyright holder name/year before adding a license file and header fields.

## Contributing / portfolio note

This repository is intended as a portfolio WordPress plugin. Issues and PRs are welcome if you publish it; please keep changes scoped and avoid committing local WordPress/XAMPP config or secrets.
