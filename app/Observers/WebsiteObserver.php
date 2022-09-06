<?php

namespace App\Observers;

use App\Jobs\Web\AddWebsiteJob;
use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Website;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class WebsiteObserver
{
    /**
     * When a website is created
     *
     * @param  \App\Models\Website  $website
     * @return void
     */
    public function created(Website $website)
    {
        $PASSWORD = Str::random();

        Session::put($website->name . "_mysql_password", $PASSWORD);

        AddWebsiteJob::dispatch($website);
    }

    /**
     * When a website is deleted
     *
     * @param  \App\Models\Website  $website
     * @return void
     */
    public function deleting(Website $website)
    {
        DeleteWebsiteFromCaddyJob::dispatch($website->toArray());

        $website->repositories()->delete();
    }

}
