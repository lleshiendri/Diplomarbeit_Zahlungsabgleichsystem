# Matching Engine vs Matching Functions – Analysis & Patches

## A) Truth table of responsibilities

### File ownership

| Responsibility | matching_engine.php | matching_functions.php |
|----------------|---------------------|------------------------|
| Invoice ↔ Student matching (ref/name/fuzzy) | **ONLY** | Must NOT |
| Create/update MATCHING_HISTORY_TAB (automatic) | **ONLY** | Must NOT |
| Subtract STUDENT_TAB.left_to_pay (confirmed matches) | **ONLY** | Must NOT |
| Set INVOICE_TAB.student_id (confirmed) | **ONLY** | Must NOT |
| Notifications (createNotificationOnce, dedupe) | No | **Yes** |
| Schema detection (getTableColumnsCached, getInvoiceMeta) | No | **Yes** |
| Manual confirm recording (log after user confirms) | Can own | Currently has logMatchingAttempt (conflict) |
| Pipeline orchestration (runMatchingPipeline) | **ONLY** | Calls it |
| Fuzzy suggestions API (getFuzzySuggestions) | **Yes** (suggestion-only writes) | No |

### Function classification

**matching_engine.php**

| Function | Class | Notes |
|----------|--------|------|
| debugLog | SUPPORT | Logging; toggled by DEBUG |
| tableExists, columnExists | SUPPORT | Schema checks |
| normalizeText, stripPrefix | SUPPORT | Pure text |
| extractReferenceIds, extractNamesFromText | MATCHING (signals) | Pure |
| similarityLetters | MATCHING | Pure; confidence formula |
| rankCandidates | MATCHING | Pure |
| loadContext | DB READ | Stage 0; reads STUDENT_TAB, column checks |
| fetchWork | DB READ | Stage 1; reads INVOICE_TAB |
| extractSignals | SUPPORT | Stage 2; pure |
| generateCandidates | MATCHING | Stage 3; pure |
| applyBusinessRules | MATCHING | Stage 4; pure |
| historyAssistGate | DB READ | Stage 5; reads MATCHING_HISTORY_TAB |
| persistPipelineResult | DB WRITE | Stage 6; **only** stage that writes |
| runMatchingPipeline | MATCHING (orchestration) | Entry point |
| notif_has_mail_status, notif_exists, notif_insert | NOTIFICATION | Used by others |
| getFuzzySuggestions | MATCHING + DB WRITE | Writes MATCHING_HISTORY_TAB for suggestions only |
| computeFuzzyConfidence, findBestFuzzyMatch | MATCHING | Pure / read-only |
| normalizeTextForMatching, containsBothNames | MATCHING | Pure |
| findNameBasedSuggestion, findFuzzyNameSuggestion | MATCHING | Read-only (DB read for candidates) |
| attemptReferenceMatch | MATCHING | Delegates to pipeline |

**matching_functions.php**

| Function | Class | Notes |
|----------|--------|------|
| dbg_log | SUPPORT | Logging |
| matchInvoiceToStudent | SUPPORT | Stub; returns empty, no matching |
| getTableColumnsCached | SUPPORT | Schema cache |
| getInvoiceMeta | SUPPORT | Read INVOICE_TAB meta |
| **logMatchingAttempt** | **DB WRITE** | **Conflict:** writes MATCHING_HISTORY_TAB |
| createNotificationOnce | NOTIFICATION | Dedupe + insert NOTIFICATION_TAB |
| toDateString | UI/SUPPORT | Date format |
| processInvoiceMatching | PIPELINE CALLER | Calls runMatchingPipeline; then reads state; creates notifications |

### Duplicated / overlapping behavior

| Behavior | Where | Conflict? |
|----------|--------|-----------|
| normalizeText (engine) vs normalizeTextForMatching (engine) | Both in matching_engine.php | Duplicate; can unify to one. |
| extractReferenceIds | matching_engine.php only | OK (single place). |
| MATCHING_HISTORY_TAB INSERT | Engine: persistPipelineResult + getFuzzySuggestions. Functions: logMatchingAttempt. | **Conflict:** functions must not write history; manual confirm should be in engine or explicitly “post-confirm log only”. |
| Confidence for fuzzy | similarityLetters uses max(len); spec says (matching_letters / total_letters_of_db_reference)*100 | **Inconsistent:** denominator should be DB reference length. |
| ref_fuzzy in applyBusinessRules | Treated like ref_exact (full amount, invoice update) | **Conflict:** fuzzy must be suggestion only (no left_to_pay, no confirm). |

