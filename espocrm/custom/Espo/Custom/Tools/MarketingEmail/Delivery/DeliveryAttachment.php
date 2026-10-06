<?php
declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail\Delivery;

use InvalidArgumentException;

/** An email MIME part, not a URL to a private tenant file. */
final readonly class DeliveryAttachment
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const TYPES = [
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp',
    ];

    public function __construct(public string $name, public string $mimeType, public string $contents)
    {
        if ($name === '' || strlen($name) > 255 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name) || trim($name) !== $name) {
            throw new InvalidArgumentException('Use a plain attachment filename without paths or control characters.');
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension]) || self::TYPES[$extension] !== $mimeType) {
            throw new InvalidArgumentException('Marketing attachments support PDF, TXT, CSV, JPG, PNG, GIF and WebP only.');
        }
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Each marketing attachment must be nonempty and no larger than 5 MB.');
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        // Some libmagic builds classify large plain-text buffers as binary.
        if (in_array($mimeType, ['text/plain', 'text/csv'], true) && $detected === 'application/octet-stream' && mb_check_encoding($contents, 'UTF-8') && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $contents)) {
            $detected = 'text/plain';
        }
        if ($detected !== $mimeType && !($mimeType === 'text/csv' && in_array($detected, ['text/plain', 'text/csv', 'application/csv'], true))) {
            throw new InvalidArgumentException('The attachment content does not match its file type.');
        }
    }

    /** @return array{name:string,content_type:string,data:string} */
    public function toPostalPayload(): array
    {
        return ['name' => $this->name, 'content_type' => $this->mimeType, 'data' => base64_encode($this->contents)];
    }
}
