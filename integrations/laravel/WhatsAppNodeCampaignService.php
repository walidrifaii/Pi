<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Syncs with the Node WhatsApp API in this repo (JWT user token + WhatsApp client id).
 * Drop into: app/Services/WhatsAppNodeCampaignService.php
 */
class WhatsAppNodeCampaignService
{
    /**
     * @param  array<int, array<string, mixed>>  $csvRows  Each row must include phone + name; any other keys become {placeholder} variables in the campaign message (Node merges them into contact.variables).
     */
    public function sendCampaign(
        string $campaignName,
        string $messageTemplate,
        array $csvRows,
        ?string $mediaUrl = null,
        ?string $mediaType = null
    ): array {
        $nodeUrl = rtrim((string) env('WHATSAPP_NODE_URL', ''), '/');
        $token = (string) env('WHATSAPP_NODE_TOKEN', '');
        $clientId = (string) env('WHATSAPP_NODE_CLIENT_ID', '');

        $integrationSecret = trim((string) env('LARAVEL_INTEGRATION_SECRET', ''));
        $integrationUserId = trim((string) env('WHATSAPP_NODE_USER_ID', ''));

        $useIntegration = $integrationSecret !== '' && $integrationUserId !== '';

        if ($nodeUrl === '' || $clientId === '') {
            return ['success' => false, 'error' => 'WhatsApp Node.js not configured (URL / client id)'];
        }

        if (! $useIntegration && $token === '') {
            return [
                'success' => false,
                'error' => 'Set WHATSAPP_NODE_TOKEN or use LARAVEL_INTEGRATION_SECRET + WHATSAPP_NODE_USER_ID (see Node .env LARAVEL_INTEGRATION_SECRET)',
            ];
        }

        if ($csvRows === []) {
            return ['success' => false, 'error' => 'No contacts to send'];
        }

        $headers = $this->nodeAuthHeaders($useIntegration, $integrationSecret, $integrationUserId, $token);

        $this->removeExistingCampaignsNamed($nodeUrl, $headers, $campaignName);

        $payload = [
            'name' => $campaignName,
            'message' => $messageTemplate,
            'clientId' => $clientId,
        ];

        $url = $this->normalizeMediaUrl($mediaUrl);
        if ($url !== null && $url !== '') {
            $payload['mediaUrl'] = $url;
            $payload['mediaType'] = $mediaType !== null && $mediaType !== '' ? $mediaType : 'image';
        }

        $create = Http::withHeaders($headers)->timeout(60)->post($nodeUrl . '/api/campaigns', $payload);

        if (! $create->successful()) {
            Log::error('NODE_CAMPAIGN_CREATE', [
                'status' => $create->status(),
                'body' => $create->body(),
            ]);

            return [
                'success' => false,
                'error' => $create->json('error')
                    ?? (is_array($create->json('errors')) ? json_encode($create->json('errors')) : null)
                    ?? $create->body()
                    ?? 'Campaign create failed',
            ];
        }

        $campaign = $create->json('campaign');
        $campaignId = $campaign['_id'] ?? $campaign['id'] ?? null;
        if (! $campaignId) {
            return ['success' => false, 'error' => 'No campaign id in Node response'];
        }

        foreach ($csvRows as $row) {
            $add = Http::withHeaders($headers)->timeout(30)->post(
                $nodeUrl . '/api/contacts/' . $campaignId . '/add',
                $this->buildContactAddPayload($row)
            );

            if (! $add->successful()) {
                Log::error('NODE_CONTACT_ADD', [
                    'campaign' => $campaignId,
                    'row' => $row,
                    'body' => $add->body(),
                ]);
                Http::withHeaders($headers)->delete($nodeUrl . '/api/campaigns/' . $campaignId);

                return [
                    'success' => false,
                    'error' => $add->json('error') ?? $add->body() ?? 'Contact add failed',
                ];
            }
        }

        $start = Http::withHeaders($headers)->timeout(30)->post(
            $nodeUrl . '/api/campaigns/' . $campaignId . '/start'
        );

        if (! $start->successful()) {
            Log::error('NODE_CAMPAIGN_START', ['body' => $start->body()]);

            return [
                'success' => false,
                'error' => $start->json('error') ?? $start->body() ?? 'Campaign start failed',
            ];
        }

        return ['success' => true, 'campaignId' => (string) $campaignId];
    }

    /**
     * Avoid duplicate campaign rows with the same name (booking id) on resend.
     */
    private function removeExistingCampaignsNamed(string $nodeUrl, array $headers, string $campaignName): void
    {
        $list = Http::withHeaders($headers)->timeout(30)->get($nodeUrl . '/api/campaigns');
        if (! $list->successful()) {
            return;
        }

        $campaigns = $list->json('campaigns') ?? [];
        foreach ($campaigns as $c) {
            if ((string) ($c['name'] ?? '') !== $campaignName) {
                continue;
            }
            $id = $c['_id'] ?? $c['id'] ?? null;
            if (! $id) {
                continue;
            }
            $status = (string) ($c['status'] ?? '');
            if ($status === 'running') {
                $pause = Http::withHeaders($headers)->timeout(30)->post(
                    $nodeUrl . '/api/campaigns/' . $id . '/pause'
                );
                if ($pause->successful()) {
                    sleep(2);
                } else {
                    Log::warning('NODE_CAMPAIGN_PAUSE_BEFORE_DELETE', [
                        'campaign_id' => $id,
                        'body' => $pause->body(),
                    ]);
                }
            }
            Http::withHeaders($headers)->timeout(30)->delete($nodeUrl . '/api/campaigns/' . $id);
        }
    }

    /**
     * Node route spreads every body field except phone + name into contact.variables for {placeholder} replacement.
     */
    private function buildContactAddPayload(array $row): array
    {
        $payload = [
            'phone' => (string) ($row['phone'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];

        foreach ($row as $key => $value) {
            if (in_array($key, ['phone', 'name'], true)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $payload[$key] = $value ? '1' : '0';
            } elseif (is_scalar($value) || $value instanceof \Stringable) {
                $payload[$key] = (string) $value;
            } else {
                $payload[$key] = json_encode($value);
            }
        }

        return $payload;
    }

    /**
     * Strip accidental double CDN prefix: https://host/https://host/path
     */
    private function normalizeMediaUrl(?string $mediaUrl): ?string
    {
        if ($mediaUrl === null) {
            return null;
        }
        $t = trim($mediaUrl);
        if ($t === '') {
            return null;
        }
        $prev = '';
        while ($prev !== $t) {
            $prev = $t;
            if (preg_match('#^(https?://[^/]+)/((?:https?://).+)$#i', $t, $m)) {
                $t = $m[2];
            }
        }

        return $t;
    }

    /**
     * Prefer integration headers (stable) over JWT (revoked after next Node web login).
     */
    private function nodeAuthHeaders(
        bool $useIntegration,
        string $integrationSecret,
        string $integrationUserId,
        string $jwtToken
    ): array {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($useIntegration) {
            $headers['X-Laravel-Integration-Secret'] = $integrationSecret;
            $headers['X-Integration-User-Id'] = $integrationUserId;
            $headers['Authorization'] = 'Bearer laravel-integration';

            return $headers;
        }

        $headers['Authorization'] = 'Bearer '.$jwtToken;

        return $headers;
    }
}
