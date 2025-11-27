<?php

namespace App\Controller;

use App\Service\StorageService;
use Predis\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/monitoring', name: 'monitoring_')]
class MonitoringController extends AbstractController
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly ClientInterface $redis
    ) {
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function getStats(): JsonResponse
    {
        try {
            // Storage statistics
            $storageStats = $this->storage->getStorageStats();

            // Active uploads
            $uploadKeys = $this->redis->keys('upload:*');
            $activeUploads = count($uploadKeys);

            $uploadDetails = [];
            foreach (array_slice($uploadKeys, 0, 10) as $key) {
                $data = json_decode($this->redis->get($key), true);
                if ($data) {
                    $progress = count($data['uploaded_chunks']) / $data['total_chunks'] * 100;
                    $uploadDetails[] = [
                        'upload_id' => $data['upload_id'],
                        'filename' => $data['filename'],
                        'progress' => round($progress, 2),
                        'status' => $data['status']
                    ];
                }
            }

            // Calculate success rate (simplified - in production, use dedicated metrics)
            $totalUploads = (int)$this->redis->get('metrics:total_uploads') ?: 0;
            $successfulUploads = (int)$this->redis->get('metrics:successful_uploads') ?: 0;
            $successRate = $totalUploads > 0 ? ($successfulUploads / $totalUploads) * 100 : 0;

            return $this->json([
                'storage' => $storageStats,
                'active_uploads' => $activeUploads,
                'upload_details' => $uploadDetails,
                'metrics' => [
                    'total_uploads' => $totalUploads,
                    'successful_uploads' => $successfulUploads,
                    'success_rate' => round($successRate, 2)
                ],
                'timestamp' => time()
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to retrieve stats',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function healthCheck(): JsonResponse
    {
        try {
            // Check Redis connection
            $this->redis->ping();
            $redisStatus = 'ok';
        } catch (\Exception $e) {
            $redisStatus = 'error: ' . $e->getMessage();
        }

        return $this->json([
            'status' => 'healthy',
            'services' => [
                'redis' => $redisStatus,
                'storage' => is_writable($this->storage->getStorageStats()['storage_path']) ? 'ok' : 'error'
            ],
            'timestamp' => time()
        ]);
    }
}

