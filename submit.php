<?php
// submit.php — two jobs:
//   action=lookup  -> turns the shared location into a street address (preview for the form)
//   (no action)    -> validates, looks the address up again itself, saves to CSV and emails you.
// The address is never typed in by the customer and never trusted from the browser.

header('Content-Type: application/json');

// ---- Settings -------------------------------------------------------------
const NOTIFY_EMAIL = 'suneprinsloo426@gmail.com';      // where new details get emailed
const FROM_EMAIL   = 'no-reply@yourdomain.co.za'; // must be an address on your own domain
// Keep this file OUTSIDE public_html so nobody can download it from the browser.
const CSV_FILE     = __DIR__ . '/../private/orders.csv';
// OpenStreetMap's free geocoder asks for a User-Agent that says who you are.
const GEOCODER_UA  = 'MyShopCheckout/1.0 (suneprinsloo426@gmail.com)';
// ---------------------------------------------------------------------------

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}
function fail(string $error, int $code = 400): void {
    respond(['ok' => false, 'error' => $error], $code);
}

// Turn coordinates into a readable address using OpenStreetMap (Nominatim).
// Returns '' if nothing usable comes back. To use Google instead, replace this function.
function reverse_geocode(float $lat, float $lng): string {
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&accept-language=en'
         . '&lat=' . number_format($lat, 6, '.', '') . '&lon=' . number_format($lng, 6, '.', '');

    $json = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_USERAGENT      => GEOCODER_UA,
        ]);
        $json = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx  = stream_context_create(['http' => ['timeout' => 6, 'header' => 'User-Agent: ' . GEOCODER_UA . "\r\n"]]);
        $json = @file_get_contents($url, false, $ctx);
    }
    if (!$json) return '';

    $d = json_decode($json, true);
    if (!is_array($d)) return '';
    $a = $d['address'] ?? [];

    $parts = array_filter([
        trim(($a['house_number'] ?? '') . ' ' . ($a['road'] ?? '')),
        $a['suburb'] ?? $a['neighbourhood'] ?? '',
        $a['city'] ?? $a['town'] ?? $a['village'] ?? '',
        $a['postcode'] ?? '',
    ]);
    $address = $parts ? implode(', ', $parts) : (string)($d['display_name'] ?? '');

    $address = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $address);
    return mb_substr(trim($address), 0, 200);
}

// Read and validate the shared location
function read_coords(): array {
    $lat = filter_var($_POST['lat'] ?? '', FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['lng'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        fail('Location is required. Please allow location access and try again.');
    }
    return [round($lat, 6), round($lng, 6)];
}

// Stop spreadsheet formula injection if you open the CSV in Excel
function safe_cell(string $v): string {
    return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Invalid request.', 405);

// ---- Preview: location -> address -----------------------------------------
if (($_POST['action'] ?? '') === 'lookup') {
    [$lat, $lng] = read_coords();
    $address = reverse_geocode($lat, $lng);
    if ($address === '') fail('No street address found for this spot.', 404);
    respond(['ok' => true, 'address' => $address]);
}

// ---- Final submit ----------------------------------------------------------

// Spam trap: bots fill the hidden field, people don't
if (!empty($_POST['website'])) respond(['ok' => true]);

$data = [];
foreach (['first_name', 'surname'] as $f) {
    $value = trim((string)($_POST[$f] ?? ''));
    $value = preg_replace('/[\r\n\t]+/', ' ', $value);
    if ($value === '') fail('Please fill in every field.');
    if (mb_strlen($value) > 60) fail('One of the fields is too long.');
    $data[$f] = $value;
}

[$lat, $lng] = read_coords();
$acc = filter_var($_POST['accuracy'] ?? '', FILTER_VALIDATE_INT);

// The address comes from our own lookup, never from the browser
$data['address']    = reverse_geocode($lat, $lng) ?: '(no street address found)';
$data['lat']        = $lat;
$data['lng']        = $lng;
$data['accuracy_m'] = $acc === false ? '' : $acc;

// Save to CSV
$dir = dirname(CSV_FILE);
if (!is_dir($dir)) mkdir($dir, 0750, true);
$isNew = !file_exists(CSV_FILE);

$fh = fopen(CSV_FILE, 'a');
if (!$fh) fail('Could not save your details. Please try again.', 500);
flock($fh, LOCK_EX);
if ($isNew) fputcsv($fh, ['received', 'first_name', 'surname', 'address', 'lat', 'lng', 'accuracy_m']);
fputcsv($fh, array_merge([date('Y-m-d H:i:s')], array_map('safe_cell', array_map('strval', array_values($data)))));
flock($fh, LOCK_UN);
fclose($fh);

// Email notification (the link opens the shared location in Google Maps)
$body = "New delivery details\n\n"
      . "Name:     {$data['first_name']} {$data['surname']}\n"
      . "Address:  {$data['address']}\n"
      . "Location: https://www.google.com/maps?q={$data['lat']},{$data['lng']} (accurate to about {$data['accuracy_m']} m)\n";
@mail(NOTIFY_EMAIL, 'New delivery details', $body, 'From: ' . FROM_EMAIL);

respond(['ok' => true]);
