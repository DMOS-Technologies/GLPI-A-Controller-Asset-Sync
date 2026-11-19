<?php

namespace GlpiPlugin\Assetsynccontroller;

use GLPIKey;

class AgentApi
{
    private function __construct(private string $baseUrl, private array $sessionHeaders)
    {
    }

    public static function open(string $apiUrl, string $storedApplicationToken, string $storedUserToken): ?self
    {
        $glpiKey = new GLPIKey();
        $key = $glpiKey->get();
        if ($key === null)
        {
            return null;
        }

        // Decrypt only for this request. Never send ciphertext if GLPI's key is unavailable.
        $applicationToken = $glpiKey->decrypt($storedApplicationToken, $key);
        $userToken = $glpiKey->decrypt($storedUserToken, $key);
        $headerTokenPattern = '/\A[\x21-\x7e]{1,1024}\z/';
        if (!preg_match($headerTokenPattern, $applicationToken ?? '') || !preg_match($headerTokenPattern, $userToken ?? ''))
        {
            return null;
        }

        $baseUrl = rtrim($apiUrl, '/');
        $appHeader = 'App-Token: ' . $applicationToken;
        $sessionResponse = self::get($baseUrl . '/initSession', [
            $appHeader,
            'Authorization: user_token ' . $userToken,
        ]);
        if ($sessionResponse === null)
        {
            return null;
        }

        $session = json_decode($sessionResponse, true);
        $sessionToken = $session['session_token'] ?? null;
        // Validate the remote value before putting it into a request header.
        if (!is_string($sessionToken) || !preg_match($headerTokenPattern, $sessionToken))
        {
            return null;
        }

        return new self($baseUrl, [
            $appHeader,
            'Session-Token: ' . $sessionToken,
        ]);
    }

    public function close(): bool
    {
        return self::get($this->baseUrl . '/killSession', $this->sessionHeaders) !== null;
    }

    public function syncAsset(array $request): ?array
    {
        // The saved URL points at GLPI's REST API. The Agent action is on the same origin.
        $glpiUrl = preg_replace('~/(?:apirest\.php|api\.php/v1)$~i', '', $this->baseUrl, 1, $matched);
        if ($matched !== 1)
        {
            return null;
        }

        $response = self::get(
            $glpiUrl . '/plugins/assetsyncagent/front/sync.php',
            $this->sessionHeaders,
            1048576,
            $request,
        );
        $result = json_decode($response ?? '', true);
        return is_array($result) ? $result : null;
    }

    public function getFieldOptions(string $itemType): ?array
    {
        // Custom asset types have namespaces. Encode the entire itemtype as one path segment.
        // Raw options retain Fields ownership even when a custom dropdown points to a native table.
        $url = $this->baseUrl . '/listSearchOptions/' . rawurlencode($itemType) . '?raw=1';
        $json = self::get($url, $this->sessionHeaders, 1048576);
        $options = json_decode($json ?? '', true);
        return is_array($options) ? $options : null;
    }

    public function getRouteOptions(): ?array
    {
        $entitiesJson = self::get($this->baseUrl . '/getMyEntities?is_recursive=1', $this->sessionHeaders, 1048576);
        $configJson = self::get($this->baseUrl . '/getGlpiConfig', $this->sessionHeaders, 1048576);

        // Use only the signed-in profile's entities and the Agent's All Assets catalog.
        $entities = json_decode($entitiesJson ?? '', true)['myentities'] ?? null;
        $assetTypes = json_decode($configJson ?? '', true)['cfg_glpi']['asset_types'] ?? null;
        if (!is_array($entities) || !is_array($assetTypes))
        {
            return null;
        }

        $entityOptions = [];
        foreach ($entities as $entity)
        {
            $id = filter_var($entity['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $name = $entity['name'] ?? null;
            if ($id === false || !is_string($name) || trim($name) === '')
            {
                return null;
            }
            $entityOptions[$id] = $name;
        }

        $typeOptions = [];
        foreach ($assetTypes as $itemType)
        {
            if (!is_string($itemType) || !preg_match('/\A[A-Za-z_][A-Za-z_0-9]*(?:\\\\[A-Za-z_][A-Za-z_0-9]*)*\z/', $itemType))
            {
                return null;
            }
            // Keep the full REST identifier as the value, including a custom type namespace.
            $parts = explode('\\', $itemType);
            $shortName = end($parts);
            $typeOptions[$itemType] = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $shortName);
        }

        natcasesort($entityOptions);
        natcasesort($typeOptions);
        return ['entities' => $entityOptions, 'asset_types' => $typeOptions];
    }

    // Return the body for HTTP 200, or null for any failure. Remote errors may contain secrets.
    private static function get(string $url, array $headers, int $maxBytes = 65536, ?array $payload = null): ?string
    {
        $request = curl_init($url);
        if ($request === false)
        {
            return null;
        }

        $body = '';
        $headers[] = 'Accept: application/json';
        $protocolOption = defined('CURLOPT_PROTOCOLS_STR')
            ? [CURLOPT_PROTOCOLS_STR => 'HTTP,HTTPS']
            : [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
        $options = $protocolOption + [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_WRITEFUNCTION => static function ($request, string $chunk) use (&$body, $maxBytes): int
            {
                if (strlen($body) + strlen($chunk) > $maxBytes)
                {
                    return 0;
                }

                $body .= $chunk;
                return strlen($chunk);
            },
        ];

        if ($payload !== null)
        {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        if (!curl_setopt_array($request, $options) || curl_exec($request) === false)
        {
            return null;
        }
        if (curl_getinfo($request, CURLINFO_HTTP_CODE) === 200)
        {
            return $body;
        }
        if ($payload !== null)
        {
            // An HTTP error can report a field ID, but it can never acknowledge a write.
            $response = json_decode($body, true);
            $error = $response['error'] ?? null;
            if (is_string($error))
            {
                $failure = ['ok' => false, 'error' => $error];
                $fieldId = $response['field_id'] ?? null;
                if (($payload['include_error_details'] ?? false) === true && is_int($fieldId) && $fieldId > 0
                    && in_array($error, ['reference_unavailable', 'invalid_reference_value'], true))
                {
                    $failure['field_id'] = $fieldId;
                }
                return json_encode($failure, JSON_THROW_ON_ERROR);
            }
        }
        return null;
    }
}
