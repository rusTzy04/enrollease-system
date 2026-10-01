<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

if (isLoggedIn()) {
    redirect(dashboardUrlFor($_SESSION['role_name']));
}

$programs = $pdo->query("SELECT program_id, program_code, program_name FROM programs WHERE is_active = 1 ORDER BY program_name")->fetchAll();

$sem = $pdo->query(
    "SELECT s.semester_id, s.semester_name, s.enrollment_start, s.enrollment_end, ay.year_label
     FROM semesters s JOIN academic_years ay ON ay.academic_year_id = s.academic_year_id
     WHERE s.is_active = 1 LIMIT 1"
)->fetch();

$now = new DateTime();
$withinWindow = $sem && (!$sem['enrollment_start'] || $now >= new DateTime($sem['enrollment_start']))
                     && (!$sem['enrollment_end']   || $now <= new DateTime($sem['enrollment_end']));

$errors = [];
$old = [];
$submittedReference = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (!$sem) {
        $errors[] = 'Applications are not being accepted right now — there is no active enrollment term set up yet.';
    } elseif (!$withinWindow) {
        $errors[] = 'The application window for ' . e($sem['year_label'] . ' ' . $sem['semester_name']) . ' is currently closed.';
    } else {
        $old = $_POST;
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $programId = (int)($_POST['program_id'] ?? 0);
        $address   = trim($_POST['address'] ?? '');
        $birthdate = $_POST['birthdate'] ?? '';
        $guardianName = trim($_POST['guardian_name'] ?? '');
        $guardianContact = trim($_POST['guardian_contact'] ?? '');

        if ($firstName === '' || $lastName === '') $errors[] = 'First and last name are required.';
        if (!isValidEmail($email)) $errors[] = 'Please enter a valid email address.';
        if (!isValidPhone($phone)) $errors[] = 'Please enter a valid 11-digit mobile number (e.g. 09171234567).';
        if ($guardianContact !== '' && !isValidPhone($guardianContact)) $errors[] = 'Please enter a valid 11-digit guardian contact number (e.g. 09171234567).';
        if (!in_array($programId, array_column($programs, 'program_id'), true)) $errors[] = 'Please select a valid program.';
        if ($birthdate === '') $errors[] = 'Birthdate is required.';
        if ($birthdate !== '' && !DateTime::createFromFormat('Y-m-d', $birthdate)) $errors[] = 'Please enter a valid birthdate.';

        if (empty($errors)) {
            $pdo->beginTransaction();

            $reference = generateApplicationReference($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO enrollment_applications
                    (reference_no, first_name, last_name, email, phone, program_id, address, birthdate, guardian_name, guardian_contact, semester_id, status)
                 VALUES (:ref, :fn, :ln, :email, :phone, :pid, :addr, :bd, :gn, :gc, :sem, 'Requirements Pending')"
            );
            $stmt->execute([
                ':ref' => $reference, ':fn' => $firstName, ':ln' => $lastName, ':email' => $email, ':phone' => $phone,
                ':pid' => $programId, ':addr' => $address ?: null, ':bd' => $birthdate ?: null,
                ':gn' => $guardianName ?: null, ':gc' => $guardianContact ?: null, ':sem' => $sem['semester_id'],
            ]);
            $applicationId = (int)$pdo->lastInsertId();

            $reqTypes = $pdo->query("SELECT requirement_type_id FROM requirement_types WHERE is_required = 1")->fetchAll();
            $insReq = $pdo->prepare("INSERT INTO application_requirements (application_id, requirement_type_id, status) VALUES (:aid, :rid, 'Missing')");
            foreach ($reqTypes as $rt) {
                $insReq->execute([':aid' => $applicationId, ':rid' => $rt['requirement_type_id']]);
            }

            $pdo->commit();
            logActivity($pdo, null, 'application_submitted', "Reference {$reference} ({$email})");
            sendApplicationReceivedEmail($pdo, $email, $firstName, $reference);

            $submittedReference = $reference;
        }
    }
}

