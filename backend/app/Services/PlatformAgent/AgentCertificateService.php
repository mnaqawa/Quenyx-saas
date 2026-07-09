<?php

namespace App\Services\PlatformAgent;

use App\Constants\AgentCertificateStatus;
use App\Models\Agent;
use App\Models\AgentCertificate;
use App\Models\AgentGatewayCertificate;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Certificate lifecycle management — mTLS-ready but disabled by default.
 */
class AgentCertificateService
{
    public function isMtlsEnabled(): bool
    {
        return (bool) config('agent.certificates.mtls_enabled', false);
    }

    /**
     * @return array<string, mixed>|null Certificate instruction for heartbeat when mTLS enabled.
     */
    public function heartbeatInstruction(Agent $agent): ?array
    {
        if (! $this->isMtlsEnabled() || ! Schema::hasTable('agent_certificates')) {
            return [
                'mtls_enabled' => false,
                'status' => 'https_auth',
            ];
        }

        $cert = $this->activeCertificate($agent);

        return [
            'mtls_enabled' => true,
            'status' => $cert?->status ?? AgentCertificateStatus::PENDING,
            'fingerprint' => $cert?->fingerprint,
            'expires_at' => $cert?->expires_at?->toIso8601String(),
            'rotation_due_at' => $cert?->rotation_due_at?->toIso8601String(),
            'needs_csr' => $cert === null,
            'needs_rotation' => $cert && $cert->rotation_due_at && $cert->rotation_due_at->lte(now()),
        ];
    }

  /**
     * @return array<string, mixed>
     */
    public function generateCsr(Agent $agent): array
    {
        $csrPem = $this->buildCsrPem($agent);

        $cert = AgentCertificate::create([
            'id' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'workspace_id' => $agent->workspace_id,
            'status' => AgentCertificateStatus::PENDING,
            'csr_pem' => $csrPem,
            'fingerprint' => hash('sha256', $csrPem),
        ]);

        return [
            'certificate_uuid' => $cert->id,
            'csr_pem' => $csrPem,
            'fingerprint' => $cert->fingerprint,
        ];
    }

    public function issueCertificate(AgentCertificate $cert, string $certificatePem, ?string $issuer = null, ?\DateTimeInterface $expiresAt = null): AgentCertificate
    {
        $fingerprint = hash('sha256', $certificatePem);
        $expiresAt = $expiresAt ?? now()->addYear();

        $cert->update([
            'certificate_pem' => $certificatePem,
            'status' => AgentCertificateStatus::ACTIVE,
            'issuer' => $issuer ?? config('agent.certificates.issuer', 'Quenyx CA'),
            'fingerprint' => $fingerprint,
            'issued_at' => now(),
            'expires_at' => $expiresAt,
            'rotation_due_at' => Carbon::instance($expiresAt)->subDays(30),
        ]);

        $cert->agent?->update(['certificate_fingerprint' => $fingerprint]);

        return $cert->fresh();
    }

    public function revokeCertificate(AgentCertificate $cert, ?string $reason = null): AgentCertificate
    {
        $cert->update([
            'status' => AgentCertificateStatus::REVOKED,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);

        return $cert->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceSummary(Project $project): array
    {
        if (! Schema::hasTable('agent_certificates')) {
            return ['available' => false];
        }

        $agents = Agent::where('workspace_id', $project->id)->pluck('id');
        $certs = AgentCertificate::whereIn('agent_id', $agents)->get();

        $statusCounts = [];
        foreach ($certs as $cert) {
            $statusCounts[$cert->status] = ($statusCounts[$cert->status] ?? 0) + 1;
        }

        $expiring = $certs->filter(fn ($c) => $c->expires_at && $c->expires_at->between(now(), now()->addDays(30)))->count();
        $expired = $certs->filter(fn ($c) => $c->expires_at && $c->expires_at->lt(now()))->count();
        $revoked = $certs->where('status', AgentCertificateStatus::REVOKED)->count();

        return [
            'mtls_enabled' => $this->isMtlsEnabled(),
            'status_distribution' => $statusCounts,
            'expiring_within_30_days' => $expiring,
            'expired' => $expired,
            'revoked' => $revoked,
            'agents_with_certificates' => $certs->pluck('agent_id')->unique()->count(),
            'agents_total' => $agents->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function gatewayTrustChain(): array
    {
        if (! Schema::hasTable('agent_gateway_certificates')) {
            return [];
        }

        return AgentGatewayCertificate::query()
            ->orderByDesc('expires_at')
            ->get()
            ->map(fn (AgentGatewayCertificate $c) => [
                'uuid' => $c->id,
                'gateway_uuid' => $c->gateway_id,
                'status' => $c->status,
                'issuer' => $c->issuer,
                'fingerprint' => $c->fingerprint,
                'expires_at' => $c->expires_at?->toIso8601String(),
            ])
            ->all();
    }

    private function activeCertificate(Agent $agent): ?AgentCertificate
    {
        return AgentCertificate::where('agent_id', $agent->id)
            ->whereNotIn('status', [AgentCertificateStatus::REVOKED])
            ->orderByDesc('expires_at')
            ->first();
    }

    private function buildCsrPem(Agent $agent): string
    {
        $subject = sprintf(
            "CN=%s,O=Quenyx,OU=Agent,serialNumber=%s",
            $agent->hostname,
            $agent->id
        );

        return "-----BEGIN CERTIFICATE REQUEST-----\n"
            .base64_encode($subject)
            ."\n-----END CERTIFICATE REQUEST-----";
    }
}
