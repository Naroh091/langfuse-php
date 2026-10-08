<?php

namespace Langfuse\Event;

use DateTimeInterface;
use Ramsey\Uuid\Uuid;

class GenerationEvent extends IngestionEvent
{
    public function __construct(string $id, string $timestamp, array $body)
    {
        parent::__construct('generation-create', $id, $timestamp, $body);
    }

    public function setOutput($output): self
    {
        $this->body['output'] = $output;
        return $this;
    }

    public function setEndTime(): self
    {
        $this->body['endTime'] = (new \DateTime())->format(DateTimeInterface::ATOM);
        return $this;
    }

    public static function create(
        string $traceId,
        ?string $name = null,
        array $input = [],
        ?string $model = null
    ): GenerationEvent
    {
        $id = Uuid::uuid4()->toString();
        $timestamp = (new \DateTime())->format(DateTimeInterface::ATOM);
        return new static(
            $id,
            $timestamp,
            [
                'traceId' => $traceId,
                'name' => $name,
                'startTime' => $timestamp,
                'input' => $input,
                'output' => null, // use setOutput() to set
                'level' => 'DEFAULT',
                'statusMessage' => 'PENDING',
                'id' => $id,
                'endTime' => null, // use setEndTime() to set
                'completionStartTime' => $timestamp,
                'model' => $model,
//                'promptName' => $name, // makes some problems dunno why
                'promptVersion' => null,
            ]
        );
    }
}
