# Manual QA guide — WooCommerce Order Health Monitor

Concise checklist for the current plugin implementation. Mark items only after **you** run them on your environment.

### Verification status (do not confuse with this checklist)

| Status | What it covers |
|--------|----------------|
| **Done during development (not a substitute for this guide)** | PHP `php -l` on plugin PHP files; class autoload smoke checks; schema/option bootstrap on a local site; limited live scan/issue/report API checks while building features. Those runs are **historical** and environment-specific. |
| **Still required (this document)** | Every section below—detection rules, lifecycle, dashboard/reports/CSV, auth/nonces, WC deactivation, HPOS, cleanup. Treat all as **unverified on your machine** until you complete them. |

There is **no** automated test suite in this repository.

**Defaults (Settings → Reset to defaults):** failed enabled (no age); pending **24h** (`date_created`); processing **72h** (status-entry meta, else `date_modified`); on-hold **48h** (same as processing); scan frequency hourly; batch size 50.

**No test-data generator** ships with this plugin. Create/change orders only in WooCommerce admin (or your own tools), and clean them up manually when finished.

**Roles:** Prefer an Administrator or Shop Manager (`manage_woocommerce`) for happy-path tests. Use a Subscriber (or logged-out) for unauthorized checks.

---

## 0. Prerequisites

| Setup | Action | Expected |
|-------|--------|----------|
| WP + WooCommerce + this plugin | Activate plugin | No fatals; tables `{prefix}ohm_issues` and `{prefix}ohm_scan_history` exist after activation/upgrade |
| Capability to manage store | Open **Order Health** | Submenus: Dashboard, Issues, Reports, Settings |

---

## 1. Detection rules and thresholds

Lower thresholds temporarily (e.g. pending/processing/on-hold = `1`) if you cannot age orders; restore defaults afterward (**Cleanup**).

### 1.1 Failed orders

| | |
|--|--|
| **Setup** | Failed rule enabled. One order in status `failed`. |
| **Action** | Dashboard → **Scan Now**. |
| **Expected** | Open issue type `failed` for that order ID on **Issues** (state Open). |
| **Cleanup** | Return order to a normal status or delete the test order; re-scan or dismiss as preferred. Restore settings if changed. |

### 1.2 Pending — recent vs overdue

| | Recent | Overdue |
|--|--------|---------|
| **Setup** | Pending rule on; threshold H hours. New `pending` order (age ≪ H). | `pending` order with age ≥ H (or H=1 and wait / carefully backdate created date). |
| **Action** | **Scan Now** | **Scan Now** |
| **Expected** | No open `pending` issue for that order. | Open `pending` issue; description cites pending payment age. |
| **Cleanup** | Delete or complete test orders; reset thresholds if temporary. |

### 1.3 Processing — recent vs overdue

| | Recent | Overdue |
|--|--------|---------|
| **Setup** | Processing rule on. `processing` order younger than threshold (prefer `_ohm_entered_status_*` after a real status change). | Age ≥ threshold via meta or fallback `date_modified`. |
| **Action** | **Scan Now** | **Scan Now** |
| **Expected** | No open `processing` issue. | Open `processing` issue. |
| **Cleanup** | As above. |

### 1.4 On hold — recent vs overdue

| | Recent | Overdue |
|--|--------|---------|
| **Setup** | On-hold rule on. `on-hold` order below / at-or-above threshold. |
| **Action** | **Scan Now** |
| **Expected** | Below: no issue. At/above: open `on_hold` issue; wording asks for **review**, not that the order is erroneous. |
| **Cleanup** | As above. |

### 1.5 Rule disabled

| | |
|--|--|
| **Setup** | Open issue exists for a type; disable that rule in Settings and save. |
| **Action** | **Scan Now**. |
| **Expected** | Open issues of that type resolve (end-of-scan reconciliation). New matches for that type are not created. |
| **Cleanup** | Re-enable the rule if needed. |

---

## 2. Duplicate prevention

| | |
|--|--|
| **Setup** | At least one order that still matches an enabled rule (open issue already exists or will be created). |
| **Action** | **Scan Now** twice without changing the order. |
| **Expected** | Still **one** row per `{order_id}:{issue_type}` in `{prefix}ohm_issues` / Issues list. Second scan updates (`last_checked`), does not insert a duplicate. Scan history may show created=0 and updated ≥ 0. |
| **Cleanup** | None required beyond normal test-order cleanup. |

---

## 3. Issue resolution, dismissal, reopening

