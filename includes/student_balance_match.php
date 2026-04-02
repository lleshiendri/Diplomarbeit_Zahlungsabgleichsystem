<?php
/**
 * Apply one confirmed match share to STUDENT_TAB (school fee vs additional balance).
 *
 * Rules (keep in sync with sql/after_matching_history_insert.sql):
 * - If school year total exists AND share < (total_amount / 10) AND additional_payments_status > 0
 *   → reduce additional_payments_status by share (floored at 0).
 * - Else → reduce left_to_pay, increase amount_paid (same share).
 *
 * IMPORTANT: If MySQL has an AFTER INSERT trigger on MATCHING_HISTORY_TAB that also
 * updates STUDENT_TAB, DROP that trigger or every match will double-count.
 */
function applyStudentBalanceForConfirmedShare(mysqli $conn, int $studentId, float $shareAmount): bool
{
    if ($studentId <= 0 || $shareAmount <= 0.00001) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT COALESCE(st.additional_payments_status, 0) AS addl,
                sy.total_amount AS sy_total
           FROM STUDENT_TAB st
           LEFT JOIN SCHOOLYEAR_TAB sy ON sy.id = st.schoolyear_id
          WHERE st.id = ?
          LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $studentId);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return false;
    }

    $addl = (float)($row['addl'] ?? 0);
    $syTotal = isset($row['sy_total']) && $row['sy_total'] !== null ? (float)$row['sy_total'] : null;

    $useAdditional = ($syTotal !== null && $shareAmount < ($syTotal / 10) && $addl > 0);

    if ($useAdditional) {
        $upd = $conn->prepare(
            'UPDATE STUDENT_TAB
                SET additional_payments_status = GREATEST(0, COALESCE(additional_payments_status, 0) - ?)
              WHERE id = ?'
        );
        if (!$upd) {
            return false;
        }
        $upd->bind_param('di', $shareAmount, $studentId);
        $ok = $upd->execute();
        $upd->close();
        return (bool)$ok;
    }

    $upd = $conn->prepare(
        'UPDATE STUDENT_TAB
            SET left_to_pay = GREATEST(0, COALESCE(left_to_pay, 0) - ?),
                amount_paid = COALESCE(amount_paid, 0) + ?
          WHERE id = ?'
    );
    if (!$upd) {
        return false;
    }
    $upd->bind_param('ddi', $shareAmount, $shareAmount, $studentId);
    $ok = $upd->execute();
    $upd->close();
    return (bool)$ok;
}
