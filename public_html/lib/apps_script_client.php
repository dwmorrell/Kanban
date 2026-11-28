<?php
/**
 * Reads the work order sheet via a Google Apps Script Web App endpoint
 * (see scripts/apps-script/workorder-reader.gs, archive/node-event-board)
 * instead of Google's Sheets API, so no Google Cloud project, service
 * account or billing is needed.
 *
 * This file only ever makes a GET request. The Apps Script side enforces
 * read-only access by only calling getDisplayValues(); this client has no
 * way to ask it to write anything even if it wanted to.
 *
 * Ports scripts/apps-script-client.js's readSheetValues(), swapping
 * fetch() for cURL (no PHP dependency needed either way).
 */
declare(strict_types=1);

/**
 * @throws RuntimeException if $url/$token are missing, the request fails,
 *     the response isn't valid JSON, or the Apps Script side reports an error.
 */
function workorder_read_sheet_values(string $url, string $token, ?string $sheet = null, ?string $range = null): array
{
    if ($url === '') {
        throw new RuntimeException('WORKORDER_APPS_SCRIPT_URL is not set.');
    }
    if ($token === '') {
        throw new RuntimeException('WORKORDER_APPS_SCRIPT_TOKEN is not set.');
    }

    $query = ['token' => $token];
    if ($sheet !== null && $sheet !== '') {
        $query['sheet'] = $sheet;
    }
    if ($range !== null && $range !== '') {
        $query['range'] = $range;
    }
    $fullUrl = $url . '?' . http_build_query($query);

    $ch = curl_init($fullUrl);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize the Apps Script request.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        // Apps Script Web Apps answer the first hit with an HTTP redirect
        // before returning the actual JSON; without this the request comes
        // back with redirect/HTML content instead (fetch() in the Node
        // version follows redirects by default, which is why this wasn't an
        // issue there).
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPGET => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("Apps Script request failed: $curlError");
    }

    $body = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300 || !is_array($body)) {
        throw new RuntimeException("Apps Script request failed ($status).");
    }
    if (isset($body['error'])) {
        throw new RuntimeException('Apps Script reported: ' . $body['error']);
    }
    return is_array($body['values'] ?? null) ? $body['values'] : [];
}
