<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Env;
use App\Support\SecretStore;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class TokenService
{
    private Client $http;

    public function __construct(?Client $client = null)
    {
        if ($client) {
            $this->http = $client;
        } else {
            $options = [
                'base_uri' => 'https://graph.facebook.com/',
                'timeout' => 10.0,
            ];
            $verifyEnv = Env::get('HTTP_VERIFY_SSL', '1');
            $caPath = Env::get('CA_BUNDLE_PATH');
            if ($caPath) {
                $options['verify'] = $caPath;
            } elseif ($verifyEnv === '0') {
                $options['verify'] = false; // local-only
            } elseif (Env::isLocal()) {
                $options['verify'] = false; // auto-relax on localhost
            }
            $this->http = new Client($options);
        }
    }

    public function debugToken(string $token): array
    {
        $version = Env::get('GRAPH_API_VERSION', 'v24.0');
        $appId = Env::get('META_APP_ID');
        $appSecret = Env::get('META_APP_SECRET');
        if (!$appId || !$appSecret) {
            throw new \RuntimeException('Missing META_APP_ID or META_APP_SECRET');
        }
        $appAccessToken = $appId . '|' . $appSecret;
        try {
            $resp = $this->http->get(sprintf('%s/debug_token', $version), [
                'query' => [
                    'input_token' => $token,
                    'access_token' => $appAccessToken,
                ],
            ]);
        } catch (RequestException $e) {
            $resp = $e->getResponse();
            $detail = $resp ? (string) $resp->getBody() : $e->getMessage();
            throw new \RuntimeException('HTTP error debugging token: ' . $detail, 0);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('HTTP error debugging token: ' . $e->getMessage(), 0);
        }
        $data = json_decode((string) $resp->getBody(), true);
        return $data['data'] ?? [];
    }

    public function refreshLongLived(string $token): array
    {
        $version = Env::get('GRAPH_API_VERSION', 'v24.0');
        $appId = Env::get('META_APP_ID');
        $appSecret = Env::get('META_APP_SECRET');
        if (!$appId || !$appSecret) {
            throw new \RuntimeException('Missing META_APP_ID or META_APP_SECRET');
        }
        try {
            $resp = $this->http->get(sprintf('%s/oauth/access_token', $version), [
                'query' => [
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $appId,
                    'client_secret' => $appSecret,
                    'fb_exchange_token' => $token,
                ],
            ]);
        } catch (RequestException $e) {
            $resp = $e->getResponse();
            $detail = $resp ? (string) $resp->getBody() : $e->getMessage();
            throw new \RuntimeException('HTTP error refreshing token: ' . $detail, 0);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('HTTP error refreshing token: ' . $e->getMessage(), 0);
        }
        $data = json_decode((string) $resp->getBody(), true) ?: [];
        $newToken = $data['access_token'] ?? null;
        if ($newToken) {
            $storage = Env::get('IG_TOKEN_STORAGE');
            if ($storage) {
                SecretStore::write($storage, $newToken);
            }
        }
        return $data;
    }
}
