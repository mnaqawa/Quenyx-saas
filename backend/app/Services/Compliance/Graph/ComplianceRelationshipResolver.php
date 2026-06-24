<?php

namespace App\Services\Compliance\Graph;

use App\Models\Compliance\ComplianceControl;
use App\Models\Compliance\ComplianceDomain;
use App\Models\Compliance\ComplianceFrameworkRelease;
use App\Models\Compliance\ComplianceRequirement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pure structural navigation of the intra-framework corpus tree
 * (Domain → Control → Requirement, plus Control self-hierarchy).
 *
 * Returns Eloquent models / collections — it does NOT format responses, perform any AI
 * execution, vector search, or cross-framework resolution. All queries are release-scoped
 * (framework_release_id) and eager-load `sourceDocument` so the graph layer can emit
 * provenance without N+1 queries.
 */
class ComplianceRelationshipResolver
{
    /**
     * Parent domain chain ordered root → immediate parent (excludes the domain itself).
     *
     * @return list<ComplianceDomain>
     */
    public function parentDomainChain(ComplianceDomain $domain): array
    {
        $chain = [];
        $current = $domain;
        $guard = 0;

        while ($current->parent_domain_id !== null && $guard < 25) {
            $parent = ComplianceDomain::query()
                ->where('framework_release_id', $domain->framework_release_id)
                ->whereKey($current->parent_domain_id)
                ->with('sourceDocument')
                ->first();

            if ($parent === null) {
                break;
            }

            $chain[] = $parent;
            $current = $parent;
            $guard++;
        }

        return array_reverse($chain);
    }

    /**
     * Parent control chain ordered root → immediate parent (excludes the control itself).
     *
     * @return list<ComplianceControl>
     */
    public function parentControlChain(ComplianceControl $control): array
    {
        $chain = [];
        $current = $control;
        $guard = 0;

        while ($current->parent_control_id !== null && $guard < 25) {
            $parent = ComplianceControl::query()
                ->where('framework_release_id', $control->framework_release_id)
                ->whereKey($current->parent_control_id)
                ->with('sourceDocument')
                ->first();

            if ($parent === null) {
                break;
            }

            $chain[] = $parent;
            $current = $parent;
            $guard++;
        }

        return array_reverse($chain);
    }

    public function domainOfControl(ComplianceControl $control): ?ComplianceDomain
    {
        if ($control->domain_id === null) {
            return null;
        }

        return ComplianceDomain::query()
            ->where('framework_release_id', $control->framework_release_id)
            ->whereKey($control->domain_id)
            ->with('sourceDocument')
            ->first();
    }

    public function controlOfRequirement(ComplianceRequirement $requirement): ?ComplianceControl
    {
        if ($requirement->control_id === null) {
            return null;
        }

        return ComplianceControl::query()
            ->where('framework_release_id', $requirement->framework_release_id)
            ->whereKey($requirement->control_id)
            ->with('sourceDocument')
            ->first();
    }

    /**
     * @return Collection<int, ComplianceControl>
     */
    public function controlsOfDomain(ComplianceDomain $domain): Collection
    {
        return ComplianceControl::query()
            ->where('framework_release_id', $domain->framework_release_id)
            ->where('domain_id', $domain->id)
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, ComplianceControl>
     */
    public function childControls(ComplianceControl $control): Collection
    {
        return ComplianceControl::query()
            ->where('framework_release_id', $control->framework_release_id)
            ->where('parent_control_id', $control->id)
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, ComplianceRequirement>
     */
    public function requirementsOfControl(ComplianceControl $control): Collection
    {
        return ComplianceRequirement::query()
            ->where('framework_release_id', $control->framework_release_id)
            ->where('control_id', $control->id)
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * Controls sharing the same domain AND the same parent control (same tree level),
     * excluding the control itself.
     *
     * @return Collection<int, ComplianceControl>
     */
    public function siblingControls(ComplianceControl $control): Collection
    {
        return ComplianceControl::query()
            ->where('framework_release_id', $control->framework_release_id)
            ->where('domain_id', $control->domain_id)
            ->where(function ($query) use ($control): void {
                if ($control->parent_control_id === null) {
                    $query->whereNull('parent_control_id');
                } else {
                    $query->where('parent_control_id', $control->parent_control_id);
                }
            })
            ->whereKeyNot($control->id)
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * Requirements under the same control, excluding the requirement itself.
     *
     * @return Collection<int, ComplianceRequirement>
     */
    public function siblingRequirements(ComplianceRequirement $requirement): Collection
    {
        return ComplianceRequirement::query()
            ->where('framework_release_id', $requirement->framework_release_id)
            ->where('control_id', $requirement->control_id)
            ->whereKeyNot($requirement->id)
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, ComplianceDomain>
     */
    public function domainsOfRelease(ComplianceFrameworkRelease $release): Collection
    {
        return ComplianceDomain::query()
            ->where('framework_release_id', $release->id)
            ->with('sourceDocument')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    public function requirementCountForControl(ComplianceControl $control): int
    {
        return ComplianceRequirement::query()
            ->where('framework_release_id', $control->framework_release_id)
            ->where('control_id', $control->id)
            ->count();
    }

    public function childControlCount(ComplianceControl $control): int
    {
        return ComplianceControl::query()
            ->where('framework_release_id', $control->framework_release_id)
            ->where('parent_control_id', $control->id)
            ->count();
    }

    public function controlCountForDomain(ComplianceDomain $domain): int
    {
        return ComplianceControl::query()
            ->where('framework_release_id', $domain->framework_release_id)
            ->where('domain_id', $domain->id)
            ->count();
    }

    public function requirementCountForDomain(ComplianceDomain $domain): int
    {
        return DB::table('compliance_requirements as r')
            ->join('compliance_controls as c', 'c.id', '=', 'r.control_id')
            ->where('c.framework_release_id', $domain->framework_release_id)
            ->where('c.domain_id', $domain->id)
            ->count();
    }
}
