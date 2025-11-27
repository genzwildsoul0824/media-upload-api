<?php

namespace App\Tests\Service;

use App\Service\ChunkStorageService;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\NullLogger;

class ChunkStorageServiceTest extends TestCase
{
    private ChunkStorageService $service;
    private Client $redis;
    private string $chunkStoragePath;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(Client::class);
        $this->chunkStoragePath = sys_get_temp_dir() . '/test_chunks_' . uniqid();
        mkdir($this->chunkStoragePath, 0777, true);

        $this->service = new ChunkStorageService(
            $this->redis,
            new NullLogger(),
            $this->chunkStoragePath,
            1800
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->chunkStoragePath)) {
            $this->removeDirectory($this->chunkStoragePath);
        }
    }

    public function testInitializeUpload(): void
    {
        $uploadId = 'test_upload_123';
        $metadata = [
            'filename' => 'test.mp4',
            'total_chunks' => 10,
            'file_size' => 10485760,
            'mime_type' => 'video/mp4'
        ];

        $this->redis->expects($this->once())
            ->method('setex')
            ->with(
                $this->stringContains('upload:'),
                $this->equalTo(86400),
                $this->isType('string')
            );

        $this->service->initializeUpload($uploadId, $metadata);
    }

    public function testSaveChunk(): void
    {
        $uploadId = 'test_upload_456';
        $chunkIndex = 0;
        $chunkData = 'test chunk data';

        $uploadData = [
            'upload_id' => $uploadId,
            'uploaded_chunks' => [],
            'total_chunks' => 5
        ];

        $this->redis->expects($this->exactly(2))
            ->method('get')
            ->willReturn(json_encode($uploadData));

        $this->redis->expects($this->once())
            ->method('setex');

        $this->service->saveChunk($uploadId, $chunkIndex, $chunkData);

        $this->assertTrue(true); // Assert no exceptions thrown
    }

    private function removeDirectory(string $path): void
    {
        $files = glob($path . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
    }
}

