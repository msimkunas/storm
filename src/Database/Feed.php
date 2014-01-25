<?php namespace October\Rain\Database;

use DB;
use Str;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Model Feed class.
 *
 * Combine various models in to a single feed.
 *
 * @package october\database
 * @author Alexey Bobkov, Samuel Georges
 */
class Feed
{

    public $tagVar = 'tag_name';
    public $modelVar = 'model_name';

    /**
     * @var array Model collection pre-query
     */
    protected $collection = [];

    /**
     * @var Builder Cache containing the generic collection union query.
     */
    private $queryCache;

    /**
     * @var bool
     */
    public $removeDuplicates = false;

    /**
     * Add a new Builder to the feed collection
     */
    public function add($tag, $item)
    {
        if ($item instanceof Closure) {
            $item = call_user_func($item);
        }

        if (!$item)
            return;

        $this->collection[] = compact('item', 'tag');

        // Reset the query cache
        $this->queryCache = null;

        return $this;
    }

    /**
     * Count the number of results from the generic union query
     */
    public function count()
    {
        $query = $this->processCollection();
        $result = DB::table(DB::raw("(".$query->toSql().") as records"))->select(DB::raw("COUNT(*) as total"))->first();
        return $result->total;
    }

    /**
     * Executes the generic union query and eager loads the results in to the added models
     */
    public function get()
    {
        $query = $this->processCollection();
        $records = $query->get();

        /*
         * Build a collection of class names and IDs needed
         */
        $mixedArray = [];
        foreach ($records as $record) {
            $className = $record->{$this->modelVar};
            $mixedArray[$className][] = $record->id;
        }

        /*
         * Eager load the data collection
         */
        $collectionArray = [];
        foreach ($mixedArray as $className => $ids) {
            $obj = new $className;
            $collectionArray[$className] = $obj->whereIn('id', $ids)->get();
        }

        /*
         * Now load the data objects in to a final array
         */
        $dataArray = [];
        foreach ($records as $record)
        {
            $tagName = $record->{$this->tagVar};
            $className = $record->{$this->modelVar};

            $obj = $collectionArray[$className]->find($record->id);
            $obj->{$this->tagVar} = $tagName;
            $obj->{$this->modelVar} = $className;

            $dataArray[] = $obj;
        }

        return new Collection($dataArray);
    }

    /**
     * Returns the SQL expression used in the generic union
     */
    public function toSql()
    {
        $query = $this->processCollection();
        return $query->toSql();
    }

    /**
     * Creates a generic union query of each added collection
     */
    private function processCollection()
    {
        if ($this->queryCache !== null)
            return $this->queryCache;

        $lastQuery = null;
        foreach ($this->collection as $data)
        {
            extract($data);
            $cleanQuery = clone $this->getQuery($item);
            $model = $this->getModel($item);
            $class = str_replace('\\', '\\\\', get_class($model));

            /*
             * Flush the select, add ID and tag
             */
            $cleanQuery = $cleanQuery->select('id');
            $cleanQuery = $cleanQuery->addSelect(DB::raw("(SELECT '".$tag."') as ".$this->tagVar));
            $cleanQuery = $cleanQuery->addSelect(DB::raw("(SELECT '".$class."') as ".$this->modelVar));

            /*
             * Union this query with the previous one
             */
            if ($lastQuery) {
                if ($this->removeDuplicates)
                    $cleanQuery = $lastQuery->union($cleanQuery);
                else
                    $cleanQuery = $lastQuery->unionAll($cleanQuery);
            }

            $lastQuery = $cleanQuery;
        }

        return $this->queryCache = $lastQuery;
    }

    /**
     * Get the model from a builder object
     */
    private function getModel($item)
    {
        return $item->getModel();
    }

    /**
     * Get the query from a builder object
     */
    private function getQuery($item)
    {
        return $item->getQuery();
    }
}