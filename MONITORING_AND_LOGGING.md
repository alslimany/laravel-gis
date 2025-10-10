# Monitoring and Logging Guide

Comprehensive guide for monitoring and logging in Laravel WebGIS.

## Table of Contents

1. [Application Logging](#application-logging)
2. [Performance Monitoring](#performance-monitoring)
3. [Error Tracking](#error-tracking)
4. [Health Checks](#health-checks)
5. [Metrics Collection](#metrics-collection)
6. [Log Aggregation](#log-aggregation)
7. [Alerting](#alerting)
8. [Best Practices](#best-practices)

## Application Logging

### Log Channels

Configured in `config/logging.php`:

- **stack**: Default channel (uses multiple channels)
- **daily**: Daily rotating logs
- **single**: Single log file
- **gis**: GIS-specific operations
- **performance**: Performance metrics
- **geoserver**: GeoServer integration logs
- **import**: Data import operations
- **slack**: Critical errors to Slack
- **syslog**: System logging

### Using Log Channels

```php
use Illuminate\Support\Facades\Log;

// Default logging
Log::info('User created', ['user_id' => $user->id]);

// GIS operations
Log::channel('gis')->info('Spatial query executed', [
    'query' => $query,
    'duration' => $duration,
]);

// Performance logging
Log::channel('performance')->info('Slow query detected', [
    'query' => $sql,
    'duration' => $time,
    'threshold' => 1000,
]);

// GeoServer operations
Log::channel('geoserver')->info('Layer published', [
    'layer' => $layer->name,
    'workspace' => $workspace,
]);

// Data import
Log::channel('import')->info('Import completed', [
    'import_id' => $import->id,
    'features' => $count,
]);
```

### Log Levels

- **debug**: Detailed information for debugging
- **info**: Informational messages
- **notice**: Normal but significant events
- **warning**: Warning messages
- **error**: Error messages
- **critical**: Critical conditions
- **alert**: Action must be taken immediately
- **emergency**: System is unusable

### Contextual Logging

```php
// Add context to all logs in request
Log::withContext([
    'user_id' => auth()->id(),
    'organization_id' => auth()->user()->organization_id,
    'ip' => request()->ip(),
]);

// Now all logs will include this context
Log::info('Action performed');
```

### Structured Logging

```php
Log::info('Spatial analysis completed', [
    'type' => 'buffer',
    'layer_id' => $layer->id,
    'geometry_type' => $geometryType,
    'distance' => $distance,
    'unit' => $unit,
    'duration_ms' => $duration,
    'result_count' => $resultCount,
]);
```

### Log File Locations

```
storage/logs/
├── laravel.log           # Main application log
├── gis.log              # GIS operations
├── performance.log       # Performance metrics
├── geoserver.log        # GeoServer integration
├── import.log           # Data imports
└── laravel-YYYY-MM-DD.log  # Daily logs
```

## Performance Monitoring

### Query Performance

Enable query logging in development:

```php
// In AppServiceProvider boot method
DB::listen(function ($query) {
    if ($query->time > 1000) { // Log queries over 1 second
        Log::channel('performance')->warning('Slow query', [
            'sql' => $query->sql,
            'bindings' => $query->bindings,
            'time' => $query->time,
        ]);
    }
});
```

### Request Performance

Create middleware for request timing:

```php
// app/Http/Middleware/LogRequestDuration.php
public function handle($request, Closure $next)
{
    $start = microtime(true);
    
    $response = $next($request);
    
    $duration = (microtime(true) - $start) * 1000;
    
    if ($duration > 2000) { // Log slow requests
        Log::channel('performance')->warning('Slow request', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'duration_ms' => $duration,
            'user_id' => auth()->id(),
        ]);
    }
    
    return $response;
}
```

### Memory Usage

```php
Log::channel('performance')->info('Memory usage', [
    'current' => memory_get_usage(true),
    'peak' => memory_get_peak_usage(true),
    'limit' => ini_get('memory_limit'),
]);
```

### Spatial Query Performance

```php
Log::channel('gis')->info('Spatial query executed', [
    'operation' => 'ST_Within',
    'table' => 'projects',
    'rows_examined' => $count,
    'duration_ms' => $duration,
    'index_used' => true,
]);
```

## Error Tracking

### Sentry Integration

Install Sentry SDK:

```bash
composer require sentry/sentry-laravel
```

Configure in `.env`:

```env
SENTRY_LARAVEL_DSN=https://your-dsn@sentry.io/project
SENTRY_TRACES_SAMPLE_RATE=0.2
```

Update exception handler:

```php
// app/Exceptions/Handler.php
public function register(): void
{
    $this->reportable(function (Throwable $e) {
        if (app()->bound('sentry')) {
            app('sentry')->captureException($e);
        }
    });
}
```

### Custom Error Context

```php
\Sentry\configureScope(function (\Sentry\State\Scope $scope): void {
    $scope->setUser([
        'id' => auth()->id(),
        'email' => auth()->user()->email,
        'organization' => auth()->user()->organization->name,
    ]);
    
    $scope->setTag('environment', app()->environment());
    $scope->setTag('version', config('app.version'));
});
```

### Error Notifications

Configure Slack notifications in `.env`:

```env
LOG_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
```

Send critical errors to Slack:

```php
Log::channel('slack')->critical('Critical error occurred', [
    'error' => $exception->getMessage(),
    'trace' => $exception->getTraceAsString(),
]);
```

### Error Response Formatting

```php
// app/Exceptions/Handler.php
public function render($request, Throwable $e)
{
    if ($request->expectsJson()) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'error_id' => \Illuminate\Support\Str::uuid(),
        ], $this->getStatusCode($e));
    }
    
    return parent::render($request, $e);
}
```

## Health Checks

### Basic Health Check

```bash
curl https://your-domain.com/health
```

Response:
```json
{
  "status": "healthy",
  "timestamp": "2025-10-09T23:41:24+00:00"
}
```

### Detailed Health Check

```bash
curl https://your-domain.com/health/detailed
```

Checks:
- Application status
- Database connectivity
- PostGIS availability
- Cache connection
- Queue connection

### Performance Metrics

```bash
curl https://your-domain.com/health/metrics
```

Returns:
- Memory usage
- Database connections
- Application uptime

### Automated Health Monitoring

Use external monitoring service:

**UptimeRobot**: Free tier available
```
Monitor URL: https://your-domain.com/health
Check interval: 5 minutes
```

**Pingdom**: Advanced monitoring
```
Monitor: /health/detailed
Alert on: status != 200
```

**New Relic**: APM integration
```
Synthetic monitoring
Transaction tracking
```

## Metrics Collection

### Prometheus Metrics

Install Laravel Prometheus exporter:

```bash
composer require ensi/laravel-prometheus
```

Configure metrics endpoint:

```php
// routes/web.php
Route::get('/metrics', function () {
    $registry = app(\Prometheus\CollectorRegistry::class);
    $renderer = new \Prometheus\RenderTextFormat();
    return response($renderer->render($registry->getMetricFamilySamples()))
        ->header('Content-Type', \Prometheus\RenderTextFormat::MIME_TYPE);
});
```

### Custom Metrics

```php
use Prometheus\CollectorRegistry;

$registry = app(CollectorRegistry::class);

// Counter
$counter = $registry->getOrRegisterCounter(
    'app',
    'spatial_queries_total',
    'Total spatial queries executed',
    ['type']
);
$counter->inc(['type' => 'buffer']);

// Histogram
$histogram = $registry->getOrRegisterHistogram(
    'app',
    'query_duration_seconds',
    'Query duration in seconds',
    ['query_type']
);
$histogram->observe($duration, ['query_type' => 'spatial']);

// Gauge
$gauge = $registry->getOrRegisterGauge(
    'app',
    'active_users',
    'Number of active users'
);
$gauge->set($activeUsers);
```

### Business Metrics

Track important business metrics:

```php
// Track imports
Log::channel('performance')->info('import_completed', [
    'metric' => 'import_count',
    'value' => 1,
    'features' => $count,
]);

// Track layer views
Log::channel('performance')->info('layer_viewed', [
    'metric' => 'layer_views',
    'layer_id' => $layer->id,
    'user_id' => auth()->id(),
]);

// Track spatial queries
Log::channel('performance')->info('spatial_query', [
    'metric' => 'query_count',
    'type' => 'buffer',
    'duration_ms' => $duration,
]);
```

## Log Aggregation

### ELK Stack (Elasticsearch, Logstash, Kibana)

Docker Compose configuration:

```yaml
elasticsearch:
  image: docker.elastic.co/elasticsearch/elasticsearch:8.11.0
  environment:
    - discovery.type=single-node
  ports:
    - "9200:9200"
  volumes:
    - elasticsearch-data:/usr/share/elasticsearch/data

logstash:
  image: docker.elastic.co/logstash/logstash:8.11.0
  volumes:
    - ./docker/logstash/pipeline:/usr/share/logstash/pipeline
  depends_on:
    - elasticsearch

kibana:
  image: docker.elastic.co/kibana/kibana:8.11.0
  ports:
    - "5601:5601"
  depends_on:
    - elasticsearch
```

Logstash pipeline configuration:

```conf
# docker/logstash/pipeline/laravel.conf
input {
  file {
    path => "/var/www/html/storage/logs/*.log"
    start_position => "beginning"
    codec => json
  }
}

filter {
  json {
    source => "message"
  }
  
  date {
    match => ["timestamp", "ISO8601"]
    target => "@timestamp"
  }
}

output {
  elasticsearch {
    hosts => ["elasticsearch:9200"]
    index => "laravel-logs-%{+YYYY.MM.dd}"
  }
}
```

### Graylog

Docker setup:

```yaml
graylog:
  image: graylog/graylog:5.0
  environment:
    - GRAYLOG_PASSWORD_SECRET=your_secret
    - GRAYLOG_ROOT_PASSWORD_SHA2=your_password_hash
    - GRAYLOG_HTTP_EXTERNAL_URI=http://localhost:9000/
  ports:
    - "9000:9000"     # Web interface
    - "12201:12201"   # GELF TCP
    - "1514:1514"     # Syslog TCP
  volumes:
    - graylog-data:/usr/share/graylog/data
```

Configure Laravel to send logs to Graylog:

```bash
composer require graylog2/gelf-php
```

### Cloud Logging

**AWS CloudWatch**:
```bash
composer require aws/aws-sdk-php
```

**Google Cloud Logging**:
```bash
composer require google/cloud-logging
```

**Azure Monitor**:
```bash
composer require microsoft/azure-storage-common
```

## Alerting

### Alert Conditions

Configure alerts for:

1. **Application Errors**
   - Critical exceptions
   - High error rate
   - Database connection failures

2. **Performance Issues**
   - Slow queries (>2s)
   - High memory usage (>90%)
   - Response time >3s

3. **Resource Issues**
   - Disk space low (<10%)
   - High CPU usage (>80%)
   - Queue backlog growing

4. **Security Issues**
   - Failed login attempts
   - Unauthorized access attempts
   - Suspicious activity patterns

### Slack Alerts

Configure webhook in `.env`:

```env
LOG_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK
```

Custom alert function:

```php
function sendAlert($level, $message, $context = [])
{
    Log::channel('slack')->{$level}($message, $context);
    
    // Also log locally
    Log::{$level}($message, $context);
}
```

### Email Alerts

Configure mail alerts:

```php
// app/Notifications/CriticalErrorNotification.php
class CriticalErrorNotification extends Notification
{
    public function via($notifiable)
    {
        return ['mail', 'slack'];
    }
    
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->error()
            ->subject('Critical Error Detected')
            ->line('A critical error has occurred in the application.')
            ->line('Error: ' . $this->error)
            ->action('View Logs', url('/admin/logs'));
    }
}
```

### PagerDuty Integration

For 24/7 on-call alerts:

```bash
composer require incident-io/pagerduty-php
```

```php
$client = new PagerDuty\Client($apiKey);
$client->trigger([
    'routing_key' => $routingKey,
    'event_action' => 'trigger',
    'payload' => [
        'summary' => 'Critical error in production',
        'severity' => 'critical',
        'source' => 'laravel-webgis',
    ],
]);
```

## Best Practices

### 1. Structured Logging

Always include context:

```php
Log::info('Operation completed', [
    'user_id' => $user->id,
    'organization_id' => $organization->id,
    'operation' => 'buffer_analysis',
    'duration_ms' => $duration,
    'result_count' => $results->count(),
]);
```

### 2. Log Sampling

For high-volume operations, sample logs:

```php
if (rand(1, 100) <= 10) { // 10% sampling
    Log::info('High-volume operation', $context);
}
```

### 3. Sensitive Data

Never log sensitive information:

```php
// Bad
Log::info('User login', ['password' => $password]);

// Good
Log::info('User login', [
    'user_id' => $user->id,
    'success' => true,
]);
```

### 4. Log Rotation

Configure daily log rotation:

```php
'daily' => [
    'driver' => 'daily',
    'path' => storage_path('logs/laravel.log'),
    'level' => 'debug',
    'days' => 14, // Keep 14 days
],
```

### 5. Performance Impact

Minimize logging overhead:

```php
// Use lazy evaluation
Log::debug('Debug info', fn() => $this->expensiveOperation());

// Disable in production if needed
if (app()->environment('local')) {
    Log::debug('Debug info', $context);
}
```

### 6. Correlation IDs

Add request ID to all logs:

```php
// Middleware
public function handle($request, Closure $next)
{
    $requestId = Str::uuid();
    Log::withContext(['request_id' => $requestId]);
    
    $response = $next($request);
    
    $response->header('X-Request-ID', $requestId);
    return $response;
}
```

### 7. Error Context

Include helpful context in errors:

```php
try {
    $this->processImport($file);
} catch (\Exception $e) {
    Log::error('Import failed', [
        'error' => $e->getMessage(),
        'file' => $file->getClientOriginalName(),
        'size' => $file->getSize(),
        'mime' => $file->getMimeType(),
        'user_id' => auth()->id(),
        'trace' => $e->getTraceAsString(),
    ]);
    throw $e;
}
```

### 8. Monitoring Dashboards

Create dashboards for:
- Request rate and response times
- Error rates by type
- Database query performance
- Queue processing metrics
- GIS operation statistics
- Import success/failure rates

### 9. Regular Review

Schedule regular log reviews:
- Weekly: Check for patterns
- Monthly: Analyze trends
- Quarterly: Update alert thresholds

### 10. Documentation

Document your logging strategy:
- What is logged and why
- Alert conditions and responses
- Log retention policies
- Access controls and compliance

## Resources

- [Laravel Logging Documentation](https://laravel.com/docs/logging)
- [Monolog Documentation](https://github.com/Seldaek/monolog)
- [Sentry Documentation](https://docs.sentry.io/platforms/php/guides/laravel/)
- [Prometheus Documentation](https://prometheus.io/docs/)
- [ELK Stack Guide](https://www.elastic.co/guide/)