---

## B) Concrete conflict report

### 1. logMatchingAttempt (matching_functions.php, ~L92–111)

- **Does:** INSERT into MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by, is_confirmed, created_at).
- **Violation:** matching_functions must not create/update matching_history.
- **Fix:** Move “record manual confirmation” to matching_engine.php (e.g. `recordManualConfirmation($conn, $invoice_id, $student_id, $confidence, $matched_by)`) and call it from unconfirmed.php. Keep in matching_functions only schema/notification helpers; or keep logMatchingAttempt but document it as “manual confirm only” and have unconfirmed call engine’s recordManualConfirmation which internally inserts history (so the only writer of history for “matching” is the engine). **Minimal patch:** Add in engine `recordManualConfirmation()` that does the INSERT; unconfirmed.php calls `recordManualConfirmation($conn, $invoice_id, $student_id, 100.0, 'manual', true)` and we deprecate/remove direct use of `logMatchingAttempt` for this flow (or make logMatchingAttempt a thin wrapper that calls engine).

### 2. getFuzzySuggestions (matching_engine.php, ~L1127–1673)

- **Does:** For name_suggest, name_fuzzy, reference_fuzzy inserts into MATCHING_HISTORY_TAB (with duplicate check). Does not subtract left_to_pay; does not set INVOICE_TAB.student_id. So “suggestion only” is partially correct.
- **Violation:** Spec says fuzzy suggestion must not “mark invoice as processed/confirmed”. Writing a row with is_confirmed=0 is OK; ensure INSERT uses is_confirmed=0 and that pipeline does not treat these as “confirmed”.
- **Fix:** Ensure all suggestion INSERTs set is_confirmed=0 (if column exists). No code change to stop inserting suggestions into history (it’s acceptable for audit); ensure no invoice update or left_to_pay subtract.

### 3. applyBusinessRules (matching_engine.php, ~L358–418)

- **Does:** Puts ref_fuzzy in same bucket as ref_exact for multi-ref split and single top match; persistPipelineResult then writes all and updates invoice.
- **Violation:** Fuzzy must be suggestion only: is_confirmed=0, no left_to_pay subtract, no invoice.student_id update.
- **Fix:** In applyBusinessRules, mark each match with `is_confirmed => ($match['matched_by'] === 'ref_exact' || (strpos($match['matched_by'], 'name_exact') !== false && $match['confidence'] >= 0.90)`. In persistPipelineResult: only update INVOICE_TAB.student_id and subtract left_to_pay for matches where is_confirmed=1; for is_confirmed=0 still insert history but do not update invoice or balances.

### 4. persistPipelineResult (matching_engine.php)

- **Missing:** Does not subtract STUDENT_TAB.left_to_pay for confirmed matches; does not set is_confirmed on INSERT.
- **Fix:** Add is_confirmed to INSERT (from decision). For confirmed matches only: subtract share_amount from STUDENT_TAB.left_to_pay (with rounding so sum of shares = invoice amount).

### 5. Idempotency (matching_engine.php)

- **Does:** fetchWork filters (student_id IS NULL OR student_id = 0) only when transactionId is null; when transactionId is set it fetches that invoice regardless. So re-running pipeline for same invoice_id can duplicate MATCHING_HISTORY_TAB rows and (once we add it) double-subtract left_to_pay.
- **Fix:** At start of pipeline for an invoice, or at start of persistPipelineResult, check “already processed”: e.g. existing MATCHING_HISTORY_TAB with is_confirmed=1 for this invoice_id, or INVOICE_TAB.student_id already set for this invoice. If so, skip persistence (and skip left_to_pay subtract). Optionally skip entire pipeline for that invoice when running by transactionId.

### 6. similarityLetters / confidence (matching_engine.php)

- **Does:** `confidence = match / maxLen` (max of two lengths).
- **Spec:** confidence_score = (matching_letters / total_letters_of_db_reference) * 100.
- **Fix:** For ref_fuzzy use denominator = length of DB reference (after prefix), not max. Add helper e.g. similarityLettersDenomDbRef($ref, $dbRef, $prefix) and use in generateCandidates for ref_fuzzy; keep 0–1 scale then *100 when persisting.

---

## C) Pipeline audit (matching_engine.php)

