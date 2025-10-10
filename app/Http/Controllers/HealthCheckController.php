<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class HealthCheckController extends Controller
{
    /**
     * Basic health check endpoint
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Detailed health check with database and cache
     */
    public function detailed(): JsonResponse
    {
        $checks = [
            'app' => $this->checkApp(),
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
        ];

        $allHealthy = collect($checks)->every(fn($check) => $check['status'] === 'healthy');

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $allHealthy ? 200 : 503);
    }

    /**
     * Check application status
     */
    private function checkApp(): array
    {
        return [
            'status' => 'healthy',
            'environment' => app()->environment(),
            'debug' => config('app.debug'),
            'version' => config('app.version', '1.0.0'),
        ];
    }

    /**
     * Check database connection and PostGIS
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            
            // Check PostGIS
            $postgis = DB::select('SELECT PostGIS_Version() as version')[0] ?? null;
            
            return [
                'status' => 'healthy',
                'connection' => 'ok',
                'postgis' => $postgis ? 'enabled' : 'disabled',
                'version' => $postgis->version ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check cache connection
     */
    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . time();
            Cache::put($key, 'test', 10);
            $value = Cache::get($key);
            Cache::forget($key);
            
            return [
                'status' => $value === 'test' ? 'healthy' : 'unhealthy',
                'driver' => config('cache.default'),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check queue connection
     */
    private function checkQueue(): array
    {
        try {
            $connection = config('queue.default');
            
            if ($connection === 'redis') {
                Redis::connection()->ping();
            }
            
            return [
                'status' => 'healthy',
                'connection' => $connection,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get performance metrics
     */
    public function metrics(): JsonResponse
    {
        $memoryLimit = ini_get('memory_limit');
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'memory' => [
                'limit' => $memoryLimit,
                'usage' => $this->formatBytes($memoryUsage),
                'peak' => $this->formatBytes($memoryPeak),
                'usage_percentage' => round(($memoryUsage / $this->parseMemoryLimit($memoryLimit)) * 100, 2),
            ],
            'database' => [
                'connections' => DB::getConnections(),
            ],
            'uptime' => $this->getUptime(),
        ]);
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get application uptime (approximation)
     */
    private function getUptime(): string
    {
        $uptimeFile = storage_path('framework/uptime');
        
        if (!file_exists($uptimeFile)) {
            file_put_contents($uptimeFile, time());
        }
        
        $startTime = (int) file_get_contents($uptimeFile);
        $uptime = time() - $startTime;
        
        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        
        return "{$days}d {$hours}h {$minutes}m";
    }
}
