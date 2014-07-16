<?php

require_once('LessFieldType.php');

class Less_Variable {
    
    public $name;
    public $category;
    public $type;
    public $title;
    public $description;
    public $default_value;
    public $order;
    function __construct($values){
        if($values){
            $values = array_change_key_case($values);
            $this->name = $values['name'];
            $this->category = $values['category'];
            $this->type = $values['type'];
            $this->title = $values['title'];
            $this->description = $values['description'];
            //Having to adjust for PHP incorrectly deserializing escaped quotation marks in JSON strings
            $this->default_value = str_replace('\"', '"', $values['defaultvalue']);
            $this->order = $values['order'];
        }
    }
    
    function merge($var){
        $result = clone $this;
        
        if($var->name){ $result->name = $var->name; }
        if($var->category){ $result->category = $var->category; }
        if($var->type){ $result->type = $var->type; }
        if($var->title){ $result->title = $var->title; }
        if($var->description){ $result->description = $var->description; }
        if($var->default_value){ $result->default_value = $var->default_value; }
        if($var->order){ $result->order = $var->order; }
        
        return $result;
    }
    
    
}

