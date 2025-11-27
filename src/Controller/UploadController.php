<?php

namespace App\Controller;

use App\Service\ChunkStorageService;
use App\Service\FileValidationService;
use App\Service\RateLimitService;
use App\Service\StorageService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/upload', name: 'upload_')]
class UploadController extends AbstractController
{
    public function __construct(
        private readonly ChunkStorageService $chunkStorage,
        private readonly FileValidationService $fileValidation,
        private readonly StorageService $storage,
        private readonly RateLimitService $rateLimit,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('/initiate', name: 'initiate', methods: ['POST'])]
    public function initiateUpload(Request $request): JsonResponse
    {
        try {
            // Rate limiting
            if (!$this->rateLimit->checkRateLimit($request)) {
                return $this->json([
                    'error' => 'Rate limit exceeded',
                    'message' => 'Too many requests. Please try again later.'
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }

            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return $this->json([
                    'error' => 'Invalid request',
                    'message' => 'Request body must be valid JSON'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate metadata
            $errors = $this->fileValidation->validateFileMetadata($data);

            if (!empty($errors)) {
                return $this->json([
                    'error' => 'Validation failed',
                    'details' => $errors
                ], Response::HTTP_BAD_REQUEST);
            }

            // Generate upload ID
            $uploadId = uniqid('upload_', true);

            // Initialize upload in Redis
            $this->chunkStorage->initializeUpload($uploadId, $data);

            $this->logger->info('Upload initiated', [
                'upload_id' => $uploadId,
                'filename' => $data['filename'],
                'client_ip' => $request->getClientIp()
            ]);

            return $this->json([
                'upload_id' => $uploadId,
                'message' => 'Upload initiated successfully',
                'metadata' => [
                    'filename' => $data['filename'],
                    'total_chunks' => $data['total_chunks'],
                    'file_size' => $data['file_size']
                ]
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            $this->logger->error('Failed to initiate upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'error' => 'Internal server error',
                'message' => 'Failed to initiate upload'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/chunk', name: 'chunk', methods: ['POST'])]
    public function uploadChunk(Request $request): JsonResponse
    {
        try {
            // Rate limiting
            if (!$this->rateLimit->checkRateLimit($request)) {
                return $this->json([
                    'error' => 'Rate limit exceeded'
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }

            $uploadId = $request->request->get('upload_id');
            $chunkIndex = (int)$request->request->get('chunk_index');
            $file = $request->files->get('chunk');

            if (!$uploadId || $chunkIndex < 0 || !$file) {
                return $this->json([
                    'error' => 'Invalid request',
                    'message' => 'upload_id, chunk_index, and chunk file are required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Verify upload exists
            $status = $this->chunkStorage->getUploadStatus($uploadId);

            if (!$status) {
                return $this->json([
                    'error' => 'Upload not found',
                    'message' => 'Upload session does not exist or has expired'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if chunk already uploaded
            if (in_array($chunkIndex, $status['uploaded_chunks'], true)) {
                return $this->json([
                    'message' => 'Chunk already uploaded',
                    'chunk_index' => $chunkIndex
                ], Response::HTTP_OK);
            }

            // Save chunk
            $chunkData = file_get_contents($file->getPathname());
            $this->chunkStorage->saveChunk($uploadId, $chunkIndex, $chunkData);

            $updatedStatus = $this->chunkStorage->getUploadStatus($uploadId);
            $progress = count($updatedStatus['uploaded_chunks']) / $updatedStatus['total_chunks'] * 100;

            return $this->json([
                'message' => 'Chunk uploaded successfully',
                'chunk_index' => $chunkIndex,
                'progress' => round($progress, 2),
                'uploaded_chunks' => count($updatedStatus['uploaded_chunks']),
                'total_chunks' => $updatedStatus['total_chunks']
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('Failed to upload chunk', [
                'upload_id' => $uploadId ?? 'unknown',
                'chunk_index' => $chunkIndex ?? -1,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to upload chunk',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/finalize', name: 'finalize', methods: ['POST'])]
    public function finalizeUpload(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $uploadId = $data['upload_id'] ?? null;
            $userId = $data['user_id'] ?? null;

            if (!$uploadId) {
                return $this->json([
                    'error' => 'Invalid request',
                    'message' => 'upload_id is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if upload is complete
            if (!$this->chunkStorage->isUploadComplete($uploadId)) {
                $status = $this->chunkStorage->getUploadStatus($uploadId);
                
                return $this->json([
                    'error' => 'Upload incomplete',
                    'message' => 'Not all chunks have been uploaded',
                    'uploaded_chunks' => count($status['uploaded_chunks']),
                    'total_chunks' => $status['total_chunks']
                ], Response::HTTP_BAD_REQUEST);
            }

            // Assemble chunks
            $tempFile = $this->chunkStorage->assembleChunks($uploadId);
            $status = $this->chunkStorage->getUploadStatus($uploadId);

            // Validate file by magic number
            if (!$this->fileValidation->validateFileByMagicNumber($tempFile, $status['mime_type'])) {
                unlink($tempFile);
                $this->chunkStorage->cleanupChunks($uploadId);
                
                return $this->json([
                    'error' => 'Validation failed',
                    'message' => 'File type validation failed (magic number mismatch)'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Calculate MD5 and check for duplicates
            $md5 = $this->fileValidation->calculateMd5($tempFile);
            $duplicate = $this->fileValidation->checkDuplicate($md5, $this->storage->getStorageStats()['storage_path']);

            if ($duplicate) {
                unlink($tempFile);
                $this->chunkStorage->cleanupChunks($uploadId);
                
                $this->logger->info('Duplicate file detected', [
                    'upload_id' => $uploadId,
                    'md5' => $md5,
                    'existing_file' => $duplicate
                ]);

                return $this->json([
                    'message' => 'File already exists',
                    'file_path' => $duplicate,
                    'md5' => $md5,
                    'is_duplicate' => true
                ], Response::HTTP_OK);
            }

            // Store file
            $storedPath = $this->storage->storeFile($tempFile, $status['filename'], $userId);

            // Cleanup chunks
            $this->chunkStorage->cleanupChunks($uploadId);

            $this->logger->info('Upload finalized', [
                'upload_id' => $uploadId,
                'filename' => $status['filename'],
                'stored_path' => $storedPath,
                'md5' => $md5,
                'client_ip' => $request->getClientIp()
            ]);

            return $this->json([
                'message' => 'Upload completed successfully',
                'file_path' => $storedPath,
                'filename' => $status['filename'],
                'file_size' => $status['file_size'],
                'md5' => $md5,
                'is_duplicate' => false
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('Failed to finalize upload', [
                'upload_id' => $uploadId ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'error' => 'Failed to finalize upload',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/status/{uploadId}', name: 'status', methods: ['GET'])]
    public function getUploadStatus(string $uploadId): JsonResponse
    {
        try {
            $status = $this->chunkStorage->getUploadStatus($uploadId);

            if (!$status) {
                return $this->json([
                    'error' => 'Upload not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $progress = count($status['uploaded_chunks']) / $status['total_chunks'] * 100;

            return $this->json([
                'upload_id' => $uploadId,
                'filename' => $status['filename'],
                'file_size' => $status['file_size'],
                'mime_type' => $status['mime_type'],
                'total_chunks' => $status['total_chunks'],
                'uploaded_chunks' => count($status['uploaded_chunks']),
                'missing_chunks' => array_values(array_diff(
                    range(0, $status['total_chunks'] - 1),
                    $status['uploaded_chunks']
                )),
                'progress' => round($progress, 2),
                'status' => $status['status'],
                'created_at' => $status['created_at']
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get upload status', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to get upload status'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/cancel/{uploadId}', name: 'cancel', methods: ['DELETE'])]
    public function cancelUpload(string $uploadId): JsonResponse
    {
        try {
            $status = $this->chunkStorage->getUploadStatus($uploadId);

            if (!$status) {
                return $this->json([
                    'error' => 'Upload not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $this->chunkStorage->cleanupChunks($uploadId);

            $this->logger->info('Upload cancelled', [
                'upload_id' => $uploadId,
                'filename' => $status['filename']
            ]);

            return $this->json([
                'message' => 'Upload cancelled successfully'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('Failed to cancel upload', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Failed to cancel upload'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

