<?php

namespace App\Enums\Ai;

/**
 * Capabilities an AI provider may advertise via supportedCapabilities(). Used by the registry
 * and orchestration layer to negotiate behavior without provider-specific code.
 */
enum AiCapability: string
{
    case Chat = 'chat';
    case Stream = 'stream';
    case Embeddings = 'embeddings';
    case Responses = 'responses';
    case StructuredJson = 'structured_json';
    case Citations = 'citations';
}
