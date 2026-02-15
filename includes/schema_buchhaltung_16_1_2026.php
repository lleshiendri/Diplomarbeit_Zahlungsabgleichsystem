<?php
/**
 * Schema reference for database buchhaltung_16_1_2026.
 * Used by matching_engine.php and matching_functions.php.
 * All table and column names below must match the actual database.
 * Run validate_schema.php against your DB to check.
 */

if (!defined('SCHEMA_BOOK_16')) {
    define('SCHEMA_BOOK_16', true);
}

return [
    'database' => 'buchhaltung_16_1_2026',

    'STUDENT_TAB' => [
        'id' => true,
        'reference_id' => true,
        'forename' => true,
        'name' => true,
        'long_name' => true,
        'left_to_pay' => true,
        'amount_paid' => true,
        // optional elsewhere: birth_date, class_id, additional_payments_status, gender, entry_date, exit_date, description, second_ID, extern_key, email
    ],

    'INVOICE_TAB' => [
        'id' => true,
        'reference_number' => true,
        'reference' => true,
        'description' => true,
        'beneficiary' => true,
        'processing_date' => true,
        'amount_total' => true,
        'amount' => true,
        'student_id' => true,
        'transaction_type' => true,
        'legal_guardian_id' => true,
        'import_hash' => true,
    ],

    'MATCHING_HISTORY_TAB' => [
        'id' => true,
        'invoice_id' => true,
        'student_id' => true,
        'confidence_score' => true,
        'matched_by' => true,
        'is_confirmed' => true,
        'created_at' => true,
    ],

    'LEGAL_GUARDIAN_TAB' => [
        'id' => true,
        'first_name' => true,
        'last_name' => true,
    ],

    'NOTIFICATION_TAB' => [
        'id' => true,
        'student_id' => true,
        'invoice_reference' => true,
        'description' => true,
        'time_from' => true,
        'is_read' => true,
        'urgency' => true,
        'mail_status' => true,
        'created_at' => true,
    ],
];
