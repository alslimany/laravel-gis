<?php

namespace Tests\Feature;

use App\Jobs\DeleteLayerFromGeoServer;
use App\Jobs\PublishLayerToGeoServer;
use App\Jobs\UpdateLayerStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeoServerJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_layer_job_can_be_dispatched(): void
    {
        Queue::fake();

        PublishLayerToGeoServer::dispatch(
            'test_workspace',
            'test_datastore',
            'test_table',
            ['title' => 'Test Layer']
        );

        Queue::assertPushed(PublishLayerToGeoServer::class, function ($job) {
            return $job->workspace === 'test_workspace' &&
                   $job->datastore === 'test_datastore' &&
                   $job->tableName === 'test_table' &&
                   $job->options['title'] === 'Test Layer';
        });
    }

    public function test_delete_layer_job_can_be_dispatched(): void
    {
        Queue::fake();

        DeleteLayerFromGeoServer::dispatch(
            'test_workspace',
            'test_datastore',
            'test_layer',
            true
        );

        Queue::assertPushed(DeleteLayerFromGeoServer::class, function ($job) {
            return $job->workspace === 'test_workspace' &&
                   $job->datastore === 'test_datastore' &&
                   $job->layerName === 'test_layer' &&
                   $job->recurse === true;
        });
    }

    public function test_update_layer_style_job_can_be_dispatched(): void
    {
        Queue::fake();

        $sldContent = '<?xml version="1.0"?><SLD>test</SLD>';

        UpdateLayerStyle::dispatch(
            'test_workspace',
            'test_layer',
            'test_style',
            $sldContent
        );

        Queue::assertPushed(UpdateLayerStyle::class, function ($job) use ($sldContent) {
            return $job->workspace === 'test_workspace' &&
                   $job->layerName === 'test_layer' &&
                   $job->styleName === 'test_style' &&
                   $job->sldContent === $sldContent;
        });
    }

    public function test_publish_layer_job_has_correct_retry_configuration(): void
    {
        $job = new PublishLayerToGeoServer('workspace', 'datastore', 'table');

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(10, $job->backoff);
    }

    public function test_delete_layer_job_has_correct_retry_configuration(): void
    {
        $job = new DeleteLayerFromGeoServer('workspace', 'datastore', 'layer');

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(60, $job->timeout);
        $this->assertEquals(5, $job->backoff);
    }

    public function test_update_layer_style_job_has_correct_retry_configuration(): void
    {
        $job = new UpdateLayerStyle('workspace', 'layer', 'style');

        $this->assertEquals(5, $job->tries);
        $this->assertEquals(60, $job->timeout);
        $this->assertEquals(10, $job->backoff);
    }
}
