<?php

declare(strict_types=1);

namespace App\Services\Platform\EventBus;

/**
 * Sprint 25 — canonical catalog of platform domain event names.
 *
 * These are the ONLY event names the Platform Event Bus recognizes. Every platform service publishes
 * one of these; modules subscribe through handlers (no direct module-to-module calls, no module
 * branching). Keeping the names here means publishers and subscribers share one vocabulary.
 */
final class PlatformEventNames
{
    public const ALERT_CREATED = 'AlertCreated';
    public const ALERT_RESOLVED = 'AlertResolved';
    public const ASSET_CREATED = 'AssetCreated';
    public const ASSET_UPDATED = 'AssetUpdated';
    public const WORKFLOW_STARTED = 'WorkflowStarted';
    public const WORKFLOW_COMPLETED = 'WorkflowCompleted';
    public const WORKFLOW_FAILED = 'WorkflowFailed';
    public const INCIDENT_OPENED = 'IncidentOpened';
    public const INCIDENT_UPDATED = 'IncidentUpdated';
    public const INCIDENT_RESOLVED = 'IncidentResolved';
    public const TICKET_CREATED = 'TicketCreated';
    public const TICKET_UPDATED = 'TicketUpdated';
    public const KNOWLEDGE_CREATED = 'KnowledgeCreated';
    public const KNOWLEDGE_UPDATED = 'KnowledgeUpdated';
    public const CONVERSATION_COMPLETED = 'ConversationCompleted';
    public const RECOMMENDATION_ACCEPTED = 'RecommendationAccepted';
    public const RECOMMENDATION_REJECTED = 'RecommendationRejected';
    public const APPROVAL_GRANTED = 'ApprovalGranted';
    public const APPROVAL_REJECTED = 'ApprovalRejected';
    public const NOTIFICATION_SENT = 'NotificationSent';
    public const COMPLIANCE_ASSESSMENT_COMPLETED = 'ComplianceAssessmentCompleted';
    public const AGENT_REVOKED = 'AgentRevoked';
    public const HOST_MONITORING_DISABLED = 'HostMonitoringDisabled';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ALERT_CREATED,
            self::ALERT_RESOLVED,
            self::ASSET_CREATED,
            self::ASSET_UPDATED,
            self::WORKFLOW_STARTED,
            self::WORKFLOW_COMPLETED,
            self::WORKFLOW_FAILED,
            self::INCIDENT_OPENED,
            self::INCIDENT_UPDATED,
            self::INCIDENT_RESOLVED,
            self::TICKET_CREATED,
            self::TICKET_UPDATED,
            self::KNOWLEDGE_CREATED,
            self::KNOWLEDGE_UPDATED,
            self::CONVERSATION_COMPLETED,
            self::RECOMMENDATION_ACCEPTED,
            self::RECOMMENDATION_REJECTED,
            self::APPROVAL_GRANTED,
            self::APPROVAL_REJECTED,
            self::NOTIFICATION_SENT,
            self::COMPLIANCE_ASSESSMENT_COMPLETED,
            self::AGENT_REVOKED,
            self::HOST_MONITORING_DISABLED,
        ];
    }

    public static function isKnown(string $name): bool
    {
        return in_array($name, self::all(), true);
    }
}
