<?php

namespace App\Ai\Agents;

use App\Ai\Tools\BufferAnalysisTool;
use App\Ai\Tools\ListFeaturesTool;
use App\Ai\Tools\SearchLayersTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Stringable;

class GisAssistant implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * @param  list<Message>  $history
     */
    public function __construct(
        public int $organizationId,
        public int $userId,
        public array $history = [],
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are a GIS assistant for an organization-scoped web GIS platform.
Use only the provided tools to search layers, list features, and run buffer analysis.
Never invent SQL, never request raw database access, and never invent layer IDs.
Prefer concise, actionable answers with layer names and counts when available.
If a tool fails or returns an error, explain the limitation clearly.
INSTRUCTIONS;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return $this->history;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return list<Agent|Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        return [
            new SearchLayersTool($this->organizationId),
            new ListFeaturesTool($this->organizationId),
            new BufferAnalysisTool($this->organizationId),
        ];
    }
}
