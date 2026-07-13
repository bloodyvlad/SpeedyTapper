<?php

declare(strict_types=1);

namespace SpeedyTapper;

use Google\Client as GoogleClient;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

final class GoogleClientIdentityVerifier implements GoogleIdentityVerifier
{
    private GoogleClient $client;

    public function __construct(string $clientId)
    {
        if (!class_exists(GoogleClient::class)) {
            throw new ApiException(503, 'Google sign-in is not installed on this server.');
        }

        $this->client = new GoogleClient(['client_id' => $clientId]);
    }

    public function verify(string $credential): GoogleIdentity
    {
        if ($credential === '' || strlen($credential) > 8_192) {
            throw new ApiException(400, 'Google credential is invalid.');
        }

        try {
            $claims = $this->client->verifyIdToken($credential);
        } catch (GuzzleException) {
            throw new ApiException(503, 'Google sign-in is temporarily unavailable.');
        } catch (Throwable) {
            $claims = false;
        }

        $subject = is_array($claims) ? ($claims['sub'] ?? null) : null;
        if (!is_string($subject) || !preg_match('/^[0-9]{1,255}$/', $subject)) {
            throw new ApiException(401, 'Google sign-in could not be verified.');
        }

        return new GoogleIdentity(
            subject: $subject,
        );
    }
}
