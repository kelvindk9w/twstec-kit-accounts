<?php

declare(strict_types=1);

// API Keys engine + Tenancy strings (en). Always via __().

return [

    // API authentication (ResolveTenant).
    'auth' => [
        // SINGLE, deliberately generic message: it does not reveal whether the
        // public key exists, whether the secret was wrong or whether the key
        // expired (non-oracular: prevents discovering which keys exist).
        'invalid' => 'Missing, invalid or expired API credentials.',
    ],

    // Scope authorization (scope:resource:action middleware).
    'scopes' => [
        'denied' => 'This API key is not allowed for the ":scope" scope.',
        'invalid_format' => 'Each scope must be in the "resource:action" format (e.g.: customers:read, pix:create, withdrawals:*).',
    ],

    // Key engine operations.
    'keys' => [
        'created' => 'API key created. Store the secret key now — it will not be shown again.',
        'rotated' => 'Key rotated. Store the new secret key now — it will not be shown again.',
        'revoked' => 'API key revoked successfully.',
        'not_rotatable' => 'Only active keys can be rotated.',
        'projects_synced' => 'Projects linked to the key successfully.',
    ],

    // Projects (organizational layer).
    'projects' => [
        'created' => 'Project created successfully.',
        'updated' => 'Project updated successfully.',
        'deleted' => 'Project removed successfully.',
        'invalid' => 'One or more of the given projects do not exist in your account.',
        'account_key_required' => 'This key is linked to projects and only acts on them. Use an unlinked (whole account) key to create projects and manage keys.',
    ],

];
