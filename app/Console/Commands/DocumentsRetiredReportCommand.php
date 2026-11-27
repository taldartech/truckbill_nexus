<?php

namespace App\Console\Commands;

use App\Domains\Document\DocumentParentTypes;
use App\Models\Company;
use App\Models\DocumentAttachment;
use Illuminate\Console\Command;

/**
 * Read-only retention report for attachments on retired parent types.
 * Files are never moved or deleted; they stay downloadable via the document manager.
 */
final class DocumentsRetiredReportCommand extends Command
{
    protected $signature = 'documents:retired-report
        {--company= : Limit to a company UUID}';

    protected $description = 'Report attachment counts on retired document parent types per company (read-only)';

    public function handle(): int
    {
        $companyFilter = $this->option('company');

        $rows = DocumentAttachment::withTrashed()
            ->whereIn('parent_type', DocumentParentTypes::retired())
            ->when(is_string($companyFilter) && $companyFilter !== '', fn ($query) => $query->where('company_id', $companyFilter))
            ->selectRaw('company_id, parent_type, count(*) as total_count')
            ->selectRaw('sum(case when deleted_at is null and is_current = ? then 1 else 0 end) as current_count', [true])
            ->selectRaw('sum(case when deleted_at is not null then 1 else 0 end) as soft_deleted_count')
            ->groupBy('company_id', 'parent_type')
            ->orderBy('company_id')
            ->orderBy('parent_type')
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No attachments found on retired parent types ('.implode(', ', DocumentParentTypes::retired()).').');

            return self::SUCCESS;
        }

        $companyNames = Company::query()
            ->whereIn('id', $rows->pluck('company_id')->unique()->values())
            ->pluck('name', 'id');

        $this->table(
            ['Company', 'Company ID', 'Parent type', 'Total', 'Current', 'Soft-deleted'],
            $rows->map(fn (object $row): array => [
                (string) ($companyNames[$row->company_id] ?? '—'),
                (string) $row->company_id,
                (string) $row->parent_type,
                (int) $row->total_count,
                (int) $row->current_count,
                (int) $row->soft_deleted_count,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