| Stage | Function | Input | Output | Pure? | DB |
|-------|----------|--------|--------|--------|-----|
| 0 | loadContext | $conn | $ctx (prefix, has_history, invoice_columns, student_refs) | No | Reads tables/columns, STUDENT_TAB |
| 1 | fetchWork | $conn, $ctx, $transactionId? | array of invoice rows | No | Reads INVOICE_TAB |
| 2 | extractSignals | $txn, $ctx | signals (ref_ids_found, names_found, normalized_text) | Yes | No |
| 3 | generateCandidates | $txn, $signals, $ctx | candidates (student_id, matched_by, confidence, evidence) | Yes | No |
| 4 | applyBusinessRules | $txn, $signals, $candidates, $ctx | decision (matches, needs_review, reason) | Yes | No |
| 5 | historyAssistGate | $conn, $txn, $signals, $decision, $ctx | decision (possibly amended) | No | Reads MATCHING_HISTORY_TAB |
| 6 | persistPipelineResult | $conn, $txn, $signals, $decision, $ctx | void | No | **Writes** MATCHING_HISTORY_TAB, INVOICE_TAB, (to add: STUDENT_TAB.left_to_pay) |

- **Stage 6 is the only stage that writes.** Stages 0, 1, 5 read DB; 2–4 are pure transformations.
- **Logging:** debugLog() and #region file_put_contents can leak invoice id, match counts, and normalized text. Restrict to DEBUG/MATCHING_DEBUG and avoid logging PII (e.g. beneficiary, full reference text) in production; use MATCHING_DEBUG to toggle.

---

## D) DB schema validation checklist (database: buchhaltungsql1 in code; user mentioned buchhaltung_16_1_2026)

Run on the actual DB you use:

```sql
SHOW TABLES;
-- Expect: STUDENT_TAB, INVOICE_TAB, MATCHING_HISTORY_TAB, LEGAL_GUARDIAN_TAB, NOTIFICATION_TAB, ...

DESCRIBE STUDENT_TAB;
-- Expect: id, reference_id, forename, name, long_name, left_to_pay, amount_paid, ...

DESCRIBE INVOICE_TAB;
-- Expect: id, reference_number, reference, reference_text(?), description, beneficiary, amount_total, amount(?), paid_amount(?), processing_date, student_id, created_at(?), needs_review(?)

DESCRIBE MATCHING_HISTORY_TAB;
-- Expect: id, invoice_id, student_id, confidence_score, matched_by, is_confirmed(?), reference_text(?), beneficiary(?), created_at(?)

DESCRIBE LEGAL_GUARDIAN_TAB;
-- Expect: id, first_name, last_name, ...
```

If column names differ:

- **reference vs reference_number:** Code uses both; INVOICE_TAB in engine uses `reference` in fetchWork; elsewhere reference_number. If DB has only one, add alias or use the existing name.
- **reference_text:** loadContext/fetchWork use it if present; getFuzzySuggestions uses reference+description+beneficiary. If MATCHING_HISTORY_TAB has no reference_text/beneficiary, use schema-safe INSERT (only existing columns) or add columns.
- **left_to_pay:** Must exist on STUDENT_TAB for subtraction. If missing, add or skip subtraction in code until schema is updated.

Prefer code fix: use columnExists() and only subtract left_to_pay / only INSERT columns that exist.

---

## E) Correctness tests (executable steps)

### 1) One perfect ref match

- **Setup:** Student with reference_id = 'HTL-ABC-A7'. Invoice with reference_text/reference/description containing 'HTL-ABC-A7', amount_total = 100.
- **Run:** runMatchingPipeline(transactionId) for that invoice_id.
- **Expected:** One MATCHING_HISTORY_TAB row: student_id, confidence_score=100, matched_by=ref_exact, is_confirmed=1. INVOICE_TAB.student_id = that student. STUDENT_TAB.left_to_pay for that student decreased by 100 (no rounding). No duplicate on second run.

### 2) Two perfect ref matches

- **Setup:** Two students with reference_id 'HTL-A1-X' and 'HTL-A2-Y'. Invoice text contains both refs, amount_total = 200.
- **Run:** Pipeline for that invoice.
- **Expected:** Two MATCHING_HISTORY_TAB rows (ref_exact, is_confirmed=1). Each student share_amount=100; left_to_pay decreased by 100 each. Invoice linked to first student (or keep primary as first). Total subtracted = 200.

### 3) Four perfect ref matches

- **Setup:** Four students with distinct refs; invoice text contains all four, amount_total = 400.
- **Expected:** Four history rows, each share 100; left_to_pay decreased by 100 per student; total 400. Rounding: if 400/4=100 exact, no remainder.

