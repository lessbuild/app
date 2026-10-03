<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiteAuditCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something a run found to improve, with the evidence (a screenshot with the problem outlined) and, for layout and
 * copy problems, a rendered mock-up of the fix.
 *
 * @property int $id
 * @property int $site_audit_run_id
 * @property string $project_id
 * @property SiteAuditCategory $category
 * @property string $severity high, medium or low
 * @property string $effort small, medium or large
 * @property string $title
 * @property string $detail what's wrong and why it matters
 * @property string $recommendation what to change
 * @property string|null $page_url
 * @property string|null $screenshot_path
 * @property list<array{x: int, y: int, width: int, height: int}>|null $boxes
 * @property string|null $mockup_path
 * @property string|null $competitor_note how a competitor handles the same thing
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SiteAuditRun $run
 */
final class SiteAuditFinding extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['category' => SiteAuditCategory::class, 'boxes' => 'array'];
    }

    /**
     * Get the run that found it.
     *
     * @return BelongsTo<SiteAuditRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SiteAuditRun::class, 'site_audit_run_id');
    }
}
