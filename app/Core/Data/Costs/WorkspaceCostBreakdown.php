<?php

namespace App\Core\Data\Costs;

final readonly class WorkspaceCostBreakdown
{
    /**
     * @param  list<array{label:string,provider:?string,size:?string,websites:int,average_cpu:?float,monthly:?float,idle:bool,attribution:string,project_names:list<string>,catalog_observed_at:?string}>  $rows
     * @param  array{limit:?int,used:int,previews:list<array{project_name:string,pull_request_number:?int,status:string,ttl_hours:int,expired:bool,expires_at:?string}>,hidden_count:int}  $previewUsage
     */
    public function __construct(
        public array $rows,
        public float $estimated,
        public int $unknownCount,
        public int $idleCount,
        public array $previewUsage,
        public ?float $budget,
        public bool $canManage,
        public bool $featureAvailable,
    ) {}
}