| Case | Setup | Action | Expected | Cleanup |
|------|-------|--------|----------|---------|
| Auto-resolve | Open issue; change order so rule no longer matches (e.g. complete it) | **Scan Now** | Issue state **resolved**; `resolved_at` set | Optional delete test order |
| Dismiss | Open issue | Issues → **Dismiss** (nonce URL) | State **dismissed** | — |
| Dismissed + still matching | Dismissed issue; condition still true | **Scan Now** | Stays **dismissed**; not silently reopened; `last_checked` may update | — |
| Reopen | Dismissed or resolved issue | Issues → **Reopen** | State **open** | Dismiss again or resolve via scan |

**Note:** Issues page defaults to **Open** only. Use State = All / Resolved / Dismissed to see historical rows (Reports shows history by `first_detected` date).

---

## 4. Dashboard vs Issues vs Reports

| | |
|--|--|
| **Setup** | Mix of open / resolved / dismissed issues and at least one completed scan. |
| **Action** | Compare Dashboard cards, **Issues** (filter Open), **Reports** period summary and tables. |
| **Expected** | Dashboard open counts = Issues with state Open (same types). Reports “From scan history” sums match scan rows in the filtered period (`started_at`). Reports “From issue records” use `first_detected` / `resolved_at` / `dismissed_at`—not a separate event log. Scan-reported created/reopened is one counter (not split). Dashboard “Monitored orders processed” excludes statuses outside enabled rules (e.g. completed). |
| **Cleanup** | None. |

---

## 5. Reports: filters, pagination, CSV

| | |
|--|--|
| **Setup** | Enough scan/issue rows for >1 page if possible (or accept single-page). Know current filters. |
| **Action** | Set period; scan status/trigger; issue state/type/order ID → Apply. Paginate. Export both CSVs. Reset filters. |
| **Expected** | Tables respect filters; pagination keeps query args. Scan CSV matches scan filters (all matching rows, not one page). Issue CSV matches issue filters + live WC fields (no email/address/phone/token). Empty filters show clear empty copy. Invalid dates show a warning and fall back safely. |
| **Cleanup** | Delete downloaded CSV files if they contain store data you do not want retained. |

---

## 6. Unauthorized access and invalid nonces

| Case | Setup | Action | Expected |
|------|-------|--------|----------|
| No capability | Subscriber (or user without `manage_woocommerce` / `manage_options` as applicable) | Open `admin.php?page=woo-order-health-monitor` (and Issues/Reports/Settings) | Access denied / cannot manage screens |
| Bad dismiss nonce | Manager session | Call dismiss/reopen URL with wrong/missing `_wpnonce` | Request fails; issue state unchanged |
| Bad export nonce | Manager | Reports export URL with invalid nonce | Export refused (die/error); no CSV |
| Bad settings nonce | Manager | POST settings without valid nonce | Save rejected |

---

## 7. WooCommerce deactivation

| | |
|--|--|
| **Setup** | Plugin active; note admin notice behavior. |
| **Action** | Deactivate WooCommerce. Open wp-admin and Order Health. |
| **Expected** | No PHP fatal. Administrators see soft-dependency warning. Order Health may use `manage_options`; dashboard shows WooCommerce inactive; Scan Now unavailable/errors clearly. Reactivate WooCommerce → monitoring UI returns. |
| **Cleanup** | Leave WooCommerce **active**. |

---

## 8. HPOS compatibility

| | |
|--|--|
| **Setup** | WooCommerce → Settings → Advanced → Features: note if High-Performance Order Storage is enabled. Plugin declares `custom_order_tables` compatibility and uses `wc_get_orders` / `wc_get_order` only. |
| **Action** | With HPOS on (or off, if you can toggle safely on a **staging** site), run **Scan Now** and open an issue’s View order link. |
| **Expected** | WooCommerce does not flag this plugin as incompatible. Scans still find orders; order edit links work. No direct queries of WC order tables from this plugin. |
| **Cleanup** | Do not leave production HPOS settings changed unless intentional. |

---

## 9. Dummy / test order cleanup

| | |
|--|--|
| **Setup** | N/A — **no built-in test-data generator** in this plugin. |
| **Action** | In WooCommerce → Orders, delete or restore any orders you created only for QA (and trash permanently if required). On Issues, filter All states and dismiss/ignore leftover rows, or leave resolved history. Optionally prune old scan rows via retention setting + a later successful scan, or leave history. |
| **Expected** | Store returns to your preferred baseline; plugin tables may still hold historical issue/scan rows unless you remove them deliberately (no uninstall drop is documented as automatic). |
| **Cleanup** | Restore Settings → **Reset to defaults** if you changed thresholds for testing. |

---

## Sign-off

| Field | Value |
|-------|--------|
| Date | |
| WordPress / WooCommerce versions | |
| HPOS on/off | |
| Tester | |
| Failures / notes | |
