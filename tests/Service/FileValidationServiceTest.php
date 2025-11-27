<?php

namespace App\Tests\Service;

use App\Service\FileValidationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FileValidationServiceTest extends TestCase
{
    private FileValidationService $service;

    protected function setUp(): void
    {
        $this->service = new FileValidationService(
            new NullLogger(),
            ['image/jpeg', 'image/png'],
            ['video/mp4'],
            10485760 // 10MB
        );
    }

    public function testValidateFileMetadataSuccess(): void
    {
        $metadata = [
            'filename' => 'test.jpg',
            'file_size' => 1048576,
            'mime_type' => 'image/jpeg',
            'total_chunks' => 10
        ];

        $errors = $this->service->validateFileMetadata($metadata);
        $this->assertEmpty($errors);
    }

    public function testValidateFileMetadataFileTooLarge(): void
    {
        $metadata = [
            'filename' => 'test.jpg',
            'file_size' => 20971520, // 20MB
            'mime_type' => 'image/jpeg',
            'total_chunks' => 20
        ];

        $errors = $this->service->validateFileMetadata($metadata);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('exceeds maximum', $errors[0]);
    }

    public function testValidateFileMetadataInvalidMimeType(): void
    {
        $metadata = [
            'filename' => 'test.exe',
            'file_size' => 1048576,
            'mime_type' => 'application/x-msdownload',
            'total_chunks' => 10
        ];

        $errors = $this->service->validateFileMetadata($metadata);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not allowed', $errors[0]);
    }

    public function testValidateFileMetadataMissingFilename(): void
    {
        $metadata = [
            'file_size' => 1048576,
            'mime_type' => 'image/jpeg',
            'total_chunks' => 10
        ];

        $errors = $this->service->validateFileMetadata($metadata);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Filename is required', $errors[0]);
    }
}

