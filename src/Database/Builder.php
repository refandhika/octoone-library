<?php namespace October\Rain\Database;

use Illuminate\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder as BuilderModel;
use October\Rain\Support\Facades\DbDongle;

/**
 * Query builder class.
 *
 * Extends Eloquent builder class.
 *
 * @package october\database
 * @author Alexey Bobkov, Samuel Georges
 */
class Builder extends BuilderModel
{
    use \October\Rain\Database\Concerns\QueriesRelationships;

    /**
     * Get an array with the values of a given column.
     *
     * @param string $column
     * @param string|null $key
     * @return array
     */
    public function lists($column, $key = null)
    {
        return $this->pluck($column, $key)->all();
    }

    /**
     * Perform a search on this query for term found in columns.
     * @param string $term Search query
     * @param array $columns Table columns to search
     * @param string $mode Search mode: all, any, exact.
     * @return self
     */
    public function searchWhere($term, $columns = [], $mode = 'all')
    {
        return $this->searchWhereInternal($term, $columns, $mode, 'and');
    }

    /**
     * Add an "or search where" clause to the query.
     * @param string $term Search query
     * @param array $columns Table columns to search
     * @param string $mode Search mode: all, any, exact.
     * @return self
     */
    public function orSearchWhere($term, $columns = [], $mode = 'all')
    {
        return $this->searchWhereInternal($term, $columns, $mode, 'or');
    }

    /**
     * Internal method to apply a search constraint to the query.
     * Mode can be any of these options:
     * - all: result must contain all words
     * - any: result can contain any word
     * - exact: result must contain the exact phrase
     */
    protected function searchWhereInternal($term, $columns, $mode, $boolean)
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }

        if (!$mode) {
            $mode = 'all';
        }

        if ($mode === 'exact') {
            $this->where(function (Builder $query) use ($columns, $term) {
                foreach ($columns as $field) {
                    if (!strlen($term)) {
                        continue;
                    }
                    $fieldSql = $this->query->raw(sprintf("lower(%s)", DbDongle::cast($field, 'text')));
                    $termSql = '%' . trim(mb_strtolower($term)) . '%';
                    $query->orWhere($fieldSql, 'LIKE', $termSql);
                }
            }, null, null, $boolean);
        } else {
            $words = explode(' ', $term);
            $wordBoolean = $mode === 'any' ? 'or' : 'and';

            $this->where(function (Builder $query) use ($columns, $words, $wordBoolean) {
                foreach ($columns as $field) {
                    $query->orWhere(function (Builder $query) use ($field, $words, $wordBoolean) {
                        foreach ($words as $word) {
                            if (!strlen($word)) {
                                continue;
                            }
                            $fieldSql = $this->query->raw(sprintf("lower(%s)", DbDongle::cast($field, 'text')));
                            $wordSql = '%' . trim(mb_strtolower($word)) . '%';
                            $query->where($fieldSql, 'LIKE', $wordSql, $wordBoolean);
                        }
                    });
                }
            }, null, null, $boolean);
        }

        return $this;
    }

    /**
     * Paginate the given query.
     *
     * @param int $perPage
     * @param int $currentPage
     * @param array $columns
     * @param string $pageName
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        /*
         * Engage Laravel signature support
         *
         * paginate($perPage, $columns, $pageName, $currentPage)
         */
        if (is_array($columns)) {
            // Adjusting parameters for backward compatibility
            $_columns = $columns;
            $_pageName = $pageName;
            $_page = $page;

            $columns = $_columns;
            $pageName = is_string($_pageName) ? $_pageName : 'page';
            $page = is_int($_page) ? $_page : null;
        }

        if (!$page) {
            $page = Paginator::resolveCurrentPage($pageName);
        }

        if (!$perPage) {
            $perPage = $this->model->getPerPage();
        }

        if ($total === null) {
            $total = $this->toBase()->getCountForPagination();
        }
    
        $this->forPage((int) $page, (int) $perPage);
    
        return $this->paginator($this->get($columns), $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName
        ]);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param int $perPage
     * @param int $currentPage
     * @param array $columns
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = null, $currentPage = null, $columns = ['*'], $pageName = 'page')
    {
        /*
         * Engage Laravel signature support
         *
         * paginate($perPage, $columns, $pageName, $currentPage)
         */
        if (is_array($currentPage)) {
            $_columns = $columns;
            $_currentPage = $currentPage;
            $_pageName = $pageName;

            $columns = $_currentPage;
            $pageName = is_string($_columns) ? $_columns : 'page';
            $currentPage = $_pageName === 'page' ? null : $_pageName;
        }

        if (!$currentPage) {
            $currentPage = Paginator::resolveCurrentPage($pageName);
        }

        if (!$perPage) {
            $perPage = $this->model->getPerPage();
        }

        $this->skip(($currentPage - 1) * $perPage)->take($perPage + 1);

        return $this->simplePaginator($this->get($columns), $perPage, $currentPage, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName
        ]);
    }

    /**
     * Insert new records or update the existing ones.
     *
     * @param  array  $values
     * @param  array|string  $uniqueBy
     * @param  array|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if (empty($values)) {
            return 0;
        }

        if (!is_array(reset($values))) {
            $values = [$values];
        }

        if (is_null($update)) {
            $update = array_keys(reset($values));
        }

        $values = $this->addTimestampsToValues($values);

        $update = $this->addUpdatedAtToColumns($update);

        return $this->toBase()->upsert($values, $uniqueBy, $update);
    }

    /**
     * Add timestamps to the inserted values.
     *
     * @param array $values
     * @return array
     */
    protected function addTimestampsToValues(array $values)
    {
        if (!$this->model->usesTimestamps()) {
            return $values;
        }

        $timestamp = $this->model->freshTimestampString();

        $columns = array_filter([$this->model->getCreatedAtColumn(), $this->model->getUpdatedAtColumn()]);

        foreach ($columns as $column) {
            foreach ($values as &$row) {
                $row = array_merge([$column => $timestamp], $row);
            }
        }

        return $values;
    }

    /**
     * Add the "updated at" column to the updated columns.
     *
     * @param array $update
     * @return array
     */
    protected function addUpdatedAtToColumns(array $update)
    {
        if (!$this->model->usesTimestamps()) {
            return $update;
        }

        $column = $this->model->getUpdatedAtColumn();

        if (!is_null($column) && !array_key_exists($column, $update) && !in_array($column, $update)) {
            $update[] = $column;
        }

        return $update;
    }

    /**
     * Dynamically handle calls into the query instance.
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if ($this->model->methodExists($scope = 'scope' . ucfirst($method))) {
            return $this->callScope([$this->model, $scope], $parameters);
        }

        return parent::__call($method, $parameters);
    }
}
