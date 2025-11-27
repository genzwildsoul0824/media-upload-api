<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

class StorageService
{
    private Filesystem $filesystem;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $storagePath,
        private readonly int $fileRetentionDays
    ) {
        $this->filesystem = new Filesystem();
    }

    public function storeFile(string $tempFilePath, string $originalFilename, ?string $userId = null): string
    {
        // Create organized directory structure: uploads/YYYY/MM/DD/userId/
        $date = new \DateTime();
        $year = $date->format('Y');
        $month = $date->format('m');
        $day = $date->format('d');
        
        $userFolder = $userId ?? 'anonymous';
        $targetDir = sprintf(
            '%s/%s/%s/%s/%s',
            $this->storagePath,
            $year,
            $month,
            $day,
            $userFolder
        );

        $this->filesystem->mkdir($targetDir, 0755);

        // Generate unique filename
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $basename = pathinfo($originalFilename, PATHINFO_FILENAME);
        $uniqueFilename = sprintf(
            '%s_%s.%s',
            $basename,
            uniqid('', true),
            $extension
        );

        $targetPath = $targetDir . '/' . $uniqueFilename;

        // Move file
        $this->filesystem->rename($tempFilePath, $targetPath);

        $this->logger->info('File stored', [
            'original_filename' => $originalFilename,
            'stored_path' => $targetPath,
            'user_id' => $userId
        ]);

        return $targetPath;
    }

    public function cleanupOldFiles(): int
    {
        $cleaned = 0;
        $cutoffTime = time() - ($this->fileRetentionDays * 86400);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getMTime() < $cutoffTime) {
                $this->filesystem->remove($file->getPathname());
                $cleaned++;
                
                $this->logger->debug('Old file removed', [
                    'path' => $file->getPathname(),
                    'age_days' => ($file->getMTime() - $cutoffTime) / 86400
                ]);
            }
        }

        $this->logger->info('Old files cleaned up', ['count' => $cleaned]);

        return $cleaned;
    }

    public function getStorageStats(): array
    {
        $totalSize = 0;
        $fileCount = 0;

        if (!is_dir($this->storagePath)) {
            return [
                'total_size' => 0,
                'file_count' => 0,
                'storage_path' => $this->storagePath
            ];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalSize += $file->getSize();
                $fileCount++;
            }
        }

        return [
            'total_size' => $totalSize,
            'total_size_mb' => round($totalSize / 1048576, 2),
            'file_count' => $fileCount,
            'storage_path' => $this->storagePath
        ];
    }
}

