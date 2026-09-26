<?php

namespace App\Core\Data\Status;

/**
 * @param  list<array{id:string,name:string}>  $websites
 * @param  list<array{id:string,name:string,slug:string,description:?string,published:bool,website_ids:list<string>,website_names:list<string>,subscriber_count:int,public_url:string}>  $pages
 * @param  list<array{id:string,page_id:string,page_name:string,kind:string,status:string,severity:string,title:string,message:string,root_cause:?string,remediation:?string,follow_up:?string,starts_at:string,ends_at:?string}>  $incidents
 */
final readonly class WorkspaceCustomerStatusManagement
{
    public function __construct(
        public array $websites,
        public array $pages,
        public array $incidents,
        public bool $canManage,
        public bool $featureAvailable,
    ) {}
}
