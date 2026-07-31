<?php

/**
 * Suggest and import exact product images from public catalog sources.
 */

function product_images_http_get(string $url, int $timeout = 12, array $headers = []): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TiendaHispanaElRey/1.0; +product-image-suggestions)',
        CURLOPT_HTTPHEADER => $headers !== [] ? $headers : ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $code >= 400) {
        return null;
    }
    return $body;
}

function product_images_tokens(string $text): array {
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
    $parts = preg_split('/\s+/u', trim($text)) ?: [];
    $stop = ['the', 'and', 'with', 'for', 'de', 'la', 'el', 'los', 'las', 'y', 'en', 'un', 'una', 'of', 'a', 'pack', 'count', 'oz', 'fl'];
    $out = [];
    foreach ($parts as $part) {
        if (mb_strlen($part) < 2 || in_array($part, $stop, true)) {
            continue;
        }
        $out[] = $part;
    }
    return array_values(array_unique($out));
}

function product_images_relevance(string $title, string $query): float {
    $qTokens = product_images_tokens($query);
    $tTokens = product_images_tokens($title);
    if ($qTokens === [] || $tTokens === []) {
        return 0.0;
    }
    $hit = 0;
    foreach ($qTokens as $token) {
        foreach ($tTokens as $titleToken) {
            if ($token === $titleToken || str_contains($titleToken, $token) || str_contains($token, $titleToken)) {
                $hit++;
                break;
            }
        }
    }
    return $hit / count($qTokens);
}

function product_images_push(array &$out, string $url, string $thumb, string $source, string $title = '', float $score = 0.0): void {
    $url = trim($url);
    $thumb = trim($thumb) ?: $url;
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return;
    }
    foreach ($out as $item) {
        if (($item['url'] ?? '') === $url) {
            if ($score > (float) ($item['score'] ?? 0)) {
                $item['score'] = $score;
            }
            return;
        }
    }
    $out[] = [
        'url' => $url,
        'thumb' => $thumb,
        'source' => $source,
        'title' => mb_substr(trim($title), 0, 120),
        'score' => $score,
    ];
}

function product_images_from_off_product(array $product, string $source, string $query = ''): array {
    $title = (string) ($product['product_name'] ?? ($product['generic_name'] ?? ''));
    $score = $query !== '' ? max(0.55, product_images_relevance($title, $query)) : 1.0;
    $images = [];
    $candidates = [
        $product['image_front_url'] ?? null,
        $product['selected_images']['front']['display']['en'] ?? null,
        $product['selected_images']['front']['display']['es'] ?? null,
        $product['image_url'] ?? null,
        $product['image_front_small_url'] ?? null,
    ];
    foreach ($candidates as $img) {
        if (!is_string($img) || trim($img) === '') {
            continue;
        }
        $thumb = (string) ($product['image_front_small_url'] ?? $img);
        product_images_push($images, $img, $thumb, $source, $title, $score);
    }
    return $images;
}

