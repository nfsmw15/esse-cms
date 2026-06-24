<?php

declare(strict_types=1);

// Minimaler HTTP-Client mit Cookie-Jar fuer die Integrationstests (kein Guzzle/Curl-Wrapper-Paket noetig).
final class Http
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'esse-test-cookies-');
    }

    public function __destruct()
    {
        @unlink($this->cookieJar);
    }

    /** @return array{status:int, headers:array<string,list<string>>, body:string} */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** @return array{status:int, headers:array<string,list<string>>, body:string} */
    public function post(string $path, array $data = [], array $headers = []): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return $this->request('POST', $path, http_build_query($data), $headers);
    }

    // POST mit JSON-Body + X-CSRF-Token-Header, analog zu webauthn.js' postJson() — fuer Routen
    // wie /admin/passkey/auth-verify, die den Body per file_get_contents('php://input') statt
    // ueber $_POST lesen.
    /** @return array{status:int, headers:array<string,list<string>>, body:string} */
    public function postJson(string $path, string $csrfToken, array $data = []): array
    {
        $headers = ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfToken];
        return $this->request('POST', $path, json_encode($data), $headers);
    }

    // POST mit multipart/form-data (Datei-Uploads). $files: Feldname => ['path' => ..., 'name' => ..., 'type' => ...]
    /** @return array{status:int, headers:array<string,list<string>>, body:string} */
    public function postMultipart(string $path, array $data, array $files, array $headers = []): array
    {
        $body = $data;
        foreach ($files as $field => $file) {
            $body[$field] = new \CURLFile($file['path'], $file['type'] ?? 'application/octet-stream', $file['name'] ?? basename($file['path']));
        }
        return $this->request('POST', $path, $body, $headers);
    }

    private function request(string $method, string $path, string|array|null $body, array $headers): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL-Fehler bei {$method} {$path}: {$error}");
        }

        $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        $headersOut = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headersOut[strtolower(trim($key))][] = trim($value);
            }
        }

        return ['status' => $status, 'headers' => $headersOut, 'body' => $responseBody];
    }
}
