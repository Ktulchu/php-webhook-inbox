<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox;

use JsonException;

/** Framework-independent HTTP boundary. Signature covers timestamp + '.' + raw body. */
final class Receiver
{
    public function __construct(
        private readonly Inbox $inbox,
        private readonly string $source,
        private readonly string $secret,
        private readonly int $maxAgeSeconds = 300,
    ) {
        if ($source === '' || $secret === '') {
            throw new \InvalidArgumentException('Source and signing secret are required');
        }
    }

    /** @return array{status: int, body: array<string, string>} */
    public function handle(string $method, string $rawBody, ?string $timestamp, ?string $signature): array
    {
        if ($method !== 'POST') {
            return $this->response(405, 'method_not_allowed');
        }

        if (strlen($rawBody) > 262144) {
            return $this->response(413, 'payload_too_large');
        }

        if (
            $timestamp === null
            || preg_match('/^[0-9]{10}$/D', $timestamp) !== 1
            || abs(time() - (int) $timestamp) > $this->maxAgeSeconds
            || $signature === null
        ) {
            return $this->response(401, 'invalid_signature');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secret);
        if (!hash_equals($expected, $signature)) {
            return $this->response(401, 'invalid_signature');
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->response(422, 'invalid_json');
        }

        if (
            !is_array($payload)
            || !isset($payload['id'])
            || !is_string($payload['id'])
            || preg_match('/^[A-Za-z0-9_.:-]{1,200}$/D', $payload['id']) !== 1
        ) {
            return $this->response(422, 'invalid_event_id');
        }

        $result = $this->inbox->receive($this->source, $payload['id'], $rawBody);

        return match ($result) {
            'accepted' => $this->response(202, 'accepted'),
            'duplicate' => $this->response(200, 'duplicate'),
            'conflict' => $this->response(409, 'event_id_conflict'),
        };
    }

    /** @return array{status: int, body: array<string, string>} */
    private function response(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['status' => $code]];
    }
}
