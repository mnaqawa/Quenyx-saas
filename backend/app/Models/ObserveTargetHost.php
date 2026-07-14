<?php

namespace App\Models;

use App\Constants\HostLifecycleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Observe target host (workspace-defined host to monitor).
 * Table: observe_targets_hosts (plural).
 */
class ObserveTargetHost extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** @var string */
    protected $table = 'observe_targets_hosts';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'name',
        'address',
        'public_ip',
        'ip_locked',
        'agent_id',
        'source',
        'check_command',
        'tags',
        'enabled',
        'lifecycle_status',
        'lifecycle_reason',
        'lifecycle_changed_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'enabled' => 'boolean',
        'ip_locked' => 'boolean',
        'lifecycle_changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ObserveTargetHost $host) {
            if (empty($host->uuid)) {
                $host->uuid = (string) Str::uuid();
            }
            if (empty($host->lifecycle_status)) {
                $host->lifecycle_status = HostLifecycleStatus::ACTIVE;
            }
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->newQuery()->where(function ($q) use ($value) {
            $q->where('uuid', $value);
            if (is_numeric($value)) {
                $q->orWhere('id', (int) $value);
            }
        });

        return $query->firstOrFail();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'workspace_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(ObserveTargetService::class, 'host_id');
    }

    public function portScans(): HasMany
    {
        return $this->hasMany(HostPortScan::class, 'host_id');
    }

    public function isMonitoringAllowed(): bool
    {
        $status = $this->lifecycle_status ?? HostLifecycleStatus::ACTIVE;

        return ! in_array($status, HostLifecycleStatus::monitoringBlocked(), true)
            && $this->deleted_at === null
            && ($this->enabled ?? true);
    }

    public function scopeActiveMonitoring($query)
    {
        return $query
            ->whereNull('deleted_at')
            ->whereIn('lifecycle_status', HostLifecycleStatus::countsAsActive())
            ->where('enabled', true);
    }

    public function scopeVisibleInList($query)
    {
        return $query
            ->whereNull('deleted_at')
            ->where('lifecycle_status', '!=', HostLifecycleStatus::DELETED);
    }

    /**
     * Host is linked to a Platform Agent (push telemetry — never SSH/pull).
     */
    public function isAgentEnrolled(): bool
    {
        return $this->agent_id !== null && $this->agent_id !== '';
    }

    /**
     * Address Quenyx should use to reach this host from the platform network.
     * Prefer public_ip when set (agent on another subnet/VPC); fall back to address.
     */
    public function reachableAddress(): string
    {
        $public = trim((string) ($this->public_ip ?? ''));
        if ($public !== '') {
            return $public;
        }

        return trim((string) ($this->address ?? ''));
    }

    /**
     * Resolve nmap target for black-box (public) or white-box (private) scans.
     *
     * @param  'public'|'private'|'auto'  $mode
     * @return array{ok: bool, address: string, mode: string, error: string|null}
     */
    public function resolveScanAddress(string $mode = 'auto'): array
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['public', 'private', 'auto'], true)) {
            $mode = 'auto';
        }

        $public = trim((string) ($this->public_ip ?? ''));
        $private = trim((string) ($this->address ?? ''));

        if ($mode === 'public') {
            if ($public === '') {
                return [
                    'ok' => false,
                    'address' => '',
                    'mode' => 'public',
                    'error' => 'No public IP configured for this host. Add a public IP in Hosts, or use a white-box (private IP) scan.',
                ];
            }

            return ['ok' => true, 'address' => $public, 'mode' => 'public', 'error' => null];
        }

        if ($mode === 'private') {
            if ($private === '') {
                return [
                    'ok' => false,
                    'address' => '',
                    'mode' => 'private',
                    'error' => 'No private IP/address configured for this host.',
                ];
            }

            return ['ok' => true, 'address' => $private, 'mode' => 'private', 'error' => null];
        }

        $address = $this->reachableAddress();
        if ($address === '') {
            return [
                'ok' => false,
                'address' => '',
                'mode' => 'auto',
                'error' => 'Host address is empty',
            ];
        }

        $resolvedMode = ($public !== '' && $address === $public) ? 'public' : 'private';

        return ['ok' => true, 'address' => $address, 'mode' => $resolvedMode, 'error' => null];
    }
}
