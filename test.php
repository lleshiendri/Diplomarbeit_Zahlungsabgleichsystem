<?php
declare(strict_types=1);

/**
 * test.php (Graph helper) — BAT LOGIC (graph_env.php)
 *
 * Flow:
 * - You run make_graph_env.bat on your PC
 * - It generates graph_env.php
 * - You upload graph_env.php next to this test.php
 * - This file loads credentials from graph_env.php
 */

const GRAPH_CFG = [
    'tenant_id'     => '175ca58f-8107-41fa-b4a8-2aaf43790b87',
    'client_id'     => '66b0890a-fe1a-4630-97ae-fdb1e96c7c19',
    'client_secret' => '...', // placeholder (should be overridden by graph_env.php)
    'sender_user'   => 'EndLle19@htl-shkoder.com',
];

function graph_cfg_from_env_file_if_available(string &$errorMsg = ''): array
{
    $errorMsg = '';
    $envFile = __DIR__ . '/graph_env.php';

    if (!is_file($envFile) || !is_readable($envFile)) {
        // env file missing → fallback to inline
        return GRAPH_CFG;
    }

    $cfg = require $envFile;
    if (!is_array($cfg)) {
        $errorMsg = "graph_env.php did not return an array.";
        return GRAPH_CFG;
    }

    $out = [
        'tenant_id'     => trim((string)($cfg['tenant_id'] ?? '')),
        'client_id'     => trim((string)($cfg['client_id'] ?? '')),
        'client_secret' => trim((string)($cfg['client_secret'] ?? '')),
        'sender_user'   => trim((string)($cfg['sender_user'] ?? '')),
    ];

    // If env file incomplete, fallback
    foreach (['tenant_id','client_id','client_secret','sender_user'] as $k) {
        if ($out[$k] === '') {
            $errorMsg = "graph_env.php missing key: {$k}";
            return GRAPH_CFG;
        }
    }

    return $out;
}

function graph_get_access_token(string &$errorMsg): ?string
{
    $errorMsg = '';

    $cfg = graph_cfg_from_env_file_if_available($errorMsg);

    $tenantId     = (string)($cfg['tenant_id'] ?? '');
    $clientId     = (string)($cfg['client_id'] ?? '');
    $clientSecret = (string)($cfg['client_secret'] ?? '');

    if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $clientSecret === '...') {
        $errorMsg = "Missing/placeholder Graph config. Ensure graph_env.php contains the real client_secret VALUE.";
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

    $cfg = graph_cfg_from_env_file_if_available($errorMsg);

    $senderUserId = (string)($cfg['sender_user'] ?? '');
    if ($senderUserId === '') {
        $errorMsg = "Missing Graph config (sender_user).";
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
