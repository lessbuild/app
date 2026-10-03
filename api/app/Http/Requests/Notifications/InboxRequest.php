<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Data\Notifications\InboxFilters;
use Illuminate\Foundation\Http\FormRequest;

/** The inbox's filters from the query string. Unknown values are ignored rather than rejected. */
final class InboxRequest extends FormRequest
{
    /**
     * Get the validation rules: none, because unknown filter values are simply ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Build the filters, keeping only notification types the person has received.
     *
     * @param  list<string>  $types
     * @return InboxFilters
     */
    public function filters(array $types): InboxFilters
    {
        $type = $this->string('type')->toString();
        $search = mb_substr(trim($this->string('q')->toString()), 0, 100);

        return new InboxFilters($this->query('filter') === 'unread', in_array($type, $types, true) ? $type : null, $search !== '' ? $search : null);
    }
}
