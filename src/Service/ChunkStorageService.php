<?php

namespace App\Service;

use Predis\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

class ChunkStorageService
{
    private Filesystem $filesystem;

    public function __construct(
        private readonly ClientInterface $redis,
        private readonly LoggerInterface $logger,
        private readonly string $chunkStoragePath,
        private readonly int $chunkTimeout
    ) {
        $this->filesystem = new Filesystem();
    }

    public function initializeUpload(string $uploadId, array $metadata): void
    {
        $key = $this->getUploadKey($uploadId);
        
        $data = [
            'upload_id' => $uploadId,
            'filename' => $metadata['filename'],
            'total_chunks' => $metadata['total_chunks'],
            'file_size' => $metadata['file_size'],
            'mime_type' => $metadata['mime_type'],
            'md5' => $metadata['md5'] ?? null,
            'uploaded_chunks' => [],
            'created_at' => time(),
            'status' => 'initiated'
        ];

        $this->redis->setex($key, 86400, json_encode($data)); // 24 hour retention
        
        $this->logger->info('Upload initialized', [
            'upload_id' => $uploadId,
            'filename' => $metadata['filename'],
            'total_chunks' => $metadata['total_chunks']
        ]);
    }

    public function saveChunk(string $uploadId, int $chunkIndex, string $chunkData): void
    {
        $chunkPath = $this->getChunkPath($uploadId, $chunkIndex);
        $this->filesystem->mkdir(dirname($chunkPath), 0755);
        
        file_put_contents($chunkPath, $chunkData);
        
        // Update Redis metadata
        $key = $this->getUploadKey($uploadId);
        $data = json_decode($this->redis->get($key), true);
        
        if ($data) {
            $data['uploaded_chunks'][] = $chunkIndex;
            $data['uploaded_chunks'] = array_unique($data['uploaded_chunks']);
            sort($data['uploaded_chunks']);
            $data['last_updated'] = time();
            
            $this->redis->setex($key, 86400, json_encode($data));
            
            $this->logger->debug('Chunk saved', [
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
                'progress' => count($data['uploaded_chunks']) . '/' . $data['total_chunks']
            ]);
        }
    }

    public function getUploadStatus(string $uploadId): ?array
    {
        $key = $this->getUploadKey($uploadId);
        $data = $this->redis->get($key);
        
        return $data ? json_decode($data, true) : null;
    }

    public function isUploadComplete(string $uploadId): bool
    {
        $status = $this->getUploadStatus($uploadId);
        
        if (!$status) {
            return false;
        }
        
        return count($status['uploaded_chunks']) === (int)$status['total_chunks'];
    }

    public function assembleChunks(string $uploadId): string
    {
        $status = $this->getUploadStatus($uploadId);
        
        if (!$status || !$this->isUploadComplete($uploadId)) {
            throw new \RuntimeException('Upload is not complete');
        }
        
        $tempFile = $this->chunkStoragePath . '/assembled_' . $uploadId;
        $outputHandle = fopen($tempFile, 'wb');
        
        for ($i = 0; $i < $status['total_chunks']; $i++) {
            $chunkPath = $this->getChunkPath($uploadId, $i);
            
            if (!file_exists($chunkPath)) {
                fclose($outputHandle);
                throw new \RuntimeException("Chunk $i is missing");
            }
            
            $chunkData = file_get_contents($chunkPath);
            fwrite($outputHandle, $chunkData);
        }
        
        fclose($outputHandle);
        
        $this->logger->info('Chunks assembled', [
            'upload_id' => $uploadId,
            'filename' => $status['filename'],
            'file_size' => filesize($tempFile)
        ]);
        
        return $tempFile;
    }

    public function cleanupChunks(string $uploadId): void
    {
        $uploadDir = $this->chunkStoragePath . '/' . $uploadId;
        
        if ($this->filesystem->exists($uploadDir)) {
            $this->filesystem->remove($uploadDir);
        }
        
        // Remove from Redis
        $key = $this->getUploadKey($uploadId);
        $this->redis->del($key);
        
        $this->logger->debug('Chunks cleaned up', ['upload_id' => $uploadId]);
    }

    public function cleanupExpiredChunks(): int
    {
        $cleaned = 0;
        $now = time();
        
        $keys = $this->redis->keys('upload:*');
        
        foreach ($keys as $key) {
            $data = json_decode($this->redis->get($key), true);
            
            if ($data && isset($data['created_at'])) {
                $age = $now - $data['created_at'];
                
                if ($age > $this->chunkTimeout) {
                    $this->cleanupChunks($data['upload_id']);
                    $cleaned++;
                }
            }
        }
        
        $this->logger->info('Expired chunks cleaned', ['count' => $cleaned]);
        
        return $cleaned;
    }

    private function getUploadKey(string $uploadId): string
    {
        return 'upload:' . $uploadId;
    }

    private function getChunkPath(string $uploadId, int $chunkIndex): string
    {
        return $this->chunkStoragePath . '/' . $uploadId . '/chunk_' . $chunkIndex;
    }
}

