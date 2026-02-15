# How to test the matching pipeline (and where it fails)

## 1. Prerequisites: schema and DB

**Check that the database has the expected tables and columns:**

```bash
# CLI (uses db_connect.php → DB_NAME)
php validate_schema.php
```

- **If you see "TABLE MISSING" or "MISSING COLUMNS"**  
  → Fix the database (create table/column) or update `includes/schema_buchhaltung_16_1_2026.php` to match your DB.

- **To test against buchhaltung_16_1_2026:**  
  Before running any script, set the DB name. Example in a small wrapper:

  ```php
  // test_with_book16.php
  define('DB_NAME', 'buchhaltung_16_1_2026');
  require_once 'validate_schema.php';
  ```

  Or run from the project root with:

  ```bash
  php -d auto_prepend_file=prepend_db.php validate_schema.php
  ```

  (Create `prepend_db.php` with `<?php define('DB_NAME', 'buchhaltung_16_1_2026');`.)

---

## 2. Dry run (no DB writes) – single invoice

**Best first test:** run the pipeline for one invoice **without** persisting.

**Option A – URL (browser, must be logged in):**

```
https://your-domain/.../matching_engine.php?run_pipeline=1&transaction_id=42&dry_run=1
```

- Replace `42` with a real `INVOICE_TAB.id`.
- If nothing is printed, the script may have exited earlier (e.g. no `$conn`). Check the PHP error log.

**Option B – CLI:**

```bash
php -r "
define('DB_NAME', 'buchhaltung_16_1_2026');  // optional
\$_GET['run_pipeline'] = 1;
\$_GET['transaction_id'] = 42;
\$_GET['dry_run'] = 1;
require 'matching_engine.php';
"
```

- Replace `42` with a real invoice id.
- Errors appear in stderr or the script may die with a fatal error.

**Option C – Debug endpoint (recommended):**

1. Log in as **Admin** (or set `MATCHING_DEBUG=true`).
2. Open:  
   `https://your-domain/.../ajax/debug_matching_harness.php`
3. Enter an **Invoice / Transaction ID** (e.g. `42`).
4. Leave **“Persist”** unchecked (dry run).
5. Click **“Run pipeline”**.

You get JSON with:

- `stages[]`: each stage name, `output_summary`, `duration_ms`.
- `sql_errors[]`: any SQL errors reported.
- `duration_total_ms`.

**Where it can fail:**

| Stage / step        | Symptom | What to check |
|---------------------|--------|----------------|
| Before Stage 0      | Blank page / 500 / fatal | PHP error log; `db_connect.php` (DB_NAME, credentials); `require` path. |
| Stage0_loadContext | `sql_errors` or empty `students_cached` | DB connection; `STUDENT_TAB` / `INVOICE_TAB` exist; column names (run `validate_schema.php`). |
| Stage1_fetchWork   | `count: 0` for your invoice id | Invoice exists; `INVOICE_TAB` has `student_id`; for “unassigned only” fetch, invoice must have `student_id IS NULL OR student_id = 0`. |
| Stage2–4           | Exception in JSON / 500 | PHP error log; `extractSignals` / `generateCandidates` / `applyBusinessRules` (e.g. missing array keys). |
| Stage5_historyAssistGate | `sql_errors` | `MATCHING_HISTORY_TAB` exists; columns `reference_text`, `beneficiary`, `confidence_score`, `student_id`, `matched_by`. |
| Stage6 (when persist=1) | `sql_errors` or no rows updated | Idempotency (invoice already has `student_id` or confirmed history); `MATCHING_HISTORY_TAB` / `INVOICE_TAB` / `STUDENT_TAB` columns; `left_to_pay` / `amount_paid` exist on `STUDENT_TAB`. |

---

## 3. Run with persist (writes DB) – single invoice

1. Pick an invoice that is **not** yet assigned (e.g. `student_id IS NULL`) and note its `id` and `amount_total`.
2. Ensure at least one student has a **reference_id** that appears in the invoice text (or name in description).
3. In the debug harness, enter that invoice id, **check “Persist”**, click “Run pipeline”.
4. Or call:  
   `ajax/debug_matching.php?invoice_id=42&persist=1`

**Then check the database:**

- **INVOICE_TAB:** `student_id` set for that invoice (if there was a confirmed match).
- **MATCHING_HISTORY_TAB:** New row(s) for this `invoice_id` (and `is_confirmed` 0 or 1 as expected).
- **STUDENT_TAB:** For the matched student(s), `left_to_pay` decreased and `amount_paid` increased by the correct share (only if match was confirmed).

**If nothing changes:**

- Pipeline might have skipped due to **idempotency** (invoice already had `student_id` or a confirmed history row).
- Or there was **no confirmed match** (only fuzzy/suggestions) → then only history rows with `is_confirmed=0`, no invoice/balance update.

---

## 4. Quick “where did it fail?” script (CLI)

Run one invoice through the pipeline and print the first failure (stage or exception):

```bash
php test_matching_once.php 42
```

(Use invoice id `42` or any id.)  
See **Section 5** below for what this script does and what output means.

---

## 5. Logs and error locations

| Where | What to look for |
|-------|-------------------|
| **Browser / JSON** | `ajax/debug_matching.php` → `stages`, `sql_errors`. |
| **PHP error log** | `error_log()` and uncaught exceptions (file path in `php.ini`: `error_log`). |
| **Debug log file** | If `MATCHING_DEBUG` or `DEBUG` is true, pipeline logs to `.cursor/debug.log` or `debug_matching.log` (see `matching_engine.php`). |
| **MySQL** | Check `$conn->error` after prepare/execute; the debug endpoint returns these in `sql_errors`. |

---

## 6. Checklist before going live

- [ ] `php validate_schema.php` reports “All expected tables and columns present” for the DB you use.
- [ ] Dry run for one known invoice returns JSON with all stages and no `sql_errors`.
- [ ] One test with persist=1: invoice gets `student_id`, history row(s), and (for confirmed match) student `left_to_pay` / `amount_paid` updated.
- [ ] Run pipeline again for the same invoice (idempotency): no duplicate history rows, no second subtraction from `left_to_pay`.
