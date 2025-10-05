<?php
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

require_once '../db.php';
require_once __DIR__ . '/bootstrap.php';

date_default_timezone_set('Asia/Tashkent');

try {
    ensure_teacher_tables($conn);
} catch (Throwable $exception) {
    teacher_flash("Bazani tayyorlashda xatolik: " . $exception->getMessage(), 'danger', $redirectPath);
}

$action = $_POST['action'] ?? '';
$redirectQuery = trim($_POST['redirect_query'] ?? '');
$redirectPath = 'index.php' . ($redirectQuery ? '?' . $redirectQuery : '');

function teacher_flash(string $message, string $type, string $redirectPath): void
{
    $_SESSION['teachers_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
    header('Location: ' . $redirectPath);
    exit();
}

if (!in_array($action, ['create', 'update', 'delete'], true)) {
    teacher_flash("Noto'g'ri amal tanlandi.", 'danger', $redirectPath);
}

$payoutId = isset($_POST['payout_id']) ? (int) $_POST['payout_id'] : 0;

function ensure_teacher_category(mysqli $conn): ?int
{
    $categoryName = "O'qituvchilar oyligi";
    $stmt = $conn->prepare('SELECT id FROM expense_categories WHERE name = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $categoryName);
        if ($stmt->execute()) {
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($result) {
                return (int) $result['id'];
            }
        } else {
            $stmt->close();
            return null;
        }
        $stmt->close();
    }

    $insert = $conn->prepare('INSERT INTO expense_categories (name) VALUES (?)');
    if (!$insert) {
        return null;
    }
    $insert->bind_param('s', $categoryName);
    if ($insert->execute()) {
        $newId = (int) $insert->insert_id;
        $insert->close();
        return $newId;
    }
    $insert->close();
    return null;
}

if ($action === 'delete') {
    if ($payoutId <= 0) {
        teacher_flash("Maosh to'lovi topilmadi.", 'danger', $redirectPath);
    }
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT transaction_id FROM teacher_payouts WHERE id = ?');
    if (!$stmt) {
        $conn->rollback();
        teacher_flash("Maosh to'lovini o'chirishda xatolik: " . $conn->error, 'danger', $redirectPath);
    }
    $stmt->bind_param('i', $payoutId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $delete = $conn->prepare('DELETE FROM teacher_payouts WHERE id = ?');
    if (!$delete) {
        $conn->rollback();
        teacher_flash("Maosh to'lovini o'chirishda xatolik: " . $conn->error, 'danger', $redirectPath);
    }
    $delete->bind_param('i', $payoutId);
    if (!$delete->execute()) {
        $error = $delete->error;
        $delete->close();
        $conn->rollback();
        teacher_flash("Maosh to'lovini o'chirishda xatolik: " . $error, 'danger', $redirectPath);
    }
    $delete->close();

    if ($existing && (int) $existing['transaction_id'] > 0) {
        $transactionId = (int) $existing['transaction_id'];
        $conn->query('DELETE FROM transaction_categories WHERE transaction_id = ' . $transactionId);
        $conn->query('DELETE FROM transactions WHERE id = ' . $transactionId);
    }

    $conn->commit();
    teacher_flash("Maosh to'lovi o'chirildi.", 'success', $redirectPath);
}

$teacherId = isset($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : 0;
$paidAt = $_POST['paid_at'] ?? '';
$amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
$paymentMethod = $_POST['payment_method'] ?? 'cash';
$note = trim($_POST['note'] ?? '');

if ($teacherId <= 0) {
    teacher_flash("O'qituvchi tanlanmadi.", 'danger', $redirectPath);
}

$dateValid = DateTime::createFromFormat('Y-m-d', $paidAt);
if (!$dateValid || $dateValid->format('Y-m-d') !== $paidAt) {
    teacher_flash("To'lov sanasi noto'g'ri.", 'danger', $redirectPath);
}

if ($amount <= 0) {
    teacher_flash("To'lov summasi 0 dan katta bo'lishi kerak.", 'danger', $redirectPath);
}

if (!in_array($paymentMethod, ['cash', 'click'], true)) {
    teacher_flash("To'lov usuli noto'g'ri tanlandi.", 'danger', $redirectPath);
}

$teacherStmt = $conn->prepare('SELECT name FROM teacher_profiles WHERE id = ?');
if (!$teacherStmt) {
    teacher_flash("O'qituvchini topishda xatolik: " . $conn->error, 'danger', $redirectPath);
}
$teacherStmt->bind_param('i', $teacherId);
$teacherStmt->execute();
$teacherRow = $teacherStmt->get_result()->fetch_assoc();
$teacherStmt->close();

if (!$teacherRow) {
    teacher_flash("O'qituvchi topilmadi.", 'danger', $redirectPath);
}

$comment = "O'qituvchi " . $teacherRow['name'] . " oyligi";
if ($note !== '') {
    $comment .= ' - ' . $note;
}

$cashFlag = $paymentMethod === 'cash' ? 1 : 0;
$clickFlag = $paymentMethod === 'click' ? 1 : 0;
$cashIn = 0;
$cashOut = 1;
$xarajat = 1;

if ($action === 'create') {
    $conn->begin_transaction();

    $transactionStmt = $conn->prepare('INSERT INTO transactions (payment, cash, click, cash_in, cash_out, xarajat, comment, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$transactionStmt) {
        $conn->rollback();
        teacher_flash("Tranzaksiya yaratishda xatolik: " . $conn->error, 'danger', $redirectPath);
    }
    $transactionStmt->bind_param('diiiiiss', $amount, $cashFlag, $clickFlag, $cashIn, $cashOut, $xarajat, $comment, $paidAt);
    if (!$transactionStmt->execute()) {
        $error = $transactionStmt->error;
        $transactionStmt->close();
        $conn->rollback();
        teacher_flash("Tranzaksiyani saqlashda xatolik: " . $error, 'danger', $redirectPath);
    }
    $transactionId = (int) $transactionStmt->insert_id;
    $transactionStmt->close();

    $categoryId = ensure_teacher_category($conn);
    if ($categoryId) {
        $categoryStmt = $conn->prepare('REPLACE INTO transaction_categories (transaction_id, category_id) VALUES (?, ?)');
        if ($categoryStmt) {
            $categoryStmt->bind_param('ii', $transactionId, $categoryId);
            $categoryStmt->execute();
            $categoryStmt->close();
        }
    }

    $payoutStmt = $conn->prepare('INSERT INTO teacher_payouts (teacher_id, paid_at, amount, payment_method, note, transaction_id) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$payoutStmt) {
        $conn->rollback();
        teacher_flash("Maosh to'lovini saqlashda xatolik: " . $conn->error, 'danger', $redirectPath);
    }
    $payoutStmt->bind_param('isdssi', $teacherId, $paidAt, $amount, $paymentMethod, $note, $transactionId);
    if (!$payoutStmt->execute()) {
        $error = $payoutStmt->error;
        $payoutStmt->close();
        $conn->rollback();
        teacher_flash("Maosh to'lovini saqlashda xatolik: " . $error, 'danger', $redirectPath);
    }
    $payoutStmt->close();

    $conn->commit();
    teacher_flash("Maosh to'lovi qo'shildi.", 'success', $redirectPath);
}

if ($payoutId <= 0) {
    teacher_flash("Maosh to'lovi topilmadi.", 'danger', $redirectPath);
}

$conn->begin_transaction();

$existingStmt = $conn->prepare('SELECT transaction_id FROM teacher_payouts WHERE id = ?');
if (!$existingStmt) {
    $conn->rollback();
    teacher_flash("Maosh to'lovini yangilashda xatolik: " . $conn->error, 'danger', $redirectPath);
}
$existingStmt->bind_param('i', $payoutId);
$existingStmt->execute();
$existingData = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

$transactionId = $existingData ? (int) ($existingData['transaction_id'] ?? 0) : 0;

if ($transactionId > 0) {
    $updateTransaction = $conn->prepare('UPDATE transactions SET payment = ?, cash = ?, click = ?, cash_in = ?, cash_out = ?, xarajat = ?, comment = ?, date = ? WHERE id = ?');
    if ($updateTransaction) {
        $updateTransaction->bind_param('diiiiissi', $amount, $cashFlag, $clickFlag, $cashIn, $cashOut, $xarajat, $comment, $paidAt, $transactionId);
        $updateTransaction->execute();
        $updateTransaction->close();
    }

    $categoryId = ensure_teacher_category($conn);
    if ($categoryId) {
        $categoryStmt = $conn->prepare('REPLACE INTO transaction_categories (transaction_id, category_id) VALUES (?, ?)');
        if ($categoryStmt) {
            $categoryStmt->bind_param('ii', $transactionId, $categoryId);
            $categoryStmt->execute();
            $categoryStmt->close();
        }
    }
} else {
    $transactionStmt = $conn->prepare('INSERT INTO transactions (payment, cash, click, cash_in, cash_out, xarajat, comment, date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if ($transactionStmt) {
        $transactionStmt->bind_param('diiiiiss', $amount, $cashFlag, $clickFlag, $cashIn, $cashOut, $xarajat, $comment, $paidAt);
        if ($transactionStmt->execute()) {
            $transactionId = (int) $transactionStmt->insert_id;
            $categoryId = ensure_teacher_category($conn);
            if ($categoryId) {
                $categoryStmt = $conn->prepare('REPLACE INTO transaction_categories (transaction_id, category_id) VALUES (?, ?)');
                if ($categoryStmt) {
                    $categoryStmt->bind_param('ii', $transactionId, $categoryId);
                    $categoryStmt->execute();
                    $categoryStmt->close();
                }
            }
        }
        $transactionStmt->close();
    }
}

$updatePayout = $conn->prepare('UPDATE teacher_payouts SET teacher_id = ?, paid_at = ?, amount = ?, payment_method = ?, note = ?, transaction_id = ?, updated_at = NOW() WHERE id = ?');
if (!$updatePayout) {
    $conn->rollback();
    teacher_flash("Maosh to'lovini yangilashda xatolik: " . $conn->error, 'danger', $redirectPath);
}
$updatePayout->bind_param('isdssii', $teacherId, $paidAt, $amount, $paymentMethod, $note, $transactionId, $payoutId);
if (!$updatePayout->execute()) {
    $error = $updatePayout->error;
    $updatePayout->close();
    $conn->rollback();
    teacher_flash("Maosh to'lovini yangilashda xatolik: " . $error, 'danger', $redirectPath);
}
$updatePayout->close();

$conn->commit();
teacher_flash("Maosh to'lovi yangilandi.", 'success', $redirectPath);
