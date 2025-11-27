<?php

namespace App\Command;

use App\Service\ChunkStorageService;
use App\Service\StorageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup',
    description: 'Clean up expired chunks and old files'
)]
class CleanupCommand extends Command
{
    public function __construct(
        private readonly ChunkStorageService $chunkStorage,
        private readonly StorageService $storage,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Running cleanup tasks');

        // Cleanup expired chunks
        $io->section('Cleaning up expired chunks');
        $expiredChunks = $this->chunkStorage->cleanupExpiredChunks();
        $io->success(sprintf('Cleaned up %d expired chunk uploads', $expiredChunks));

        // Cleanup old files
        $io->section('Cleaning up old files');
        $oldFiles = $this->storage->cleanupOldFiles();
        $io->success(sprintf('Cleaned up %d old files', $oldFiles));

        $this->logger->info('Cleanup command completed', [
            'expired_chunks' => $expiredChunks,
            'old_files' => $oldFiles
        ]);

        return Command::SUCCESS;
    }
}

