<?php

namespace Eawardie\DataGrid;

use Closure;
use Eawardie\DataGrid\Definitions\ColumnDefinition;
use Eawardie\DataGrid\Definitions\IconDefinition;
use Eawardie\DataGrid\Definitions\ViewDefinition;
use Eawardie\DataGrid\Models\DataGrid;
use Eawardie\DataGrid\Traits\DynamicCompare;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DataGridService
{
    //all data grid properties
    private Builder $query;
    private ?Collection $existingConfig;
    private ?Collection $request;
    private ?string $ref;
    private int $page = 1;
    private int $itemsPerPage = 50;
    private int $totalItems = 0;
    private int $totalPages = 0;
    private array $search = [
        'term' => '',
        'recommendations' => [],
        'queries' => [],
    ];
    private array $sortBy = [];
    private array $filters = [];
    private array $columns = [];
    private array $items = [];
    private array $layouts = [];
    private array $metaData = [];
    private bool $filterWithConfig = false;
    private bool $searchWithSession = false;
    private bool $sortWithSession = false;
    private bool $pageWithSession = false;
    private bool $hyperlinks = false;
    private ?Closure $rowMapClosure = null;
    private array $additionalSelects = [];
    private array $loadRelationships = [];
    private array $additionalRawSelects = [];
    private array $defaultOrderBy = [];

    //indicates column types that are accepted as advanced
    private const ADVANCED_COLUMN_TYPES = ['number', 'perc', 'timestamp', 'enum', 'icon'];

    //dynamic comparison trait used to determine icon per items
    use DynamicCompare;

    //inits data grid
    public function __construct()
    {
        $this->ref = request()->path();
        $this->setRequest(collect(json_decode(base64_decode(request('q')), true)));
    }

    //sets the query to be used by the data grid
    //when query of type relation is used, use the getQuery() helper function on the original query
    public function forQuery(Builder $query): self
    {
        $this->setQuery($query);
        $this->handleConfig();

        return $this;
    }

    //returns the query of the data grid
    public function getQuery(): Builder
    {
        return $this->query;
    }

    //sets the query for the data grid
    public function setQuery($query): self
    {
        $this->query = $query;

        return $this;
    }

    //returns the current request
    public function getRequest(): ?Collection
    {
        return $this->request;
    }

    //sets the current request
    public function setRequest($request = []): self
    {
        $this->request = $request;

        return $this;
    }

    //returns the current data grid reference
    public function getReference(): ?string
    {
        return $this->ref;
    }

    //function used to pass key -> value pairs for default column orders
    //this is ignored if anu user changes are passed in the request
    public function defaultOrderBy(array $orders): self
    {
        $this->defaultOrderBy = $orders;

        return $this;
    }

    //returns the current data grid columns
    //pass true for labels only to get an array of column labels, useful for data export
    public function getColumns(bool $labelsOnly = false): array
    {
        if ($labelsOnly) {
            collect($this->columns)->pluck('label');
        }

        return $this->columns;
    }

    //sets the current data grid columns
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    //switches the filter storage from config (default) to session
    public function filterWithConfig(): self
    {
        $this->filterWithConfig = true;

        return $this;
    }

    //switches search storage from route parameters (default) to session
    public function searchWithSession(): self
    {
        $this->searchWithSession = true;

        return $this;
    }

    //switches sort storage from route parameters (default) to session
    public function sortWithSession(): self
    {
        $this->sortWithSession = true;

        return $this;
    }

    //switches page storage from route parameters (default) to session
    public function pageWithSession(): self
    {
        $this->pageWithSession = true;

        return $this;
    }

    /**
     * @throws Throwable
     */
    //function to add an advanced column
    //includes a list of helper functions to build a column from scratch
    //allows for fine grain column control
    public function addAdvancedColumn(Closure $closure): self
    {
        $column = $closure(new ColumnDefinition())->toArray();
        $index = count($this->columns);
        $column['index'] = $index;
        $column['originalIndex'] = $index;

        if ($column['type'] === 'enum' && count($column['enumerators']) === 0) {
            $column['enumerators'] = $this->autoGenerateEnumerators($column['rawValue']);
        }

        $this->columns[] = $column;

        return $this;
    }

    /**
     * @throws Throwable
     */
    //function to add a simple column
    //has less functionality but generally less code to use
    public function addColumn(string $value, string $label, string $type, bool $searchable = true, bool $sortable = true, bool $hidden = false): self
    {
        $index = count($this->columns);
        $basicValueArray = explode('.', $value);
        $basicValue = $basicValueArray[count($basicValueArray) - 1];
        $enumerators = [];

        if ($type === 'enum') {
            $enumerators = $this->autoGenerateEnumerators($value);
        }

        $this->column($basicValue, $value, $label, $type, $index, $searchable, $sortable, [], $enumerators, $hidden);

        return $this;
    }

    /**
     * @throws Throwable
     */
    //function to add an icon column
    //icon columns only contain icons
    //can also take the advanced IconDefinition class as a closure for fine grain icon condition control per item
    public function addIconColumn(string $value, string $label, $icon, string $color = 'grey', bool $searchable = true, bool $sortable = true, bool $hidden = false): self
    {
        $index = count($this->columns);
        $basicValueArray = explode('.', $value);
        $basicValue = $basicValueArray[count($basicValueArray) - 1];
        $iconMap = [];

        if (gettype($icon) === 'string') {
            $iconMap = [[
                'icon' => $icon,
                'value' => null,
                'color' => $color,
                'tooltip' => null,
                'operator' => null,
                'default' => true,
            ]];
        } elseif ($icon instanceof Closure) {
            $iconMap = $icon(new IconDefinition())->toArray();
        }

        $this->column($basicValue, $value, $label, 'icon', $index, $searchable, $sortable, $iconMap, [], $hidden);

        return $this;
    }

    public function addCustomColumn(string $identifier, string $label, bool $hidden = false): self
    {
        $index = count($this->columns);

        $this->column($identifier, $identifier, $label, 'custom', $index, false, false, [], [], $hidden);

        return $this;
    }

//    public function addFileColumn(string $value, string $label, string $icon = 'mdi-file', string $iconColor = 'grey'): self
//    {
//        $index = count($this->columns);
//        $this->column($value, $value, $label, 'file', $index);
//
//        return $this;
//    }

    /**
     * @throws Throwable
     */
    //function to add layouts to the data grid
    //layouts are added with LayoutDefinition class
    public function views(...$layoutDefinitions): self
    {
        $this->validateLayoutDefinitions($layoutDefinitions);

        $this->layouts = collect($layoutDefinitions)->map(function ($layoutDefinition, $index) {
            $layout = $layoutDefinition(new ViewDefinition())->toArray();
            $id = 'predefined' . '_' . $index;

            return [
                'id' => $id,
                'columns' => $layout['columns'],
                'label' => $layout['label'],
                'search' => $layout['search'],
                'sort' => $layout['sort'],
                'filters' => $layout['filters'],
                'current' => $this->existingConfig['currentLayout'] === $id,
                'custom' => false,
            ];
        })->toArray();

        $this->validateLayouts();

        return $this;
    }

    //switches whether to show hyperlinks for emails or not
    //email hyperlinks will automatically open the default email writer with a draft for that email address
    public function hyperlinks(): self
    {
        $this->hyperlinks = true;

        return $this;
    }

    //function used to add extra selects to final items array
    public function addSelect(string $select): self
    {
        $this->additionalSelects[] = $select;

        return $this;
    }

    //function used to add extra selects to final items array
    public function addRawSelect(string $rawSelect): self
    {
        $this->additionalRawSelects[] = $rawSelect;

        return $this;
    }

    //function used t get access to every item on the current page
    //can be used to mutate existing rows
    public function map(Closure $closure): self
    {
        $this->rowMapClosure = $closure;

        return $this;
    }

    public function load(...$relationships): self
    {
        if (is_array($relationships[0])) {
            $this->loadRelationships = $relationships[0];
        } else {
            $this->loadRelationships = $relationships;
        }

        return $this;
    }

    /**
     * @return array
     *
     * @throws Exception
     * @throws Throwable
     */
    //function to manage and return final state of the data grid
    //can only be called as the very last function
    public function get(): array
    {
        $this->setFromRequest();
        $this->applyLayout();
        $this->prepareItems();
        $this->setTotals();
        $this->applyPaging();
        $this->setItems();
        $this->setLayouts();
        $this->prepareMetaData();

        return [
            'items' => $this->items,
            'metaData' => $this->metaData,
        ];
    }

    //sets or gets the database config for the data grid
    //configs are based on the data grid reference and current user auth ID
    private function handleConfig()
    {
        if (Models\DataGrid::authHasConfiguration($this->ref)) {
            $this->existingConfig = $this->getConfiguration();
        } else {
            $this->existingConfig = $this->setConfiguration();
        }
    }

    //sets data grid options from current page request
    //changes may take effect based on route, session of config settings
    private function setFromRequest()
    {
        $this->preparePaging();
        $this->prepareSearch();
        $this->prepareOrderBy();
        $this->prepareFilters();

        if ($this->request->has('rl') && (int)$this->request->get('rl', 0) === 1) {
            DataGrid::updateConfigurationValue($this->ref, 'currentLayout', null);
            $this->layouts = collect($this->layouts)->map(function ($layout) {
                $layout['current'] = false;
                return $layout;
            })->toArray();
            $this->existingConfig['currentLayout'] = null;
        }
    }

    private function preparePaging()
    {
        if (!$this->pageWithSession) {
            $this->page = $this->request->get('page', 1);
            $this->itemsPerPage = $this->request->get('itemsPerPage', 50);
        } else {
            $this->page = session($this->ref)['page'] ?? 1;
            $this->itemsPerPage = session($this->ref)['itemsPerPage'] ?? 50;
        }
    }

    private function prepareSearch()
    {
        $defaultSearch = [
            'term' => '',
            'recommendations' => [],
            'queries' => [],
        ];

        if (!$this->searchWithSession) {
            $this->search = $this->request->get('search', $defaultSearch);
        } else {
            $this->search = session($this->ref)['search'] ?? $defaultSearch;
        }
    }

    private function prepareOrderBy()
    {
        if (!$this->sortWithSession) {
            $this->sortBy = $this->request->get('sortBy', []);
        } else {
            $this->sortBy = session($this->ref)['sortBy'] ?? [];
        }

        $orders = $this->query->getQuery()->orders;
        $this->query->getQuery()->orders = [];
        $hasExistingOrders = $orders && count($orders) > 0;
        $hasUserSort = $this->request->has('sortBy') && count($this->request->get('sortBy')) > 0;

        if (!$hasUserSort && $hasExistingOrders && count($this->defaultOrderBy) === 0) {
            foreach ($orders as $order) {
                $this->sortBy[$order['column']] = $order['direction'];
            }
        } else if (!$hasUserSort && count($this->defaultOrderBy) > 0) {
            foreach ($this->defaultOrderBy as $column => $direction) {
                $this->sortBy[$column] = $direction;
            }
        }
    }

    private function prepareFilters()
    {
        if (!$this->filterWithConfig) {
            $this->filters = session($this->ref)['filters'] ?? [];
        } else {
            $this->filters = $this->existingConfig['filters'] ?? [];
        }
    }

    //function to prepare final data grid meta data
    private function prepareMetaData(): void
    {
        if (count($this->filters) > 0 || (isset($this->search['queries']) && count($this->search['queries']) > 0)) {
            $this->columns = collect($this->columns)->map(function ($column) {
                $value = $column['isRaw'] ? $column['value'] : $column['rawValue'];
                if (isset($this->filters[$value]) || isset($this->search['queries'][$value])) {
                    $column['hidden'] = false;
                }

                return $column;
            })->toArray();
        }

        $this->metaData = [
            'tableRef' => $this->ref,
            'page' => $this->page,
            'itemsPerPage' => $this->itemsPerPage,
            'totalItems' => $this->totalItems,
            'totalPages' => $this->totalPages,
            'sortBy' => $this->sortBy,
            'filters' => $this->filters,
            'search' => $this->search,
            'columns' => $this->columns,
            'layouts' => $this->layouts,
            'currentLayout' => $this->existingConfig['currentLayout'],
            'hyperlinks' => $this->hyperlinks,
            'advancedColumnTypes' => self::ADVANCED_COLUMN_TYPES,
            'states' => [
                'filter' => $this->filterWithConfig ? 'config' : 'session',
                'search' => $this->searchWithSession ? 'session' : 'route',
                'sort' => $this->sortWithSession ? 'session' : 'route',
                'page' => $this->pageWithSession ? 'session' : 'route',
            ],
        ];
    }

    //applies a selected layout onto the data grid
    //can also be a custom layout created by the user
    private function applyLayout()
    {
        if (isset($this->existingConfig['currentLayout']) && $this->existingConfig['currentLayout']) {
            $layouts = collect($this->layouts)->merge($this->existingConfig['layouts']);
            $layout = $layouts->firstWhere('id', $this->existingConfig['currentLayout']);

            if ($layout) {
                if (isset($layout['search'])) {
                    $this->search = $layout['search'];
                }

                if (isset($layout['sort'])) {
                    $this->sortBy = $layout['sort'];
                }

                if (isset($layout['filters'])) {
                    $this->filters = $layout['filters'];
                }

                $this->columns = collect($this->columns)->map(function ($column) use ($layout) {
                    $value = $column['isRaw'] ? $column['value'] : $column['rawValue'];
                    $found = collect($layout['columns'])->firstWhere('value', $value);

                    if ($found) {
                        $column['hidden'] = $found['hidden'];
                        $column['index'] = $found['order'];
                    } else {
                        $column['hidden'] = true;
                    }

                    return $column;
                })->toArray();
            }
        }
    }

    //returns the current data grid config from the database
    private function getConfiguration(): ?Collection
    {
        if ($this->ref) {
            return collect(Models\DataGrid::getConfigurationData($this->ref));
        }

        return null;
    }

    //sets the current data grid config in the database
    private function setConfiguration()
    {
        if ($this->ref) {
            $data = [
                'tableRef' => $this->ref,
                'layouts' => [],
                'currentLayout' => null,
                'search' => [],
                'sort' => [],
                'filters' => [],
            ];

            return Models\DataGrid::setConfigurationData($this->ref, $data);
        }

        return [];
    }

    /**
     * @throws Exception
     */
    //function to prepare the final items for the data grid
    private function prepareItems(): void
    {
        $this->applySelects();
        $this->applySortBy();
        $this->applySearch();
        $this->applyFilters();
    }

    //sets total item and page counts for paging and front-end display
    private function setTotals()
    {
        $this->totalItems = $this->getCountForPagination();
        $this->totalPages = ceil($this->totalItems / $this->itemsPerPage);
    }

    private function getCountForPagination(): int
    {
        return $this->query->toBase()->getCountForPagination();
    }

    //selects all required values for items to be displayed on the front-end
    private function applySelects()
    {
        collect($this->columns)->each(function ($column) {
            if ($column['type'] !== 'custom') {
                $this->selectValues($column);
                $this->selectAvatar($column);
            }
        });

        if (count($this->additionalSelects) > 0) {
            foreach ($this->additionalSelects as $select) {
                $this->query->addSelect(DB::raw($select));
            }
        }

        if (count($this->additionalSelects) > 0) {
            foreach ($this->additionalRawSelects as $rawSelect) {
                $this->query->addSelect(DB::raw($rawSelect));
            }
        }
    }

    //selects specifically the item values
    private function selectValues(array $column)
    {
        $this->query->addSelect(DB::raw($column['rawValue'] . ($column['isRaw'] ? ' AS ' . $column['value'] : '')));

        if (isset($column['rawSubtitle']) && $column['rawSubtitle']) {
            $this->query->addSelect(DB::raw($column['rawSubtitle'] . ' AS ' . $column['subtitle']));
        }

        if (isset($column['iconConditionRawValue']) && $column['iconConditionRawValue']) {
            $this->query->addSelect(DB::raw($column['iconConditionRawValue'] . ' AS ' . $column['iconConditionValue']));
        }
    }

    //sets avatar file details if the advanced column avatar function is used
    private function selectAvatar(array $column)
    {
        if (isset($column['avatar'])) {
            $this->query->leftJoin('file AS ' . $column['value'] . '_file', $column['avatar'], '=', $column['value'] . '_file.fileid');
            $this->query->addSelect($column['value'] . '_file.thumbnail_key AS ' . $column['value'] . '_file_key');
            $this->query->addSelect($column['value'] . '_file.disk AS ' . $column['value'] . '_file_disk');
            $this->query->addSelect($column['value'] . '_file.base_url AS ' . $column['value'] . '_file_base_url');
        }
    }

    //applies sort orders based on front-end selections
    private function applySortBy()
    {
        if (count($this->sortBy) > 0) {
            foreach ($this->sortBy as $value => $direction) {
                $column = collect($this->columns)->firstWhere('value', $value);
                if (!$column) {
                    $column = collect($this->columns)->firstWhere('rawValue', $value);
                }

                $innerValue = $column['isRaw'] ? $column['value'] : $column['rawValue'];
                $this->query->orderBy(DB::raw($innerValue), $direction);
            }
        }
    }

    //applies search queries as selected on the front-end
    //if search is in its initial stage this function provides recommendations
    private function applySearch()
    {
        if (isset($this->search['queries']) && count($this->search['queries']) > 0) {
            $index = 0;
            foreach ($this->search['queries'] as $key => $terms) {
                $isSubtitle = false;
                $column = collect($this->columns)->firstWhere('rawValue', $key);
                if (!$column) {
                    $column = collect($this->columns)->firstWhere('value', $key);
                }

                if (!$column) {
                    $column = collect($this->columns)->firstWhere('rawSubtitle', $key);
                    $isSubtitle = true;
                }
                if (!$column) {
                    $column = collect($this->columns)->firstWhere('subtitle', $key);
                    $isSubtitle = true;
                }

                if ($index === 0) {
                    $clause = ((isset($column['isAggregate']) && $column['isAggregate'])
                        || (isset($column['subtitleIsAggregate'])) && $column['subtitleIsAggregate'])
                        ? 'havingRaw'
                        : 'whereRaw';
                } else {
                    $clause = ((isset($column['isAggregate']) && $column['isAggregate'])
                        || (isset($column['subtitleIsAggregate'])) && $column['subtitleIsAggregate'])
                        ? 'orHavingRaw'
                        : 'orWhereRaw';
                }

                if ($column) {
                    $this->query->where(function ($query) use ($column, $clause, $terms, $isSubtitle) {
                        foreach ($terms as $term) {
                            $query->$clause((!$isSubtitle ? $column['rawValue'] : $column['rawSubtitle']) . ' LIKE "%' . strtolower($term) . '%"');
                        }
                    });
                }
                $index++;
            }
        }

        $this->search['term'] = '';
        $this->prepareMetaData();
    }

    //applies the selected filters from the front-end
    //filters are only applies when advanced column exist
    private function applyFilters()
    {
        if (count($this->filters) > 0) {
            foreach ($this->filters as $key => $filter) {
                if (count($filter) > 0) {
                    $clause = 'whereRaw';
                    $identifier = str_replace('_icon', '', $key);
                    $operator = $filter['operator'] === '===' ? '=' : $filter['operator'];
                    $column = collect($this->columns)->firstWhere('rawValue', $identifier);
                    $isSubtitle = false;
                    $isIcon = false;

                    if (!$column) {
                        $column = collect($this->columns)->firstWhere('value', $identifier);
                    }

                    if (!$column) {
                        $column = collect($this->columns)->firstWhere('subtitle', $identifier);
                        $isSubtitle = !!$column;
                    }

                    if (!$column) {
                        $column = collect($this->columns)->firstWhere('rawSubtitle', $identifier);
                        $isSubtitle = !!$column;
                    }

                    if (!$column) {
                        $column = collect($this->columns)->firstWhere('iconConditionRawValue', $identifier);
                        $isIcon = !!$column;
                    }

                    if (!$column) {
                        $column = collect($this->columns)->firstWhere('iconConditionValue', $identifier);
                        $isIcon = !!$column;
                    }

                    if ($column) {
                        if (isset($column['isAggregate']) && $column['isAggregate']) {
                            $clause = 'havingRaw';
                        }

                        //find a better solution for time inclusive dates
                        if ($column['type'] === 'timestamp' && $operator === '=') {
                            if($filter['value'] != null) {
                                $operator = 'LIKE';
                                $filter['value'] .= '%';
                            } else {
                                $operator = 'IS';
                                $filter['value'] = ' NULL';
                            }
                        }

                        if ($isSubtitle) {
                            $comparative = $column['rawSubtitle'];
                        } else if ($isIcon) {
                            $comparative = $column['iconConditionRawValue'];
                        } else {
                            $comparative = $column['rawValue'];
                        }

                        if($filter['value'] === ' NULL') {
                            $evaluation = $comparative . ' ' . $operator . $filter['value'];
                        } else {
                            $evaluation = $comparative . ' ' . $operator . ' "' . $filter['value'] . '"';
                        }

                        $this->query->$clause($evaluation);
                    }
                }
            }
        }
    }

    //applies final paging from items
    //table uses 50 items by default
    private function applyPaging()
    {
        if ($this->itemsPerPage > 0) {
            $this->query->skip(($this->page - 1) * $this->itemsPerPage)
                ->take($this->queryInstance->limit ?? $this->itemsPerPage);
        }
    }

    /**
     * @throws Exception
     */
    //function for setting final items states
    //handles basic item values, avatars and icon states
    private function setItems()
    {
        $data = $this->query->get();
        if (count($this->loadRelationships) > 0) {
            $data->load($this->loadRelationships);
        }
        $items = $data->toArray();
        $enumColumns = collect($this->columns)->where('type', '=', 'enum')->toArray();
        $avatarColumns = collect($this->columns)->where('avatar', '!=', null)->toArray();
        $iconColumns = collect($this->columns)->where('type', 'icon')->toArray();
        $columnsWithIcons = collect($this->columns)->where('iconConditionValue', '!=', null)->toArray();
        $iconColumns = collect($iconColumns)->merge($columnsWithIcons)->toArray();

        $hasEnumColumns = count($enumColumns) > 0;
        $hasAvatarColumns = count($avatarColumns) > 0;
        $hasIconColumns = count($iconColumns) > 0;
        $modifiedItems = [];

        /** @var Model $item */
        foreach ($data as $item) {
            if ($hasAvatarColumns) {
                foreach ($avatarColumns as $column) {
                    $item[$column['value'] . '_avatar_url'] = $this->generateAvatarUrl($item, $column['value']);
                    unset($item[$column['value'] . '_file_key']);
                    unset($item[$column['value'] . '_file_disk']);
                    unset($item[$column['value'] . '_file_base_url']);
                }
            }

            if ($hasIconColumns) {
                $icon = $this->getIcon($item->toArray(), $iconColumns);
                foreach ($icon as $key => $value) {
                    $item->setAttribute($key, $value);
                }
            }

            if ($hasEnumColumns) {
                foreach ($enumColumns as $enumColumn) {
                    if (isset($enumColumn['enumerators'][$item[$enumColumn['value']]]) && $item[$enumColumn['value']]) {
                        $item[$enumColumn['value']] = $enumColumn['enumerators'][$item[$enumColumn['value']]];
                    }
                }
            }

            if ($this->rowMapClosure && is_callable($this->rowMapClosure)) {
                $item = call_user_func($this->rowMapClosure, $item);
            }

            $modifiedItems[] = $item;
        }

        $this->items = $modifiedItems;
    }

    private function setLayouts() {
        if (count($this->existingConfig['layouts']) > 0) {
            $this->layouts = collect($this->layouts)->concat($this->existingConfig['layouts'])->toArray();
        }
    }

    private function autoGenerateEnumerators(string $value): array
    {
        $cloned = clone $this->query;
        return $cloned->select(DB::raw('DISTINCT ' . $value . ' AS value'))
            ->get()
            ->mapWithKeys(function ($item) {
                $text = implode(' ', array_map('ucfirst', explode('_', $item->value)));

                return [$item->value => $text];
            })->toArray();
    }

    //generates avatar URLs based on previously selected avatar values
    private function generateAvatarUrl($item, $value): ?string
    {
        $disk = $item[$value . '_file_disk'];
        $key = $item[$value . '_file_key'];
        $baseUrl = $item[$value . '_file_base_url'];

        if ($disk === 's3') {
            return $baseUrl . '/' . $key;
        } else {
            if ($disk && $key) {
                return Storage::disk($disk)->temporaryUrl($key, Carbon::now()->addMinutes(config('filesystems.validity')));
            }

            return '';
        }
    }

    /**
     * @throws Exception
     */
    //gets icon for item based on columns of type icon
    private function getIcon(array $item, array $columns = []): array
    {
        foreach ($columns as $column) {
            if (isset($column['iconConditionValue']) && $column['iconConditionValue']) {
                return [$column['iconConditionValue'] . '_icon' => $this->getIconFromCondition($item[$column['iconConditionValue']], $column['iconMap'])];
            } else {
                return [$column['value'] . '_icon' => $this->getIconFromCondition($item[$column['value']], $column['iconMap'])];
            }
        }

        return [];
    }

    /**
     * @throws Exception
     */
    //returns icon set based on the conditions as specified by the IconDefinition class
    private function getIconFromCondition(?string $value, array $icons): array
    {
        $index = collect($icons)->search(function ($icon) use ($value) {
            return !$icon['default'] && $this->is($value, $icon['operator'], $icon['value']);
        });

        if ($index === false) {
            $index = collect($icons)->search(function ($icon) {
                return $icon['default'];
            });
        }

        return collect($icons[$index])->only(['icon', 'color', 'tooltip'])->toArray();
    }

    //append one column to the total columns of the data grid
    private function column(
        string $value,
        string $rawValue,
        string $label,
        string $type,
        int    $index = 0,
        bool   $searchable = false,
        bool   $sortable = false,
        array  $iconMap = [],
        array  $enumerators = [],
        bool   $hidden = false
    )
    {
        $this->columns[] = [
            'value' => $value,
            'rawValue' => $rawValue,
            'label' => $label,
            'type' => $type,
            'index' => $index,
            'originalIndex' => $index,
            'hidden' => $hidden,
            'searchable' => $searchable,
            'sortable' => $sortable,
            'isAggregate' => false,
            'isRaw' => false,
            'isAdvanced' => in_array($type, self::ADVANCED_COLUMN_TYPES),
            'iconMap' => $iconMap,
            'enumerators' => $enumerators,
            'timestampFormat' => 'D MMMM YYYY',
        ];
    }

    /**
     * @throws Throwable
     */
    //validates layout definition to ensure they are of closure instances
    private function validateLayoutDefinitions($definitions)
    {
        foreach ($definitions as $definition) {
            throw_if(!$definition instanceof Closure, 'Layouts must be of type Closure. Use function(LayoutDefinition $layout) instead.');
        }
    }

    /**
     * @throws Throwable
     */
    //validates all passed layouts from the LayoutDefinition class
    //primarily checks whether layouts use column that do not exist on the data grid
    private function validateLayouts()
    {
        throw_if(count($this->layouts) === 0, 'When using layouts() there should be at least one layout specified.');

        $moreThanOneDefault = collect($this->layouts)->where('default', true)->count() > 1;
        throw_if($moreThanOneDefault, 'Only one layout can be set as the default layout.');

        $layoutColumns = collect($this->layouts)->pluck('columns')->flatten(1)->toArray();
        foreach ($layoutColumns as $layoutColumn) {
            $found = collect($this->columns)->firstWhere('value', $layoutColumn['value']) !== null;
            if (!$found) {
                $found = collect($this->columns)->firstWhere('rawValue', $layoutColumn['value']) !== null;
            }

            throw_if(!$found, 'Layout with value "' . $layoutColumn['value'] . '" does not have a corresponding column. Please ensure each layout column has a specified table column.');
        }
    }
}
