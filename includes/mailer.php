<?php
require_once __DIR__ . '/../config/mail.php';

function sendEmail(PDO $pdo, string $toEmail, string $subject, string $bodyHtml): bool
{
    if (BREVO_API_KEY !== '') {
        $ok = sendViaBrevo($toEmail, $subject, $bodyHtml);
        logEmailAttempt($pdo, $toEmail, $subject, $bodyHtml, $ok ? 'Sent' : 'Failed');
        return $ok;
    }

    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($vendorAutoload)) {
        error_log("PHPMailer not installed — email to {$toEmail} was not sent: {$subject}");
        logEmailAttempt($pdo, $toEmail, $subject, $bodyHtml, 'Failed');
        return false;
    }

    require_once $vendorAutoload;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $bodyHtml;

        $mail->send();
        logEmailAttempt($pdo, $toEmail, $subject, $bodyHtml, 'Sent');
        return true;
    } catch (Exception $e) {
        error_log('Email send failed: ' . $mail->ErrorInfo);
        logEmailAttempt($pdo, $toEmail, $subject, $bodyHtml, 'Failed');
        return false;
    }
}

/** Sends through Brevo's HTTPS API (port 443, which Railway does not block). */
function sendViaBrevo(string $toEmail, string $subject, string $bodyHtml): bool
{
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['api-key: ' . BREVO_API_KEY, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'sender' => ['name' => SMTP_FROM_NAME, 'email' => SMTP_FROM_EMAIL],
            'to' => [['email' => $toEmail]],
            'subject' => $subject,
            'htmlContent' => $bodyHtml,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        return true;
    }
    error_log('Brevo send failed (' . $status . '): ' . ($response === false ? $curlError : $response));
    return false;
}

function logEmailAttempt(PDO $pdo, string $to, string $subject, string $body, string $status): void
{
    $pdo->prepare("INSERT INTO email_log (to_email, subject, body, status) VALUES (:to, :subj, :body, :status)")
        ->execute([':to' => $to, ':subj' => $subject, ':body' => $body, ':status' => $status]);
}

/** Convenience wrappers matching the SMTP notification types from the feature list */
function sendRegistrationConfirmationEmail(PDO $pdo, string $email, string $firstName, string $studentId): void
{
    sendEmail(
        $pdo,
        $email,
        'Welcome to ' . SITE_NAME,
        "<p>Hi, {$firstName}!</p><p>Your account has been created. Your Student ID is <strong>{$studentId}</strong>.</p>"
    );
}

function sendEnrollmentSubmittedEmail(PDO $pdo, string $email, string $firstName): void
{
    sendEmail(
        $pdo,
        $email,
        'Enrollment Application Received',
        "<p>Hi, {$firstName}!</p><p>We've received your enrollment application. Please submit your physical requirements to the Registrar's office.</p>"
    );
}

function sendEnrollmentApprovedEmail(PDO $pdo, string $email, string $firstName): void
{
    sendEmail(
        $pdo,
        $email,
        'Enrollment Approved',
        "<p>Hi, {$firstName}!</p><p>Congratulations, your enrollment has been approved! You can now view and print your Registration Form.</p>"
    );
}

function sendEnrollmentRejectedEmail(PDO $pdo, string $email, string $firstName, string $reason): void
{
    sendEmail(
        $pdo,
        $email,
        'Enrollment Application Update',
        "<p>Hi, {$firstName}!</p><p>Unfortunately your enrollment application was not approved. Reason: {$reason}</p>"
    );
}

function sendPasswordResetEmail(PDO $pdo, string $email, string $firstName, string $resetLink): void
{
    sendEmail(
        $pdo,
        $email,
        'Reset Your Password',
        "<p>Hi, {$firstName}!</p><p>Click the link below to reset your password. This link expires in 1 hour.</p><p><a href=\"{$resetLink}\">{$resetLink}</a></p>"
    );
}

function sendApplicationReceivedEmail(PDO $pdo, string $email, string $firstName, string $referenceNo): void
{
    sendEmail(
        $pdo,
        $email,
        'Enrollment Application Received',
        "<p>Hi, {$firstName}!</p><p>Thank you for applying. Your application reference number is <strong>{$referenceNo}</strong>.</p>
         <p>Please bring your physical requirements to the Registrar's office, then proceed to the Cashier to pay the ₱3,000 minimum fee.
         Once both are verified, the Registrar will finalize your enrollment and provide your account login details.</p>"
    );
}


function sendApplicationApprovedEmail(PDO $pdo, string $email, string $firstName, string $studentId, string $tempPassword): void
{
    sendEmail(
        $pdo,
        $email,
        'You Are Officially Enrolled!',
        "<p>Hi, {$firstName}!</p><p>Congratulations, your enrollment has been finalized! Here are your account details:</p>
         <p><strong>Student ID:</strong> {$studentId}<br>
            <strong>Login email:</strong> {$email}<br>
            <strong>Temporary password:</strong> {$tempPassword}</p>
         <p>Please log in and change your password as soon as possible under Account &rarr; Change Password.
         For your security, do not share this email or forward it to anyone else.</p>"
    );
}