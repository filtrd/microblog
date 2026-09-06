<?php
declare(strict_types=1);

const POST_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

function saveImageFromFile(string $source): string
{
    if (!is_file($source) || filesize($source) > POST_IMAGE_MAX_BYTES) {
        throw new RuntimeException('Image is too large. Maximum size is 5 MB.');
    }

    return saveImageFromData(file_get_contents($source));
}

function saveImageFromUrl(string $url): string
{
    $parts = parse_url(trim($url));
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = $parts['host'] ?? '';

    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        throw new RuntimeException('Please enter a valid image URL.');
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (!isPublicImageIp($host)) throw new RuntimeException('That image URL is not allowed.');
    } else {
        $addresses = gethostbynamel($host) ?: [];
        if (!$addresses) throw new RuntimeException('The image URL could not be reached.');
        $publicAddress = null;
        foreach ($addresses as $address) {
            if (isPublicImageIp($address)) {
                $publicAddress = $address;
                break;
            }
        }
        if ($publicAddress === null) throw new RuntimeException('That image URL is not allowed.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('Image URL uploads are unavailable.');
    }

    $curl = curl_init($url);
    $data = '';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Microblog image fetcher',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data): int {
            if (strlen($data) + strlen($chunk) > POST_IMAGE_MAX_BYTES) return 0;
            $data .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($ok === false || $status !== 200) {
        throw new RuntimeException($error !== '' ? 'The image could not be downloaded.' : 'The image URL did not return an image.');
    }

    return saveImageFromData($data);
}

function saveImageFromData(string|false $data): string
{
    if ($data === false || $data === '' || strlen($data) > POST_IMAGE_MAX_BYTES) {
        throw new RuntimeException('Image is too large. Maximum size is 5 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($data);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Please use a JPEG, PNG, or WebP image.');
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
        throw new RuntimeException('Image processing is unavailable. Please try again later.');
    }

    $image = imagecreatefromstring($data);
    if ($image === false) {
        throw new RuntimeException("We couldn't process that image. Please try another one.");
    }

    imagepalettetotruecolor($image);
    imagealphablending($image, false);
    imagesavealpha($image, true);

    $uploadDir = __DIR__ . '/../uploads/posts';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        imagedestroy($image);
        throw new RuntimeException('Image could not be saved. Please try again later.');
    }

    $filename = bin2hex(random_bytes(16)) . '.webp';
    $destination = $uploadDir . '/' . $filename;

    if (!imagewebp($image, $destination, 82)) {
        imagedestroy($image);
        @unlink($destination);
        throw new RuntimeException('Image could not be saved. Please try again later.');
    }

    imagedestroy($image);
    return 'uploads/posts/' . $filename;
}

function isPublicImageIp(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}
