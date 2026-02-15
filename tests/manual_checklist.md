## Manual Checklist – Matching & Notifications

This checklist focuses on the classic matching (`matching_functions.php`) and the newer pipeline (`matching_engine.php`). Use your normal UI (no direct DB editing).

### 1. Single reference ID match – classic path
1. Insert a new invoice via `add_transactions.php` or `import_files.php` with `INVOICE_TAB.reference` or `reference_number` equal to a known `STUDENT_TAB.reference_id`.
2. Confirm the request completes without PHP errors.
3. In DB, verify for the new invoice row:
   - `INVOICE_TAB.student_id` is set to the expected student’s id.
4. In `MATCHING_HISTORY_TAB`, verify:
   - A row exists with this `invoice_id`, `student_id`, and `matched_by` = `reference_id` (or similar classic value).
5. In `NOTIFICATION_TAB`, verify:
   - An `info` notification exists for `invoice_reference` of the invoice.

Expected result: student_id updated; one matching_history row; one info notification; no warnings for this invoice.

### 2. No reference ID – fallback name matching (classic)
1. Insert an invoice with empty/irrelevant `reference`/`reference_number` but a `beneficiary` that clearly contains an existing student name.
2. Let `processInvoiceMatching` run via the normal flow.
3. In DB, check `MATCHING_HISTORY_TAB` for this `invoice_id`:
   - A row with non-null `student_id` and `matched_by` = `name` or `regex`.
4. Check `INVOICE_TAB.student_id`:
   - If confidence ≥ `CONFIRM_THRESHOLD` (70), expect `student_id` set; otherwise it may remain null.
5. Check `NOTIFICATION_TAB` for `warning` vs `info` notification created for this `invoice_reference`.

Expected result: a matching_history row always exists; invoice is confirmed only when confidence ≥ 70; notifications reflect confirmed/unconfirmed state.

### 3. Fuzzy / suggestion flow – engine AJAX (`get_fuzzy_suggestions`)
1. Use the AJAX endpoint `matching_engine.php?action=get_fuzzy_suggestions&invoice_id=…` on an invoice that has a near-but-not-exact reference to an existing student.
2. Confirm JSON suggestions are returned with `student_id`, `confidence`, and `matched_by` indicating fuzzy or name-based suggestions.
3. In `MATCHING_HISTORY_TAB`, verify:
   - Suggestion rows exist with appropriate `matched_by` values (`reference_fuzzy`, `name_suggest`, or `name_fuzzy`) and `confidence_score` scaled 0–100.
4. Verify that **no** change was made to `INVOICE_TAB.student_id` from this AJAX call alone.

Expected result: history suggestions are logged, but invoice is not confirmed/updated by AJAX alone.

### 4. Pipeline engine – explicit run
1. Identify an invoice with `student_id` still null and a reference that should uniquely identify a student using the new multi-ref logic.
2. Call the pipeline directly:
   - Browser: `matching_engine.php?run_pipeline=1&transaction_id={invoice_id}`
   - Or CLI: `php matching_engine.php` (if configured to process unmatched invoices).
3. In `MATCHING_HISTORY_TAB`, verify:
   - New entries for this `invoice_id` with `matched_by` in `{ref_exact, ref_fuzzy, name_exact, name_fuzzy, history_assist}`.
4. Verify `INVOICE_TAB.student_id` now matches the chosen student (or remains null if no match).

Expected result: pipeline produces deterministic candidates; one or more history rows are inserted; `student_id` is updated only when a confident match exists.

### 5. Notifications consistency – classic vs engine
1. For the same invoice, compare notifications produced by classic matching (`processInvoiceMatching`) and any produced by engine suggestions/pipeline.
2. Check `NOTIFICATION_TAB`:
   - `invoice_reference` is stable and human-readable.
   - `urgency` values (`info`, `warning`) are consistent with the actual state (confirmed vs unconfirmed).
3. Confirm there are no duplicate rows for the same `(invoice_reference, urgency)` pair when the same flow is repeated.

Expected result: notifications are deduped across runs and flows; both classic and engine paths write compatible records.

### 6. Latencies dependency sanity-check (read-only)
1. Run one of the matching flows above to set `INVOICE_TAB.student_id` for a current-month invoice.
2. Visit `latencies.php` and locate the student.
3. Verify:
   - `this_month_count` and “Days Late” text change as expected when a confirmed invoice is present vs absent.

Expected result: latencies reflects only confirmed (student_id set) invoices; unmatched invoices are intentionally ignored.
