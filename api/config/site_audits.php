<?php

declare(strict_types=1);

// Audit: the simulated visitor, its browser and its limits.
return [
    // The Claude model that plays the visitor and writes the findings.
    'model' => env('AUDIT_MODEL', 'claude-opus-5'),

    // The Node binary that runs resources/site-audit/runner.mjs, and where Playwright's Chromium is installed.
    'node' => env('AUDIT_NODE_BINARY', 'node'),
    'browsers_path' => env('PLAYWRIGHT_BROWSERS_PATH'),

    'user_agent' => 'Mozilla/5.0 (compatible; BuildPusherAudit/1.0; +'.rtrim((string) env('APP_URL', 'https://buildpusher.com'), '/').'/help/audit-bot)',

    // Steps a visitor may take on one journey before giving up.
    'max_steps' => (int) env('AUDIT_MAX_STEPS', 12),

    // Findings that get a rendered mock-up of the fix.
    'max_mockups' => (int) env('AUDIT_MAX_MOCKUPS', 3),

    // Brave Search, for suggesting competitors. Without a key, Claude suggests them from the site itself.
    'brave_search_key' => env('BRAVE_SEARCH_API_KEY'),
];
