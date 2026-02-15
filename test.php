<?php
declare(strict_types=1);

/**
 * test.php (Graph helper) — CONFIG INLINE
 * Reused by notifications.php -> send_parent_email.php
 *
 * IMPORTANT:
 * - Put your teacher's values below ONCE.
 * - Do NOT retype them per click.
 * - Do NOT commit to GitHub.
 */

const GRAPH_CFG = [
    'tenant_id'     => '175ca58f-8107-41fa-b4a8-2aaf43790b87',
    'client_id'     => '66b0890a-fe1a-4630-97ae-fdb1e96c7c19',
    'client_secret' => 'yTO8Q~rivKPlzSc-jjDyoyqL1Te05YSkJJRm7bu_',
    'sender_user'   => 'EndLle19@htl-shkoder.com', // mailbox that sends the email
];

function graph_get_access_token(string &$errorMsg): ?string
{
    $errorMsg = '';

    $tenantId     = (string)(GRAPH_CFG['tenant_id'] ?? '');
    $clientId     = (string)(GRAPH_CFG['client_id'] ?? '');
    $clientSecret = (string)(GRAPH_CFG['client_secret'] ?? '');

    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        $errorMsg = "Missing Graph config in test.php (tenant_id/client_id/client_secret).";
        return null;
    }

    $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
    $tokenData = [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));

    $raw = curl_exec($ch);
    if ($raw === false) {
        $errorMsg = "Token curl error: " . curl_error($ch);
        curl_close($ch);
        return null;
    }

    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);
    if ($http < 200 || $http >= 300 || empty($json['access_token'])) {
        $errorMsg = "Token HTTP {$http}: {$raw}";
        return null;
    }

    return (string)$json['access_token'];
}

function graph_send_mail(array $toEmails, string $subject, string $content, string &$errorMsg): bool
{
    $errorMsg = '';

    $senderUserId = (string)(GRAPH_CFG['sender_user'] ?? '');
    if ($senderUserId === '') {
        $errorMsg = "Missing Graph config in test.php (sender_user).";
        return false;
    }

    $token = graph_get_access_token($errorMsg);
    if (!$token) return false;

    $toEmails = array_values(array_unique(array_filter($toEmails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    if (count($toEmails) === 0) {
        $errorMsg = "No valid recipient emails.";
        return false;
    }

    $sendMailUrl = "https://graph.microsoft.com/v1.0/users/" . rawurlencode($senderUserId) . "/sendMail";
    $toRecipients = array_map(fn($e) => ["emailAddress" => ["address" => $e]], $toEmails);

    $mailData = [
        "message" => [
            "subject" => $subject,
            "body" => [
                "contentType" => "Text",
                "content" => $content
            ],
            "toRecipients" => $toRecipients
        ],
        "saveToSentItems" => true
    ];

    $ch = curl_init($sendMailUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mailData));

    $raw = curl_exec($ch);
    if ($raw === false) {
        $errorMsg = "SendMail curl error: " . curl_error($ch);
        curl_close($ch);
        return false;
    }

    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 202) return true;

    $errorMsg = "SendMail HTTP {$http}: {$raw}";
    return false;
}