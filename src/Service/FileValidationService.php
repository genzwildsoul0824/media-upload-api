<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class FileValidationService
{
    private const MAGIC_NUMBERS = [
        'image/jpeg' => ['FFD8FF'],
        'image/png' => ['89504E47'],
        'image/gif' => ['474946383761', '474946383961'],
        'image/webp' => ['52494646'],
        'video/mp4' => ['66747970'],
        'video/quicktime' => ['6D6F6F76'],
        'video/webm' => ['1A45DFA3']
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $allowedImageTypes,
        private readonly array $allowedVideoTypes,
        private readonly int $maxFileSize
    ) {
    }

    public function validateFileMetadata(array $metadata): array
    {
        $errors = [];

        // Validate filename
        if (empty($metadata['filename'])) {
            $errors[] = 'Filename is required';
        }

        // Validate file size
        if (!isset($metadata['file_size']) || $metadata['file_size'] <= 0) {
            $errors[] = 'Invalid file size';
        } elseif ($metadata['file_size'] > $this->maxFileSize) {
            $errors[] = sprintf(
                'File size exceeds maximum allowed (%d MB)',
                $this->maxFileSize / 1048576
            );
        }

        // Validate MIME type
        if (empty($metadata['mime_type'])) {
            $errors[] = 'MIME type is required';
        } else {
            $allowedTypes = array_merge($this->allowedImageTypes, $this->allowedVideoTypes);
            
            if (!in_array($metadata['mime_type'], $allowedTypes, true)) {
                $errors[] = 'File type not allowed';
            }
        }

        // Validate chunk count
        if (!isset($metadata['total_chunks']) || $metadata['total_chunks'] <= 0) {
            $errors[] = 'Invalid chunk count';
        }

        if (!empty($errors)) {
            $this->logger->warning('File validation failed', [
                'filename' => $metadata['filename'] ?? 'unknown',
                'errors' => $errors
            ]);
        }

        return $errors;
    }

    public function validateFileByMagicNumber(string $filePath, string $expectedMimeType): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $handle = fopen($filePath, 'rb');
        $header = fread($handle, 12);
        fclose($handle);

        $headerHex = strtoupper(bin2hex($header));

        if (!isset(self::MAGIC_NUMBERS[$expectedMimeType])) {
            $this->logger->warning('Unknown MIME type for magic number validation', [
                'mime_type' => $expectedMimeType
            ]);
            return false;
        }

        foreach (self::MAGIC_NUMBERS[$expectedMimeType] as $magicNumber) {
            if (str_starts_with($headerHex, $magicNumber)) {
                return true;
            }
        }

        $this->logger->warning('Magic number validation failed', [
            'expected_mime' => $expectedMimeType,
            'header_hex' => substr($headerHex, 0, 24)
        ]);

        return false;
    }

    public function calculateMd5(string $filePath): string
    {
        return md5_file($filePath);
    }

    public function checkDuplicate(string $md5Hash, string $storagePath): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($storagePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fileMd5 = md5_file($file->getPathname());
                
                if ($fileMd5 === $md5Hash) {
                    $this->logger->info('Duplicate file found', [
                        'md5' => $md5Hash,
                        'existing_file' => $file->getPathname()
                    ]);
                    
                    return $file->getPathname();
                }
            }
        }

        return null;
    }
}

