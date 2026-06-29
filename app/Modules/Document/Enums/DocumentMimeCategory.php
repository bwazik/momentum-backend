<?php

namespace App\Modules\Document\Enums;

enum DocumentMimeCategory: int
{
    case Pdf = 1;
    case Image = 2;
    case Word = 3;
    case Excel = 4;
    case Other = 5;

    public static function fromMimeType(string $mimeType): self
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::Image,
            $mimeType === 'application/pdf' => self::Pdf,
            in_array($mimeType, [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ], true) => self::Word,
            in_array($mimeType, [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ], true) => self::Excel,
            default => self::Other,
        };
    }

    public function supportsPreview(): bool
    {
        return in_array($this, [self::Pdf, self::Image], true);
    }
}
