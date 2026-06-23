<?php

$api_key = 'AQ.Ab8RN6LaYhJ9B5YfvxcAZNuTUYBBJX-kQtIUkxTNM138ctkG2Q';

$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($api_key);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    die("Error fetching models. HTTP $http_code: $response");
}

$data = json_decode($response, true);

if (!isset($data['models']) || empty($data['models'])) {
    echo "No models found or invalid response.\n";
    print_r($data);
    exit;
}

echo "=== Available Gemini Models (v1beta) ===\n\n";

foreach ($data['models'] as $model) {
    $name = $model['name'] ?? 'N/A';
    $displayName = $model['displayName'] ?? '';
    $description = $model['description'] ?? '';
    $supportedMethods = $model['supportedGenerationMethods'] ?? [];
    $supportsGenerateContent = in_array('generateContent', $supportedMethods);

    echo "Model: $name\n";
    if ($displayName) echo "  Display Name: $displayName\n";
    if ($description) echo "  Description: $description\n";
    echo "  Supports generateContent: " . ($supportsGenerateContent ? 'YES' : 'NO') . "\n";
    echo "  Supported methods: " . implode(', ', $supportedMethods) . "\n";
    echo "----------------------------------------\n";
}
?>