function product_images_search_barcode_catalogs(?string $barcode, string $query = ''): array {
    if ($barcode === null || $barcode === '' || !preg_match('/^\d{8,14}$/', $barcode)) {
        return [];
    }
    $hosts = [
        'openfoodfacts' => 'https://world.openfoodfacts.org/api/v2/product/',
        'openbeautyfacts' => 'https://world.openbeautyfacts.org/api/v2/product/',
        'openproductsfacts' => 'https://world.openproductsfacts.org/api/v2/product/',
    ];
    $results = [];
    foreach ($hosts as $source => $base) {
        $raw = product_images_http_get($base . rawurlencode($barcode) . '.json');
        if ($raw === null) {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (($decoded['status'] ?? 0) != 1 || !is_array($decoded['product'] ?? null)) {
            continue;
        }
        foreach (product_images_from_off_product($decoded['product'], $source, $query) as $item) {
            $item['score'] = max((float) $item['score'], 0.98);
            product_images_push($results, $item['url'], $item['thumb'], $item['source'], $item['title'], $item['score']);
        }
        if ($results !== []) {
            break;
        }
    }
    return $results;
}

function product_images_search_open_food_facts(string $query, int $limit = 8): array {
    if ($query === '') {
        return [];
    }
    $url = 'https://world.openfoodfacts.org/cgi/search.pl?' . http_build_query([
        'search_terms' => $query,
        'search_simple' => 1,
        'action' => 'process',
        'json' => 1,
        'page_size' => 24,
        'fields' => 'product_name,brands,image_front_url,image_url,image_front_small_url,code',
    ]);
    $raw = product_images_http_get($url);
    if ($raw === null) {
        return [];
    }
    $decoded = json_decode($raw, true);
    $results = [];
    foreach (($decoded['products'] ?? []) as $product) {
        if (!is_array($product)) {
            continue;
        }
        $title = trim(((string) ($product['brands'] ?? '')) . ' ' . ((string) ($product['product_name'] ?? '')));
        $score = product_images_relevance($title, $query);
        if ($score < 0.45) {
            continue;
        }
        $img = (string) ($product['image_front_url'] ?? ($product['image_url'] ?? ''));
        if ($img === '') {
            continue;
        }
        product_images_push(
            $results,
            $img,
            (string) ($product['image_front_small_url'] ?? $img),
            'openfoodfacts',
            $title,
            $score
        );
        if (count($results) >= $limit) {
            break;
        }
    }
    return $results;
}

function product_images_search_duckduckgo(string $query, int $limit = 8): array {
    if ($query === '') {
        return [];
    }
    // Prefer packaging / product shots over lifestyle/similar scenes.
    $search = $query . ' product packaging';
    $landing = product_images_http_get(
        'https://duckduckgo.com/?' . http_build_query(['q' => $search, 'iax' => 'images', 'ia' => 'images']),
        12,
        ['Accept: text/html']
    );
    if ($landing === null || !preg_match('/vqd=(?:\'|\"|)([\d-]+)/', $landing, $m)) {
        return [];
    }
    $vqd = $m[1];
    $apiUrl = 'https://duckduckgo.com/i.js?' . http_build_query([
        'l' => 'us-en',
        'o' => 'json',
        'q' => $search,
        'vqd' => $vqd,
        'f' => ',,,,,',
        'p' => '1',
    ]);
    $raw = product_images_http_get($apiUrl, 12, [
        'Accept: application/json',
        'Referer: https://duckduckgo.com/',
    ]);
    if ($raw === null) {
        return [];
    }
    $decoded = json_decode($raw, true);
    $results = [];
    foreach (($decoded['results'] ?? []) as $row) {
        $title = (string) ($row['title'] ?? '');
        $score = product_images_relevance($title, $query);
        // Keep only strong product matches from web image search.
        if ($score < 0.5) {
            continue;
        }
        $image = (string) ($row['image'] ?? '');
        $thumb = (string) ($row['thumbnail'] ?? $image);
        product_images_push($results, $image, $thumb, 'web', $title, $score + 0.05);
        if (count($results) >= $limit) {
            break;
        }
    }
    return $results;
}

function product_images_build_query(string $query, ?string $brand = null): string {
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    $brand = trim((string) $brand);
    if ($brand !== '' && $query !== '' && !str_contains(mb_strtolower($query), mb_strtolower($brand))) {
        $query = $brand . ' ' . $query;
    }
    return $query;
}

function product_images_suggest(string $query, ?string $barcode = null, int $limit = 8, ?string $brand = null): array {
    $query = product_images_build_query($query, $brand);
    if ($query === '' && ($barcode === null || $barcode === '')) {
        return [];
    }

    $merged = [];
    foreach (product_images_search_barcode_catalogs($barcode, $query) as $item) {
        product_images_push($merged, $item['url'], $item['thumb'], $item['source'], $item['title'], (float) $item['score']);
    }
    if ($query !== '') {
        foreach (product_images_search_open_food_facts($query, $limit) as $item) {
            product_images_push($merged, $item['url'], $item['thumb'], $item['source'], $item['title'], (float) $item['score']);
        }
        foreach (product_images_search_duckduckgo($query, $limit) as $item) {
            product_images_push($merged, $item['url'], $item['thumb'], $item['source'], $item['title'], (float) $item['score']);
        }
    }

    usort($merged, static fn($a, $b) => ((float) $b['score'] <=> (float) $a['score']));
    $merged = array_values(array_filter($merged, static fn($item) => (float) ($item['score'] ?? 0) >= 0.45));
    return array_slice($merged, 0, $limit);
}

function product_images_is_public_url(string $url): bool {
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'http' && ($parts['scheme'] ?? '') !== 'https') {
        return false;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
        return false;
    }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if ($ips === []) {
            $resolved = gethostbynamel($host) ?: [];
            $ips = array_merge($ips, $resolved);
        }
    }
    if ($ips === []) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

function product_images_import_url(string $url): string {
    $url = trim($url);
    if (!product_images_is_public_url($url)) {
        throw new InvalidArgumentException('Invalid or blocked image URL.');
    }
    if (strlen($url) > 2000) {
        throw new InvalidArgumentException('Image URL is too long.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'invimg_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temp file.');
    }

    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        @unlink($tmp);
        throw new RuntimeException('Unable to open temp file.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TiendaHispanaElRey/1.0; +product-image-import)',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    fclose($fp);

    if ($ok === false || $code >= 400) {
        @unlink($tmp);
        throw new RuntimeException('Failed to download image' . ($err ? ": {$err}" : ''));
    }

    $size = filesize($tmp);
    if ($size === false || $size <= 0 || $size > MAX_UPLOAD_SIZE) {
        @unlink($tmp);
        throw new RuntimeException('Downloaded image is empty or too large.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmp) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($allowed[$mime]) || @getimagesize($tmp) === false) {
        $headerMime = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        if (!isset($allowed[$headerMime]) || @getimagesize($tmp) === false) {
            @unlink($tmp);
            throw new RuntimeException('Downloaded file is not a valid JPEG/PNG/WEBP image.');
        }
        $mime = $headerMime;
    }

    $subdir = 'inventory';
    $name = uniqid('web_', true) . '.' . $allowed[$mime];

    if (function_exists('storage_enabled') && storage_enabled()) {
        $bucket = storage_bucket_for_subdir($subdir);
        $objectPath = $subdir . '/' . $name;
        try {
            $path = storage_upload($tmp, $bucket, $objectPath);
            @unlink($tmp);
            return $path;
        } catch (Throwable $e) {
            @unlink($tmp);
            throw new RuntimeException('Unable to store image in remote storage.');
        }
    }

    $dir = UPLOAD_DIR . $subdir . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to create upload directory.');
    }
    $dest = $dir . $name;
    if (!@rename($tmp, $dest) && !(@copy($tmp, $dest) && @unlink($tmp))) {
        @unlink($tmp);
        throw new RuntimeException('Unable to store downloaded image.');
    }
    @chmod($dest, 0644);
    return 'assets/uploads/' . $subdir . '/' . $name;
}