$requirementsList = $pdo->query("SELECT requirement_name FROM requirement_types WHERE is_required = 1 ORDER BY requirement_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Apply for Enrollment · <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/public-nav.php'; ?>

<div class="auth-wrap" style="align-items:flex-start; padding-top:48px;">
<div class="auth-card" style="max-width:560px;">

<?php if ($submittedReference): ?>
    <div class="auth-brand"><div class="mark">E</div><div class="name"><?= e(SITE_NAME) ?></div></div>
    <h1>Application received!</h1>
    <div class="alert alert-success">Your reference number is <strong><?= e($submittedReference) ?></strong>. Please write this down or take a screenshot.</div>
    <p><strong>Next steps:</strong></p>
    <ol style="color:var(--ink-soft); padding-left:20px;">
        <li>Bring these requirements to the Registrar's office (quote your reference number):
            <ul style="margin-top:6px;">
                <?php foreach ($requirementsList as $r): ?><li><?= e($r['requirement_name']) ?></li><?php endforeach; ?>
            </ul>
        </li>
        <li>Pay the ₱3,000 minimum enrollment fee at the Cashier.</li>
        <li>Once both are verified, the Registrar will finalize your enrollment and give you your account login.</li>
    </ol>
    <a href="<?= BASE_URL ?>/" class="btn btn-outline" style="margin-top:10px;">Back to home</a>

<?php else: ?>
    <div class="auth-brand"><div class="mark">E</div><div class="name"><?= e(SITE_NAME) ?></div></div>
    <h1>Apply for Enrollment</h1>
    <div class="auth-sub">
        <?php if ($sem): ?>
            Applying for <strong><?= e($sem['year_label'] . ' — ' . $sem['semester_name']) ?></strong>.
        <?php else: ?>
            Enrollment applications are not currently open.
        <?php endif; ?>
    </div>

    <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

    <?php if ($sem && $withinWindow): ?>
    <p class="helper-text" style="margin:-4px 0 14px;"><span class="required-mark">*</span> Required field</p>
    <form method="POST" novalidate autocomplete="off">
        <?= csrfField() ?>
        <div class="row-2">
            <div class="field"><label for="first_name">First name <span class="required-mark">*</span></label><input type="text" id="first_name" name="first_name" required value="<?= e($old['first_name'] ?? '') ?>"></div>
            <div class="field"><label for="last_name">Last name <span class="required-mark">*</span></label><input type="text" id="last_name" name="last_name" required value="<?= e($old['last_name'] ?? '') ?>"></div>
        </div>
        <div class="field"><label for="email">Email address <span class="required-mark">*</span></label><input type="email" id="email" name="email" required value="<?= e($old['email'] ?? '') ?>"></div>
        <div class="field"><label for="phone">Mobile number <span class="required-mark">*</span></label><input type="tel" id="phone" name="phone" required maxlength="11" inputmode="numeric" pattern="0[0-9]{10}" placeholder="09171234567" value="<?= e($old['phone'] ?? '') ?>"><div class="hint">11 digits, starting with 0 (e.g. 09171234567)</div><div class="field-error" id="err-phone"></div></div>
        <div class="field"><label for="program_id">Program <span class="required-mark">*</span></label>
            <select id="program_id" name="program_id" required>
                <option value="">Select a program</option>
                <?php foreach ($programs as $p): ?>
                    <option value="<?= (int)$p['program_id'] ?>" <?= (int)($old['program_id'] ?? 0) === (int)$p['program_id'] ? 'selected' : '' ?>><?= e($p['program_code'] . ' — ' . $p['program_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label for="address">Address</label><input type="text" id="address" name="address" value="<?= e($old['address'] ?? '') ?>"></div>
        <div class="row-2">
            <div class="field"><label for="birthdate">Birthdate<span class="required-mark">*</span></label><input type="date" id="birthdate" name="birthdate" required value="<?= e($old['birthdate'] ?? '') ?>"></div>
            <div class="field"><label for="guardian_name">Guardian name</label><input type="text" id="guardian_name" name="guardian_name" value="<?= e($old['guardian_name'] ?? '') ?>"></div>
        </div>
        <div class="field"><label for="guardian_contact">Guardian contact number</label><input type="tel" id="guardian_contact" name="guardian_contact" maxlength="11" inputmode="numeric" pattern="0[0-9]{10}" placeholder="09171234567" value="<?= e($old['guardian_contact'] ?? '') ?>"><div class="field-error" id="err-guardian_contact"></div></div>
        <button type="submit" class="btn btn-primary">Submit Application</button>
    </form>
    <?php endif; ?>

    <div class="auth-foot">Already have an account? <a href="<?= BASE_URL ?>/auth/login">Log in</a></div>
<?php endif; ?>

</div>
</div>
<?php require_once __DIR__ . '/includes/public-footer.php'; ?>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
<script>
    attachPhoneLiveValidation('phone', 'err-phone');
    attachPhoneLiveValidation('guardian_contact', 'err-guardian_contact');
</script>
</body>
</html>