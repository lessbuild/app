<?php

namespace App\Core\Data\Status;

/**
 * @param  list<array{id:string,name:string,type:string,application:string,environment:string,health:string}>  $monitors
 * @param  list<array{id:string,name:string,slug:string,description:?string,published:bool,monitor_ids:list<string>,component_names:list<string>,public_url:string}>  $pages
 */
final readonly class WorkspaceMonitorStatusManagement
{
    public function __construct(
        public array $monitors,
        public array $pages,
        public bool $canManage,
    ) {}
}
