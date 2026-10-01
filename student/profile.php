<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_STUDENT]);

$studentId = $_SESSION['user_id'];
$errors = [];

$stmt = $pdo->prepare(
    "SELECT u.*, sp.address, sp.birthdate, sp.guardian_name, sp.guardian_contact, sp.year_level, sp.academic_standing, p.program_name
     FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.user_id
     LEFT JOIN programs p ON p.program_id = sp.program_id
     WHERE u.user_id = :id"
);
$stmt->execute([':id' => $studentId]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $guardianName = trim($_POST['guardian_name'] ?? '');
    $guardianContact = trim($_POST['guardian_contact'] ?? '');

    if (!isValidPhone($phone))
        $errors[] = 'Please enter a valid 11-digit mobile number (e.g. 09171234567).';
    if ($guardianContact !== '' && !isValidPhone($guardianContact))
        $errors[] = 'Please enter a valid 11-digit guardian contact number (e.g. 09171234567).';

    if (empty($errors)) {
        $pdo->prepare("UPDATE users SET phone = :phone WHERE user_id = :id")
            ->execute([':phone' => $phone, ':id' => $studentId]);
        $pdo->prepare(
            "UPDATE student_profiles SET address = :addr, guardian_name = :gn, guardian_contact = :gc WHERE user_id = :id"
        )->execute([':addr' => $address, ':gn' => $guardianName, ':gc' => $guardianContact, ':id' => $studentId]);

        setFlash('success', 'Profile updated.');
        redirect('/student/profile');
    }
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="card" style="max-width:600px;">
    <div class="grid grid-2" style="margin-bottom:16px;">
        <p><strong>Student ID:</strong> <?= e($user['student_id']) ?></p>
        <p><strong>Program:</strong> <?= e($user['program_name'] ?? '—') ?></p>
        <p><strong>Year Level:</strong> <?= (int) ($user['year_level'] ?? 0) ?></p>
        <p><strong>Academic Standing:</strong> <?= e($user['academic_standing'] ?? '—') ?></p>
    </div>
    <form method="POST" novalidate>
        <?= csrfField() ?>
        <div class="field"><label>Full name</label><input type="text"
                value="<?= e($user['first_name'] . ' ' . $user['last_name']) ?>" disabled></div>
        <div class="field"><label>Email</label><input type="email" value="<?= e($user['email']) ?>" disabled></div>
        <div class="field"><label for="phone">Phone</label><input type="tel" id="phone" name="phone"
                value="<?= e($user['phone']) ?>" required maxlength="11" inputmode="numeric" pattern="0[0-9]{10}"
                placeholder="09171234567"></div>
        <div class="field"><label for="address">Address</label><input type="text" id="address" name="address"
                value="<?= e($user['address']) ?>"></div>
        <div class="row-2">
            <div class="field"><label for="guardian_name">Guardian name</label><input type="text" id="guardian_name"
                    name="guardian_name" value="<?= e($user['guardian_name']) ?>"></div>
            <div class="field"><label for="guardian_contact">Guardian contact</label><input type="tel"
                    id="guardian_contact" name="guardian_contact" value="<?= e($user['guardian_contact']) ?>"
                    maxlength="11" inputmode="numeric" pattern="0[0-9]{10}" placeholder="09171234567"></div>
        </div>
        <button type="submit" class="btn btn-primary" style="width:auto; padding:11px 24px;">Save changes</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>