<?php
// SMTP settings come from environment variables (set them on Railway).
// Never commit a real password here.
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'no-reply@enrollease.edu.ph');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'EnrollEase System');

// Railway blocks outbound SMTP on its cheaper plans. When BREVO_API_KEY is set,
// mail goes through Brevo's HTTPS API instead (SMTP_FROM_EMAIL must be a sender
// verified in Brevo).
define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: '');
