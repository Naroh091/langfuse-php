<?php

namespace Langfuse;

use Langfuse\Client\LangfuseClient;
use Langfuse\Model\Prompt;

class LangfuseManager
{

    public function __construct(
        private LangfuseClient $langfuseClient
    )
    {}

    /**
     * @param array<string, mixed> $metadata
     * @param list<string>         $tags     Langfuse trace tags (filterable in the UI)
     */
    public function withTrace(string $name, array $metadata, callable $callback, array $tags = []): mixed
    {
        $this->langfuseClient->startTrace($name, $metadata, $tags);
        $result = $callback();
        $this->langfuseClient->endTrace();

        return $result;
    }

    public function withGeneration(
        string $name,
        string $modelName,
        array $prompt,
        callable $callback
    ): mixed
    {
        $event = $this->langfuseClient->startGeneration($name, $modelName, $prompt);
        $result = $callback();
        $event->setOutput($result);
        $this->langfuseClient->endGeneration($event);

        return $result;
    }

    /**
     * Get a prompt by name, with optional version or label
     *
     * @param string $name The name of the prompt to retrieve
     * @param string|null $label Optional label (e.g., "production", "latest"). Defaults to "production" if not specified
     * @param int|null $version Optional specific version number. Usually not recommended as it requires code changes to deploy new prompt versions
     * @return Prompt The prompt object that can be compiled with variables
     * @throws \RuntimeException If the prompt cannot be fetched
     */
    public function getPrompt(string $name, ?string $label = null, ?int $version = null): Prompt
    {
        return $this->langfuseClient->getPrompt($name, $version, $label);
    }

}
