<?php
namespace AdGuard;

require_once __DIR__ . '/Telemetry/RequestTelemetry.php';
require_once __DIR__ . '/Telemetry/ResponseTelemetry.php';
require_once __DIR__ . '/Telemetry/TelemetryEvent.php';
require_once __DIR__ . '/Storage/LocalEventStore.php';

use AdGuard\Storage\LocalEventStore;
use AdGuard\Telemetry\RequestTelemetry;
use AdGuard\Telemetry\ResponseTelemetry;
use AdGuard\Telemetry\TelemetryEvent;

/** Coordinates one bounded schema 4 telemetry event for one active request. */
class DecisionLogger
{
    private $config;
    private $store;
    private $requestStarts = array();
    private $lastWriteMetrics = array(
        'written' => false,
        'dropped' => false,
        'reason' => 'not_attempted',
        'duration_ms' => 0.0,
    );

    public function __construct(Config $config, $store = null)
    {
        $this->config = $config;
        $this->store = $store === null ? new LocalEventStore($config) : $store;
    }

    /** Capture request evidence before the mutating risk provider runs. */
    public function beginRequest($server = null, $cookies = null, $startedAt = null)
    {
        if (!$this->config->get('logging.enabled', true)) {
            return null;
        }
        try {
            $request = new RequestTelemetry($this->config, $server, $cookies, $startedAt);
            $event = new TelemetryEvent($request, $request->projectId());
            $this->requestStarts[spl_object_hash($event)] = $request->startedAt();
            return $event;
        } catch (\Exception $exception) {
            $this->lastWriteMetrics = $this->droppedResult('request_capture_failed');
            return null;
        }
    }

    /** Complete and append the same event once. Storage outcomes never escape. */
    public function completeRequest($event, $decision, $meta, $status, $durationMs, $bytes, $guardDurationMs)
    {
        if (!($event instanceof TelemetryEvent)) {
            $this->lastWriteMetrics = $this->droppedResult('missing_request_event');
            return false;
        }

        $allowed = is_array($decision) && !empty($decision['ads_allowed']);
        $sampleRate = (float)$this->config->get(
            $allowed ? 'logging.allow_sample_rate' : 'logging.deny_sample_rate',
            1.0
        );
        $sampleRate = max(0.0, min(1.0, $sampleRate));
        if ($sampleRate <= 0.0 || ($sampleRate < 1.0 && $this->randomUnit() > $sampleRate)) {
            $this->lastWriteMetrics = $this->droppedResult('sampled_out');
            return false;
        }

        $meta = is_array($meta) ? $meta : array();
        $meta['sample_rate'] = $sampleRate;
        $response = new ResponseTelemetry($decision, $meta, $status, $durationMs, $bytes, $guardDurationMs);
        if (!$event->complete(
            $response,
            $this->config->get('telemetry.agent_version', ''),
            $this->config->get('telemetry.rule_version', '')
        )) {
            $this->lastWriteMetrics = $this->droppedResult('duplicate_completion');
            return false;
        }

        try {
            $result = $this->store->append($event->toArray());
            $this->lastWriteMetrics = is_array($result) ? $result : $this->droppedResult('invalid_store_result');
        } catch (\Exception $exception) {
            $this->lastWriteMetrics = $this->droppedResult('store_exception');
        }
        unset($this->requestStarts[spl_object_hash($event)]);
        return !empty($this->lastWriteMetrics['written']);
    }

    /** Compatibility entry point for existing adapters; all new writes are schema 4. */
    public function log($decision, $meta)
    {
        $event = $this->beginRequest();
        if (!($event instanceof TelemetryEvent)) {
            return false;
        }
        $key = spl_object_hash($event);
        $startedAt = isset($this->requestStarts[$key]) ? $this->requestStarts[$key] : microtime(true);
        $status = http_response_code();
        $status = is_int($status) ? $status : null;
        $bytes = is_array($meta) && isset($meta['response_bytes']) && is_int($meta['response_bytes'])
            ? $meta['response_bytes']
            : null;
        return $this->completeRequest(
            $event,
            $decision,
            $meta,
            $status,
            max(0.0, (microtime(true) - $startedAt) * 1000.0),
            $bytes,
            null
        );
    }

    public function getLastWriteMetrics()
    {
        return $this->lastWriteMetrics;
    }

    private function droppedResult($reason)
    {
        return array(
            'written' => false,
            'dropped' => true,
            'reason' => substr((string)$reason, 0, 64),
            'duration_ms' => 0.0,
        );
    }

    private function randomUnit()
    {
        return mt_rand() / mt_getrandmax();
    }
}
