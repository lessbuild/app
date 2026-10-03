<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scim;

use App\Exceptions\ScimException;
use App\Models\Account;
use App\Models\ScimUser;
use App\Support\Identity\ScimResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListScimUsersController
{
    /**
     * List the people the identity provider manages, optionally filtered by `userName eq "…"` or `externalId eq "…"`,
     * a page at a time (startIndex from 1, count up to 200).
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->attributes->get('scim.account');
        $query = ScimUser::query()->where('account_id', $account->id)->with('user')->orderBy('created_at')->orderBy('id');
        $filter = trim($request->string('filter')->toString());
        if ($filter !== '') {
            if (preg_match('/^(userName|externalId)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"$/i', $filter, $match) !== 1) {
                throw new ScimException(400, 'Only userName eq and externalId eq filters are supported.', 'invalidFilter');
            }
            $value = stripcslashes($match[2]);
            strcasecmp($match[1], 'userName') === 0
                ? $query->whereHas('user', fn ($users) => $users->whereRaw('lower(email) = ?', [mb_strtolower($value)]))
                : $query->where('external_id', $value);
        }
        $start = max(1, $request->integer('startIndex', 1));
        $count = min(200, max(0, $request->integer('count', 100)));
        $total = (clone $query)->count();
        $page = $query->skip($start - 1)->take($count)->get();

        return ScimResources::respond(ScimResources::list(array_values($page->map(ScimResources::user(...))->all()), $total, $start));
    }
}
