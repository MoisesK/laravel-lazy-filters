<?php

declare(strict_types=1);

namespace MoisesK\LaravelLazyFilters;

use Ramsey\Uuid\Uuid;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

trait Searcheable
{
    /**
     * Operators to be used in request query string:
     * - eq (=)
     * - neq (!= ou <>)
     * - in (IN)
     * - nin (NOT IN)
     * - like (LIKE)
     * - lt (<)
     * - gt (>)
     * - lte (<=)
     * - gte (>=)
     * - btw (BETWEEN)
     *
     * @param Builder $query
     * @param Request|null $request
     * @return void
     * @see https://www.yiiframework.com/doc/guide/2.0/en/rest-filtering-collections#filtering-request
     */
    public function processSearch(Builder $query, ?Request $request = null): void
    {
        $request = $request ?? request();
        $filters = $request->query('filter');

        if (empty($filters)) {
            return;
        }

        foreach ($filters as $column => $param) {
            $column = Str::snake($column);
            $hasRelation = str_contains($column, '.');

            if (is_array($param)) {
                foreach ($param as $operator => $value) {
                    if (empty($value)) {
                        continue;
                    }

                    $value = trim($value);
                    $operator = SearchOperator::from($operator);

                    if ($operator === SearchOperator::LIKE) {
                        if ($hasRelation) {
                            if (count(explode('.', $column)) <= 2) {
                                [$relation, $column] = explode('.', $column);
                                $query->whereHas($relation, function (Builder $query) use ($operator, $value, $column) {
                                    $query->where($column, $operator->value, "%$value%");
                                });
                                continue;
                            }

                            [$relation1, $relation2, $column] = explode('.', $column);
                            $query->whereHas($relation1, function (Builder $query) use (
                                $operator,
                                $value,
                                $column,
                                $relation2
                            ) {
                                $query->whereHas($relation2, function (Builder $query) use (
                                    $operator,
                                    $value,
                                    $column
                                ) {
                                    $query->where($column, $operator->value, "%$value%");
                                });
                            });
                            continue;
                        }

                        $query->where($column, 'LIKE', "%$value%");
                        continue;
                    }

                    if ($operator === SearchOperator::IN) {
                        $values = explode(',', $value);
                        $query->whereIn($column, $values);
                        continue;
                    }

                    if ($operator === SearchOperator::BETWEEN) {
                        $dates = explode(',', $value);
                        if (strlen($dates[0]) === 7) {
                            $month1 = new DateTimeImmutable($dates[0]);
                            $date1 = "{$month1->format('Y-m')}-01 00:00:00";
                            if (!isset($dates[1])) {
                                $date2 = "{$month1->format('Y-m-t')} 23:59:59";
                            } else {
                                $month2 = new DateTimeImmutable($dates[1]);
                                $date2 = "{$month2->format('Y-m-t')} 23:59:59";
                            }

                            $query->whereBetween($column, [$date1, $date2]);
                            continue;
                        }

                        $date1 = "$dates[0] 00:00:00";
                        if (!isset($dates[1])) {
                            $date2 = "$dates[0] 23:59:59";
                        } else {
                            $date2 = "$dates[1] 23:59:59";
                        }

                        $query->whereBetween($column, [$date1, $date2]);
                    }
                }

                continue;
            }

            if (is_string($param)) {
                if ($hasRelation) {
                    if (count(explode('.', $column)) <= 2) {
                        [$relation, $column] = explode('.', $column);
                        $query->whereHas($relation, function (Builder $query) use ($param, $column) {
                            $value = isUuidString($param) ? Uuid::fromString($param)->getBytes() : $param;
                            $query->where($column, $value);
                        });
                        continue;
                    }

                    [$relation1, $relation2, $column] = explode('.', $column);
                    $query->whereHas($relation1, function (Builder $query) use ($param, $column, $relation2) {
                        $query->whereHas($relation2, function (Builder $query) use ($param, $column) {
                            $value = isUuidString($param) ? Uuid::fromString($param)->getBytes() : $param;
                            $query->where($column, $value);
                        });
                    });
                    continue;
                }

                if (isDateUs($param)) {
                    $query->where(DB::raw("DATE_FORMAT({$column}, '%Y-%m-%d')"), $param);
                    continue;
                }

                $value = isUuidString($param) ? Uuid::fromString($param)->getBytes() : $param;
                $query->where($column, $value);
            }
        }
    }
}
