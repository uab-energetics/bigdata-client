<?php
/**
 * Created by PhpStorm.
 * User: chris
 * Date: 4/29/17
 * Time: 11:54 PM
 */

namespace Models;


use DB\DB;
use triagens\ArangoDb\Document;
use triagens\ArangoDb\Edge;

class EdgeModel extends BaseModel
{

    /**
     * Creates a new record in the database, wraps it in a model, and returns it.
     * @param $to       string  The full _id of a vertex model
     * @param $from     string  The full _id of a vertex model
     * @param $data     array   PHP array of object attributes
     * @return mixed
     */
    public static function create( $to, $from, $data)
    {
        $edge_doc = Edge::createFromArray( $data );
        $key = DB::createEdge( static::getCollectionName(), $from, $to, $edge_doc );
//        $doc = DB::retrieve( static::getCollectionName(), $key );
        $edge_doc->setInternalKey($key);
        return static::createFromDocument( $edge_doc );
    }

    public function setTo( $to ){
        $this->update('_to', $to);
    }
    public function setFrom( $from ){
        $this->update('_from', $from);
    }
    public function getTo(  ){
        return $this->get( '_to' );
    }
    public function getFrom(  ){
        return $this->get( '_from' );
    }
}