<?php

namespace App\Domain\Integrations;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Read-only CASE 1.1 views over the LMS's published competency framework records. */
final class CaseV1p1Provider
{
    /** @return array{CFDocuments: list<array<string, mixed>>} */
    public function documents(Tenant $tenant): array
    {
        $frameworks = DB::table('academic_competency_frameworks')
            ->where('tenant_id', $tenant->getKey())->where('status', 'published')->orderBy('id')->get();
        $identifiers = $this->identifiers($tenant, 'academic_competency_framework', $frameworks->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return ['CFDocuments' => $frameworks->map(fn ($framework): array => $this->document($tenant, $framework, $identifiers[(int) $framework->id]))->all()];
    }

    /** @return array<string, mixed> */
    public function documentBySourceId(Tenant $tenant, string $sourceId): array
    {
        $framework = $this->frameworkBySourceId($tenant, $sourceId);

        return $this->document($tenant, $framework);
    }

    /** @return array<string, mixed> */
    public function itemBySourceId(Tenant $tenant, string $sourceId): array
    {
        $record = DB::table('integration_external_identifiers')
            ->where('tenant_id', $tenant->getKey())->where('entity_type', 'academic_competency')->where('sourced_id', $sourceId)->first();
        abort_unless($record, 404);

        $item = DB::table('academic_competencies')->where('tenant_id', $tenant->getKey())->where('id', $record->entity_id)->where('status', 'active')->first();
        abort_unless($item, 404);
        $framework = DB::table('academic_competency_frameworks')->where('tenant_id', $tenant->getKey())->where('id', $item->framework_id)->where('status', 'published')->first();
        abort_unless($framework, 404);

        return $this->item($tenant, $framework, $item);
    }

    /** @return array<string, mixed> */
    public function packageBySourceId(Tenant $tenant, string $sourceId): array
    {
        $framework = $this->frameworkBySourceId($tenant, $sourceId);
        $frameworkId = $this->identifier($tenant, 'academic_competency_framework', (int) $framework->id);
        $items = DB::table('academic_competencies')->where('tenant_id', $tenant->getKey())->where('framework_id', $framework->id)->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get();
        $itemIdentifiers = $this->identifiers($tenant, 'academic_competency', $items->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return [
            'CFDocument' => array_diff_key($this->document($tenant, $framework, $frameworkId), ['CFPackageURI' => true]),
            'CFItems' => $items->map(fn ($item): array => array_diff_key($this->item($tenant, $framework, $item, $itemIdentifiers[(int) $item->id], $frameworkId), ['CFDocumentURI' => true]))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function document(Tenant $tenant, object $framework, string $identifier): array
    {
        $base = $this->base($tenant);
        $uri = $base.'/CFDocuments/'.$identifier;

        return [
            'identifier' => $identifier,
            'uri' => $uri,
            'creator' => $tenant->name,
            'title' => $framework->title,
            'lastChangeDateTime' => Carbon::parse($framework->updated_at)->toIso8601String(),
            'CFPackageURI' => ['title' => $framework->title.' package', 'identifier' => $identifier, 'uri' => $base.'/CFPackages/'.$identifier],
            'caseVersion' => '1.1',
            'frameworkType' => 'Competency',
            'description' => $framework->description,
            'version' => (string) $framework->version_number,
            'adoptionStatus' => $framework->status,
        ];
    }

    /** @return array<string, mixed> */
    private function item(Tenant $tenant, object $framework, object $item, ?string $identifier = null, ?string $documentId = null): array
    {
        $identifier ??= $this->identifier($tenant, 'academic_competency', (int) $item->id);
        $documentId ??= $this->identifier($tenant, 'academic_competency_framework', (int) $framework->id);
        $base = $this->base($tenant);
        $uri = $base.'/CFItems/'.$identifier;

        return [
            'identifier' => $identifier,
            'fullStatement' => $item->descriptor,
            'uri' => $uri,
            'lastChangeDateTime' => Carbon::parse($item->updated_at)->toIso8601String(),
            'CFDocumentURI' => ['title' => $framework->title, 'identifier' => $documentId, 'uri' => $base.'/CFDocuments/'.$documentId],
            'humanCodingScheme' => $item->code,
            'abbreviatedStatement' => $item->title,
            'notes' => $item->mastery_criteria,
            'educationLevel' => $item->level_label ? [$item->level_label] : [],
        ];
    }

    private function frameworkBySourceId(Tenant $tenant, string $sourceId): object
    {
        $record = DB::table('integration_external_identifiers')->where('tenant_id', $tenant->getKey())->where('entity_type', 'academic_competency_framework')->where('sourced_id', $sourceId)->first();
        abort_unless($record, 404);

        return DB::table('academic_competency_frameworks')->where('tenant_id', $tenant->getKey())->where('id', $record->entity_id)->where('status', 'published')->firstOrFail();
    }

    private function identifier(Tenant $tenant, string $type, int $entityId): string
    {
        return $this->identifiers($tenant, $type, [$entityId])[$entityId];
    }

    /** @param list<int> $entityIds @return array<int, string> */
    private function identifiers(Tenant $tenant, string $type, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }
        $query = fn () => DB::table('integration_external_identifiers')->where('tenant_id', $tenant->getKey())->where('entity_type', $type)->whereIn('entity_id', $entityIds)->pluck('sourced_id', 'entity_id')->map(fn ($id): string => (string) $id)->all();
        $identifiers = $query();
        $now = now();
        $missing = array_values(array_diff($entityIds, array_map('intval', array_keys($identifiers))));
        if ($missing !== []) {
            $rows = array_map(fn (int $entityId): array => [
                'tenant_id' => $tenant->getKey(), 'entity_type' => $type, 'entity_id' => $entityId,
                'sourced_id' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
            ], $missing);
            DB::table('integration_external_identifiers')->insertOrIgnore($rows);
            $identifiers = $query();
        }

        return $identifiers;
    }

    private function base(Tenant $tenant): string
    {
        return rtrim((string) config('app.url'), '/').'/api/tenants/'.$tenant->slug.'/ims/case/v1p1';
    }
}
