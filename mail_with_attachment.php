<?php
declare(strict_types=1);

require_once __DIR__ . '/test.php';

/**
 * Send an email with a single file attachment using Microsoft Graph.
 *
 * Returns ['ok' => bool, 'error' => ?string]
 */
function send_mail_with_attachment(
    string $toEmail,
    string $toName,
    string $subject,
    string $body,
    string $attachmentPathFs,
    string $attachmentFilename
): array {
    $result = ['ok' => false, 'error' => null];

    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $result['error'] = 'Invalid recipient email.';
        return $result;
    }

    if ($attachmentPathFs === '' || !is_file($attachmentPathFs) || !is_readable($attachmentPathFs)) {
        // Do not expose filesystem paths in the error message.
        $result['error'] = 'Attachment file not found or not readable.';
        return $result;
    }

    $maxSizeBytes = 10 * 1024 * 1024; // 10 MB
    $size = @filesize($attachmentPathFs);
    if ($size === false || $size <= 0) {
        $result['error'] = 'Unable to determine attachment size.';
        return $result;
    }
    if ($size > $maxSizeBytes) {
        $result['error'] = 'Attachment is too large (max 10MB).';
        return $result;
    }

    $fileContent = @file_get_contents($attachmentPathFs);
    if ($fileContent === false) {
        $result['error'] = 'Failed to read attachment file.';
        return $result;
    }

    $base64Content = base64_encode($fileContent);

    $cfgError = '';
    $cfg = graph_cfg_from_env_file_if_available($cfgError);
    $senderUserId = (string)($cfg['sender_user'] ?? '');
    if ($senderUserId === '') {
        $result['error'] = $cfgError !== '' ? $cfgError : 'Missing Graph sender configuration.';
        return $result;
    }

    $tokenError = '';
    $token = graph_get_access_token($tokenError);
    if (!$token) {
        $result['error'] = $tokenError !== '' ? $tokenError : 'Failed to obtain Graph access token.';
        return $result;
    }

    $displayName = trim($toName) !== '' ? trim($toName) : $toEmail;
    $attachmentFilename = $attachmentFilename !== '' ? $attachmentFilename : basename($attachmentPathFs);

    $sendMailUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($senderUserId) . '/sendMail';

    $mailData = [
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => 'Text',
                'content' => $body,
            ],
            'toRecipients' => [
                [
                    'emailAddress' => [
                        'address' => $toEmail,
                        'name' => $displayName,
                    ],
                ],
            ],
            'attachments' => [
                [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => $attachmentFilename,
                    'contentType' => 'application/pdf',
                    'contentBytes' => $base64Content,
                ],
            ],
        ],
        'saveToSentItems' => true,
    ];

    $ch = curl_init($sendMailUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mailData));

    $raw = curl_exec($ch);
    if ($raw === false) {
        $result['error'] = 'SendMail curl error: ' . curl_error($ch);
        curl_close($ch);
        return $result;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 202) {
        $result['ok'] = true;
        return $result;
    }

    // Graph error payload is safe to show; it does not contain local filesystem paths.
    $result['error'] = 'SendMail HTTP ' . $httpCode . ': ' . $raw;
    return $result;
}

