<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * W6-04: a list's CSV export takes every row the current filters match, not
 * just the loaded page. `all=1` runs the very query the paged list runs (same
 * filters, same unit scope, same order, same eager loads) in one response, up
 * to `fine.export_all_limit` rows. Past the cap the request is refused with
 * 422 EXPORT_TOO_LARGE (plus `limit` and `total`) rather than cut short, so an
 * export is never silently incomplete; the caller narrows the filters.
 */
trait ListsAllRows
{
    /**
     * Every matching row, or a 422 when there are more than the cap allows.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    private function allRows(Builder $query): Collection
    {
        $limit = (int) config('fine.export_all_limit', 5000);
        $total = (clone $query)->count();

        if ($total > $limit) {
            throw new HttpResponseException(response()->json([
                'message' => 'Too many rows to export at once; narrow the filters.',
                'code' => 'EXPORT_TOO_LARGE',
                'limit' => $limit,
                'total' => $total,
            ], 422));
        }

        return $query->get();
    }

    /**
     * The paged list, or with `all=1` every row as a single page of the same
     * paginator shape (data, total, current_page = last_page = 1, ...).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    private function paginateOrAll(Request $request, Builder $query, int $perPage): LengthAwarePaginator
    {
        if (! $request->boolean('all')) {
            // At least one row per page: paginate(0 or less) drops the LIMIT and would
            // return every row as one page, sidestepping the export cap.
            return $query->paginate(max($perPage, 1));
        }

        $rows = $this->allRows($query);

        return new LengthAwarePaginator($rows, $rows->count(), max($rows->count(), 1), 1, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }
}
