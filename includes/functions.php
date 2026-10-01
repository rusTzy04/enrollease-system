<?php
/**
 * Redirect to a clean, extensionless URL (BASE_URL + path), then stop execution.
 */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

/** Escape output for safe HTML display (defends against stored/reflected XSS) */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Basic required-field + format validators (server-side; pair with client-side checks in main.js) */
function isValidEmail(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function isStrongPassword(string $password): bool
{
    // At least 8 chars, one letter, one number
    return strlen($password) >= 8 && preg_match('/[A-Za-z]/', $password) && preg_match('/[0-9]/', $password);
}

function isValidPhone(string $phone): bool
{
    // Philippine mobile format: exactly 11 digits, starting with 0 (e.g. 09171234567)
    return (bool) preg_match('/^0\d{10}$/', $phone);
}

/** Record an entry in the activity_logs / audit trail table */
function logActivity(PDO $pdo, ?int $userId, string $action, ?string $details): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (:uid, :action, :details, :ip)"
        );
        $stmt->execute([
            ':uid' => $userId,
            ':action' => $action,
            ':details' => $details,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Failed to write activity log: ' . $e->getMessage());
    }
}

/** Create an in-system notification for a user */
function notify(PDO $pdo, int $userId, string $title, string $message): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, message) VALUES (:uid, :title, :msg)"
    );
    $stmt->execute([':uid' => $userId, ':title' => $title, ':msg' => $message]);
}

/** Generate a unique student ID like 2026-00001 */
function generateStudentId(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM users WHERE student_id LIKE :pattern");
    $stmt->execute([':pattern' => $year . '-%']);
    $count = (int) $stmt->fetch()['cnt'] + 1;
    return $year . '-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
}

/** Generate a unique receipt number */
function generateReceiptNumber(PDO $pdo): string
{
    do {
        $candidate = 'OR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM payments WHERE receipt_number = :r");
        $stmt->execute([':r' => $candidate]);
    } while ((int) $stmt->fetch()['cnt'] > 0);
    return $candidate;
}

/** Generate a unique application reference number like APP-2026-00001 (for pre-account applicants) */
function generateApplicationReference(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM enrollment_applications WHERE reference_no LIKE :pattern");
    $stmt->execute([':pattern' => "APP-{$year}-%"]);
    $count = (int) $stmt->fetch()['cnt'] + 1;
    return "APP-{$year}-" . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
}

/** Flash message helper (stored in session, shown once) */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}