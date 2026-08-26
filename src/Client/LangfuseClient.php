<?php

namespace Langfuse\Client;

use DateTimeInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Langfuse\Config\Config;
use Langfuse\Event\GenerationEvent;
use Langfuse\Event\IngestionEvent;
use Langfuse\Event\TraceEvent;
use Langfuse\Event\SpanEvent;
use Langfuse\Model\Prompt;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;

class LangfuseClient
{
    private $httpClient;
    private $config;
    private $events = []; // Store events to send

    private ?TraceEvent $traceEvent = null;

    public function __construct(Config $config)
    {
        $this->config = $config;

        $baseUri = $config->get(Config::LANGFUSE_BASE_URI, 'https://cloud.langfuse.com/');
        $publicKey = $config->get(Config::PUBLIC_KEY);
        $secretKey = $config->get(Config::SECRET_KEY);

        $this->httpClient = new GuzzleClient(
            [
                'base_uri' => $baseUri,
                'auth' => [$publicKey, $secretKey], // Basic Auth
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string>         $tags     Langfuse trace tags (filterable in the UI)
     */
    public function startTrace(string $name, array $metadata, array $tags = [])
    {
        $id = Uuid::uuid4()->toString();
        $timestamp = (new \DateTime())->format(DateTimeInterface::ATOM);
        $this->traceEvent = new TraceEvent(
            $id,
            $timestamp,
            [
                'id' => $id,
                'name' => $name,
                'timestamp' => $timestamp,
                'userId' => $metadata['userId'] ?? null,
                'metadata' => $metadata,
                'tags' => array_values($tags),
                'public' => false,
            ]
        );
        $this->events[] = $this->traceEvent->toArray();
    }

    public function endTrace()
    {
        $this->sendEvents();
    }

    public function startGeneration(string $name, string $modelName, array $prompt): GenerationEvent
    {
        return GenerationEvent::create($this->traceEvent->getId(), $name, $prompt, $modelName);
    }

    public function endGeneration(GenerationEvent $event): void
    {
        $event->setEndTime();
        $this->events[] = $event->toArray();
    }

    /**
     * Get a prompt by name, with optional version or label
     *
     * @param string $promptName The name of the prompt
     * @param int|null $version Optional specific version number
     * @param string|null $label Optional label (defaults to "production" if not specified)
     * @return Prompt The prompt object
     * @throws RequestException If the API request fails
     */
    public function getPrompt(string $promptName, ?int $version = null, ?string $label = null): Prompt
    {
        $queryParams = [];
        
        if ($version !== null) {
            $queryParams['version'] = $version;
        }
        
        if ($label !== null) {
            $queryParams['label'] = $label;
        }
        
        try {
            $response = $this->httpClient->get('/api/public/v2/prompts/' . $promptName, [
                'query' => $queryParams,
            ]);
            
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException('Failed to fetch prompt: HTTP ' . $statusCode);
            }
            
            $body = (string) $response->getBody();
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Failed to decode prompt response: ' . json_last_error_msg());
            }
            
            return new Prompt($data);
        } catch (RequestException $e) {
            error_log('Langfuse prompt fetch error: ' . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Processes an intercepted request.
     *
     * @param RequestInterface $request
     */
//    public function processRequest(RequestInterface $request): void
//    {
//        // Build a TraceEvent or appropriate event
//        $event = $this->buildEventFromRequest($request);
//        if ($event !== null) {
//            $this->events[] = $event->toArray();
//            $this->sendEvents();
//        }
//    }

    /**
     * Processes an intercepted response.
     *
     * @param ResponseInterface $response
     * @param RequestInterface $request
     */
//    public function processResponse(ResponseInterface $response, RequestInterface $request): void
//    {
//        // Build a SpanEvent or appropriate event
//        $event = $this->buildEventFromResponse($response, $request);
//        if ($event !== null) {
//            $this->events[] = $event->toArray();
//            $this->sendEvents();
//        }
//    }

    /**
     * Builds an event from a request.
     *
     * @param RequestInterface $request
     * @return IngestionEvent|null
     */
//    private function buildEventFromRequest(RequestInterface $request): ?IngestionEvent
//    {
//        // Extract necessary information from the request to build the event
//        // For simplicity, we'll create a TraceEvent here
//
//        $eventId = $this->generateUuid();
//        $timestamp = (new \DateTime())->format(\DateTime::ATOM);
//
//        $event = new TraceEvent($eventId, $timestamp, [
//            'method' => $request->getMethod(),
//            'uri' => (string)$request->getUri(),
//            'headers' => $request->getHeaders(),
//            'body' => (string)$request->getBody(),
//        ]);
//
//        return $event;
//    }

    /**
     * Builds an event from a response.
     *
     * @param ResponseInterface $response
     * @param RequestInterface $request
     * @return IngestionEvent|null
     */
//    private function buildEventFromResponse(ResponseInterface $response, RequestInterface $request): ?IngestionEvent
//    {
//        // Extract necessary information from the response to build the event
//        // For simplicity, we'll create a SpanEvent here
//
//        $eventId = $this->generateUuid();
//        $timestamp = (new \DateTime())->format(\DateTime::ATOM);
//
//        $event = new SpanEvent($eventId, $timestamp, [
//            'status_code' => $response->getStatusCode(),
//            'reason_phrase' => $response->getReasonPhrase(),
//            'headers' => $response->getHeaders(),
//            'body' => (string)$response->getBody(),
//            'request_method' => $request->getMethod(),
//            'request_uri' => (string)$request->getUri(),
//        ]);
//
//        return $event;
//    }

    /**
     * Sends the accumulated events to Langfuse.
     */
    private function sendEvents(): void
    {
        if (empty($this->events)) {
            return;
        }

        $payload = [
            'batch' => $this->events,
        ];

        try {
            $response = $this->httpClient->post('/api/public/ingestion', [
                'json' => $payload,
            ]);

            $this->handleResponse($response);
            // Clear the events after successful send
            $this->events = [];
        } catch (RequestException $e) {
            // Log error or handle as needed
            error_log('Langfuse ingestion error: ' . $e->getMessage());
            // Decide whether to clear events or retry later
            $this->events = []; // Clearing to prevent duplication
        }
    }

    /**
     * Handles the API response.
     *
     * @param ResponseInterface $response
     */
    private function handleResponse(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200 && $statusCode !== 207) {
            $body = (string)$response->getBody();
            $data = json_decode($body, true);
            $message = $data['error'] ?? 'Unknown error';
            error_log('Langfuse API error: ' . $message);
        }
    }

    /**
     * Generates a UUID v4.
     *
     * @return string
     */
//    private function generateUuid(): string
//    {
//        return \Ramsey\Uuid\Uuid::uuid4()->toString();
//    }
}
