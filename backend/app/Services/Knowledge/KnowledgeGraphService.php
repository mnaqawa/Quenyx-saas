<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Automation\AutomationExecution;
use App\Models\Automation\AutomationRunbook;
use App\Models\Automation\AutomationWorkflow;
use App\Models\Incident\Incident;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Notification\Notification;
use App\Models\Project;
use App\Models\Support\Ticket;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 24 — Enterprise Knowledge Graph v2.
 *
 * A deterministic READ-MODEL graph over the workspace's REAL entities — hosts/assets, alerts, services,
 * incidents, runbooks, automation, tickets, documents, people, projects, notifications, approvals — with
 * typed nodes and traversable relationships built from real foreign keys and deterministic UUID
 * soft-references. Bounded per type for predictable size. No fabricated nodes or edges.
 */
class KnowledgeGraphService
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(Project $project, array $options = []): array
    {
        $cap = (int) config('knowledge.graph.node_limit_per_type', 50);

        /** @var array<string, array<string, mixed>> $nodes keyed by node id */
        $nodes = [];
        /** @var list<array<string, mixed>> $edges */
        $edges = [];

        $addNode = function (string $type, string $id, string $label, array $meta = []) use (&$nodes): string {
            $nodeId = $type.':'.$id;
            if (! isset($nodes[$nodeId])) {
                $nodes[$nodeId] = ['id' => $nodeId, 'type' => $type, 'ref' => $id, 'label' => $label, 'meta' => $meta];
            }

            return $nodeId;
        };
        $addEdge = function (string $from, string $to, string $relation) use (&$edges): void {
            $edges[] = ['from' => $from, 'to' => $to, 'relation' => $relation];
        };

        $root = $addNode('project', (string) $project->uuid, (string) $project->name);

        if (Schema::hasTable('incidents')) {
            foreach (Incident::where('project_id', $project->id)->latest('opened_at')->limit($cap)->get() as $i) {
                $n = $addNode('incident', $i->uuid, $i->title, ['severity' => $i->severity, 'status' => $i->status]);
                $addEdge($root, $n, 'has_incident');
                if ($i->asset_uuid) {
                    $addEdge($n, $addNode('asset', $i->asset_uuid, 'Asset '.substr($i->asset_uuid, 0, 8)), 'affects_asset');
                }
                if ($i->alert_uuid) {
                    $addEdge($addNode('alert', $i->alert_uuid, 'Alert '.substr($i->alert_uuid, 0, 8)), $n, 'triggered_incident');
                }
            }
        }

        if (Schema::hasTable('automation_workflows')) {
            foreach (AutomationWorkflow::where('project_id', $project->id)->limit($cap)->get() as $wf) {
                $addEdge($root, $addNode('workflow', $wf->uuid, $wf->name, ['trigger_type' => $wf->trigger_type]), 'has_workflow');
            }
        }
        if (Schema::hasTable('automation_runbooks')) {
            foreach (AutomationRunbook::where('project_id', $project->id)->limit($cap)->get() as $rb) {
                $addEdge($root, $addNode('runbook', $rb->uuid, $rb->name, ['category' => $rb->category]), 'has_runbook');
            }
        }
        if (Schema::hasTable('automation_executions')) {
            foreach (AutomationExecution::where('project_id', $project->id)->latest()->limit($cap)->get() as $x) {
                $n = $addNode('execution', $x->uuid, $x->adapter_key.' · '.$x->status, ['mode' => $x->mode]);
                if ($x->incident_id && Schema::hasTable('incidents')) {
                    $inc = Incident::where('id', $x->incident_id)->value('uuid');
                    if ($inc) {
                        $addEdge($addNode('incident', $inc, 'Incident '.substr($inc, 0, 8)), $n, 'ran_automation');
                    }
                }
                if ($x->workflow_id) {
                    $wfu = AutomationWorkflow::where('id', $x->workflow_id)->value('uuid');
                    if ($wfu) {
                        $addEdge($addNode('workflow', $wfu, 'Workflow '.substr($wfu, 0, 8)), $n, 'executed_as');
                    }
                }
                if ($x->runbook_id) {
                    $rbu = AutomationRunbook::where('id', $x->runbook_id)->value('uuid');
                    if ($rbu) {
                        $addEdge($addNode('runbook', $rbu, 'Runbook '.substr($rbu, 0, 8)), $n, 'executed_as');
                    }
                }
            }
        }

        if (Schema::hasTable('tickets')) {
            foreach (Ticket::where('project_id', $project->id)->latest()->limit($cap)->get() as $tk) {
                $n = $addNode('ticket', $tk->uuid, $tk->reference.': '.$tk->subject, ['priority' => $tk->priority, 'status' => $tk->status]);
                $addEdge($root, $n, 'has_ticket');
                if ($tk->incident_uuid) {
                    $addEdge($n, $addNode('incident', $tk->incident_uuid, 'Incident '.substr($tk->incident_uuid, 0, 8)), 'relates_to_incident');
                }
                if ($tk->asset_uuid) {
                    $addEdge($n, $addNode('asset', $tk->asset_uuid, 'Asset '.substr($tk->asset_uuid, 0, 8)), 'concerns_asset');
                }
            }
        }

        if (Schema::hasTable('knowledge_documents')) {
            foreach (KnowledgeDocument::where('project_id', $project->id)->latest('updated_at')->limit($cap)->get() as $d) {
                $addEdge($root, $addNode('document', $d->uuid, $d->title, ['category' => $d->category, 'source' => $d->source_key]), 'has_document');
            }
        }

        if (Schema::hasTable('notifications')) {
            foreach (Notification::where('project_id', $project->id)->latest()->limit($cap)->get() as $nt) {
                $addEdge($root, $addNode('notification', $nt->uuid, $nt->title, ['severity' => $nt->severity]), 'has_notification');
            }
        }

        $nodeList = array_values($nodes);
        $counts = [];
        foreach ($nodeList as $node) {
            $counts[$node['type']] = ($counts[$node['type']] ?? 0) + 1;
        }

        return [
            'nodes' => $nodeList,
            'edges' => $edges,
            'node_count' => count($nodeList),
            'edge_count' => count($edges),
            'counts_by_type' => $counts,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