### 4) Perfect ref match but invoice already processed

- **Setup:** Same as (1) but INVOICE_TAB.student_id already set for that invoice (or existing MATCHING_HISTORY_TAB with is_confirmed=1 for this invoice_id).
- **Run:** Pipeline for that invoice_id (e.g. by transactionId).
- **Expected:** No new MATCHING_HISTORY_TAB row; no change to left_to_pay; no second subtract (idempotency).

### 5) Fuzzy ref suggestion only

- **Setup:** Invoice with typo ref 'HTL-ABC-A8'; DB has 'HTL-ABC-A7'. No exact match.
- **Run:** Pipeline and/or getFuzzySuggestions.
- **Expected:** Pipeline: if only ref_fuzzy, decision has is_confirmed=0; persist: history row with is_confirmed=0, confidence &lt; 100; no INVOICE_TAB.student_id update; no left_to_pay subtract. getFuzzySuggestions: returns suggestion; if it inserts history, is_confirmed=0.

### 6) Name match (student)

- **Setup:** Invoice text "Payment for Max Mustermann", no ref IDs; STUDENT_TAB has forename=Max, name=Mustermann.
- **Expected:** Candidate name_exact; if applied as confirmed (e.g. confidence 90%), one history row, invoice linked, left_to_pay decreased. If policy is “name_exact = suggestion only”, then is_confirmed=0 and no left_to_pay.

### 7) Guardian name match

- **Setup:** Invoice text "Payment from Maria Müller"; LEGAL_GUARDIAN_TAB has first_name=Maria, last_name=Müller; student with name=Müller.
- **Expected:** findNameBasedSuggestion / pipeline candidate resolves to that student; suggestion or confirmed per policy; no double-count if both student and guardian match.

### 8) Fuzzy name below threshold

- **Setup:** Invoice text only "Smith" (no first name); fuzzy name score below confirm threshold.
- **Expected:** decision has match with low confidence or needs_review; is_confirmed=0; no invoice update; no left_to_pay subtract; optionally one history row as suggestion.

---

## F) Implementation patches (summary)

1. **Enforce matching-only-in-engine:** Move manual-confirm history write to engine (`recordManualConfirmation`), call from unconfirmed.php; keep logMatchingAttempt in matching_functions only for backward compatibility or remove its use from unconfirmed.
2. **Idempotency:** In persistPipelineResult (or at start of pipeline per invoice), if invoice already has is_confirmed=1 in MATCHING_HISTORY_TAB or INVOICE_TAB.student_id set, skip all writes for that invoice.
3. **Confidence:** For ref_fuzzy use (matching_letters / length_of_db_reference) * 100; add helper and use in generateCandidates.
4. **Amount split:** In persistPipelineResult, for confirmed matches with N students: compute shares in cents (e.g. floor(amount_cents/N) for first N-1, last gets remainder); subtract each share from STUDENT_TAB.left_to_pay; ensure total = invoice amount.
5. **Confirmed vs fuzzy:** In applyBusinessRules set is_confirmed per match (ref_exact or name_exact with high confidence); in persistPipelineResult only update invoice and left_to_pay for is_confirmed=1; insert all matches into history with is_confirmed flag.
6. **AJAX debug endpoint:** Add ajax/debug_matching.php (auth + DEBUG flag), run pipeline for given invoice_id, return JSON (stages, timing, DB writes, SQL errors); add minimal JS to call it and print to &lt;pre&gt;.

---

## Implemented (summary)

- **matching_engine.php:** `similarityLettersDbRefDenom()` for spec confidence; `applyBusinessRules` sets `is_confirmed` (ref_exact / name_exact ≥0.9); multi-ref only ref_exact; `persistPipelineResult` idempotency check, `is_confirmed` on INSERT, `splitAmountNoCentsLost()`, `STUDENT_TAB.left_to_pay` subtract for confirmed only; `recordManualConfirmation()` for manual confirm.
- **unconfirmed.php:** Calls `recordManualConfirmation()` instead of `logMatchingAttempt()`.
- **getFuzzySuggestions:** All suggestion INSERTs use `is_confirmed=0` when column exists.
- **ajax/debug_matching.php:** Debug endpoint (auth + Admin or MATCHING_DEBUG); returns JSON stages, timing, db_writes, sql_errors.
- **ajax/debug_matching_harness.php:** Minimal page with vanilla JS calling the endpoint and printing JSON to `<pre>`.

*End of analysis. Patches applied in codebase.*
