<?php
// Core booking helpers and utilities
// This file contains small helper functions used across booking pages.
// Hardened and implemented missing helpers.

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ($needle === '') return true;
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

function normalizeSubject(string $s): string {
    // Normalize subject text for matching/searching:
    // lowercase, remove diacritics, replace non-alphanumeric with empty, collapse spaces
    $s = mb_strtolower($s, 'UTF-8');
    // Transliterate to ASCII where possible
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    // Remove anything that is not a-z0-9 and collapse sequences
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return trim($s);
}

function isPathInUploads(?string $path): bool {
    if (empty($path)) return false;
    // Normalize and ensure path is inside application uploads directory
    $uploads = realpath(__DIR__ . '/uploads');
    if (!$uploads) return false;
    $real = realpath(__DIR__ . '/' . ltrim($path, '/\\'));
    if (!$real) return false;
    return strpos($real, $uploads) === 0;
}

function jsonSafe($data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function sendEmail(string $to, string $name, string $subjectLine, string $title, string $contentHtml, ?string $attachmentPath = null): array {
    // Returns ['ok'=>bool,'error'=>string]
    // Prefer PHPMailer if available via composer; fallback to mail()
    $result = ['ok' => false, 'error' => 'Unknown error'];

    // Sanitize recipient
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email'];
    }

    // PHPMailer if installed
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        try {
            require __DIR__ . '/vendor/autoload.php';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            // Basic config; recommend replacing with your SMTP config
            $mail->isMail(); // fallback to PHP mail()
            $mail->setFrom('noreply@invento.uz', 'Invento School');
            $mail->addAddress($to, $name);
            $mail->Subject = $subjectLine;
            $mail->isHTML(true);
            $mail->Body = '<h2>' . htmlspecialchars($title) . '</h2>' . $contentHtml;
            $mail->AltBody = strip_tags($contentHtml);
            if ($attachmentPath && isPathInUploads($attachmentPath) && file_exists(__DIR__ . '/' . ltrim($attachmentPath, '/\\'))) {
                $mail->addAttachment(__DIR__ . '/' . ltrim($attachmentPath, '/\\'));
            }
            $mail->send();
            return ['ok' => true, 'error' => ''];
        } catch (Exception $e) {
            $result['ok'] = false;
            $result['error'] = 'PHPMailer: ' . $e->getMessage();
            // fall back to mail()
        }
    }

    // Fallback to PHP mail()
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Invento School <noreply@invento.uz>\r\n";
    $body = '<html><body>';
    $body .= '<h2>' . htmlspecialchars($title) . '</h2>';
    $body .= $contentHtml;
    $body .= '</body></html>';

    if ($attachmentPath && isPathInUploads($attachmentPath) && file_exists(__DIR__ . '/' . ltrim($attachmentPath, '/\\'))) {
        // Simple mail() does not support attachments easily; skip attachments in fallback
        // Recommend using PHPMailer (composer) for attachments
    }

    $ok = @mail($to, $subjectLine, $body, $headers);
    if ($ok) return ['ok' => true, 'error' => ''];
    return ['ok' => false, 'error' => 'mail() failed'];
}
