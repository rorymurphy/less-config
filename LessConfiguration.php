<?php

class Less_Configuration {
    public $template;
    public $variables_file;
    public $entrypoint_files;
    public $variables;
    public $path;
    public $url;
    
    function __construct($values){
        if($values){
            if(!array_key_exists('path', $values)){
                throw new Exception("Values to construct Less_Configuration must contain a path reference to resolve relative paths", "","");
            }else{
                $this->path = $values['path'];
            }
            
            if(!array_key_exists('url', $values)){
                throw new Excpetion("Values to construct Less_Configuration must contain a url reference to resolve relative urls", "", "");
            }else{
                $this->url = $values['url'];
            }
            
            $file = $this->path;
            $dir = dirname($file);
            
            $values = array_change_key_case($values);
            $this->template = $values['template'];

            $this->variables_file = $values['variablesfile'];
            if(strpos($this->variables_file, '/') === 0){
                throw new Exception("Absolute paths are not allowed in LESS @import statements", '','');
            }else if(strpos($this->variables_file, '.') === 0){
                $this->variables_file = realpath($dir . $this->variables_file);
            }          
            
            $this->entrypoint_files = (array)$values['entrypointfiles'];
            if(array_key_exists('variables', $values)){
                $this->variables = array_map(function($m){
                    return new Less_Variable((array)$m);
                }, $values['variables']);
            }else{
                $this->variables = array();
            }
        }        
    }

    protected function get_output_dir(){
        $uploaddir = wp_upload_dir();
        return $uploaddir['basedir'] . '/less-config';
    }
    
    protected function get_output_url(){
        $uploaddir = wp_upload_dir();
        $result = $uploaddir['baseurl'] . '/less-config';
        $result = is_ssl() ? str_replace('http://', 'https://', $result) : $result;
        return $result;
    }
    
    function slugify($str){
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9]+/', '-', $str);
        return $str;
    }
    
    function merge($overrides){
        $result = clone $this;

        if($overrides->template){
            $result->template = $overrides->template;
        }
        if($overrides->variables_file){
            $result->variables_file = $overrides->variables_file;
        }
        if($overrides->entrypoint_files){
            $result->entrypoint_files = array_unique(array_merge($this->entrypoint_files, $overrides->entrypoint_files));
        }
        
        $result->url = $overrides->url;
        $result->path = $overrides->path;
        if($overrides->variables && count($overrides->variables) > 0){
            $oldVars = $this->get_variables_by_name();
            $newVars = $overrides->get_variables_by_name();
            
            foreach($oldVars as $key=>$value){
                if( array_key_exists($key, $oldVars) ){
                    $newVars[$key] = $value->merge($newVars[$key]);
                }else{
                    $newVars[$key] = $value;
                }
            }
            
            $result->variables = array_values($newVars);
        }
        
        return $result;
    }
    
    function get_variables_by_name(){
        $arr = $this->variables;
        $result = array();
        foreach($arr as $key => $value){
            $result[$value->name] = $value;
        }
        return $result;
    }
    
    function get_variables_by_category(){
        $arr = array();
        return array_map(function($m){
            if(!array_key_exists($m->category, $arr)){
                $arr[$m->category] = array();
            }
            $arr[$m->category][] = $m;
        }, $this->variables);
    }
    
    function compile($values){
        $normalized_paths = array();
        $normalized_paths_rev = array();
        $intermediate_paths = array();
        $css_output_paths = array();
        $intermediate_urls = array();
        $files = array_merge($this->entrypoint_files, array($this->variables_file => str_ireplace('.less', '.css', $this->variables_file)));
        $dir = dirname($this->path);
        foreach($files as $less_file => $css_file){
            $src = realpath($dir . '/' . $less_file);
            $normalized_paths[$less_file] = $src;
            $normalized_paths_rev[$src] = $less_file;
            
            $intermediate_urls[$less_file] = $this->get_output_url() . '/' . $this->slugify($less_file) . '.less';
            $intermediate_paths[$less_file] = Less_Configuration::normalize_path( $this->get_output_dir() . '/' . $this->slugify($less_file) . '.less' );
            $css_output_paths[$less_file] = Less_Configuration::normalize_path( $this->get_output_dir() . '/' . $this->slugify($less_file) . '.css' );
        }
        
        if(!file_exists($this->get_output_dir())){
            mkdir($this->get_output_dir());
        }
        ob_start();
        foreach($this->variables as $var){
            printf('@%1$s: %2$s;', $var->name, $values[$var->name]);
            print "\n";
        }
        $variables_output = ob_get_contents();
        ob_end_clean();
        file_put_contents($intermediate_paths[$this->variables_file], $variables_output);
        
        $replacer = function($t, $matches, $src, $key) use($intermediate_paths, $normalized_paths_rev){
            $tgt = Less_Configuration::normalize_path(dirname($src) . '/' . $matches[1]);
            if(array_key_exists($tgt, $normalized_paths_rev)){
                $result = $t->slugify($normalized_paths_rev[$tgt]) . '.less';
            }else{
                $result = str_replace('\\', '/', Less_Configuration::get_relative_path($intermediate_paths[$key], $tgt) );
            }
            
            return $result;
        };
        
        $t = $this;
        foreach($this->entrypoint_files as $less_file => $css_file){
            $src = $normalized_paths[$less_file];
            
            $srcText = file_get_contents($src);
            $srcText = preg_replace_callback('/@import\\s+url\\("([\\w\\.\\-_\\/\\\\]+\\.less)"\\);/', function($matches) use ($t, $replacer, $src, $less_file  ){
                return sprintf('@import("%1$s");', $replacer($t, $matches, $src, $less_file));
            }, $srcText);
            $srcText = preg_replace_callback('/@import\\s+"([\\w\\.\\-_\\/\\\\]+\\.less)";/', function($matches) use ($t, $replacer, $src, $less_file){
                return sprintf('@import "%1$s";', $replacer($t, $matches, $src, $less_file));
            }, $srcText); 
            
            file_put_contents($intermediate_paths[$less_file], $srcText);
            
        }
        
        $results = array();
//        $less = new lessc;
//        foreach($this->entrypoint_files as $less_file => $css_file){
//            $dirname = substr($this->url, 0, strrpos($this->url, '/'));
//            $results[$dirname . '/' . $css_file] = $less->compileFile($intermediate_paths[$less_file]);
//        }
        
        //return $results;
        return $intermediate_urls;
    }
    
    static function get_relative_path($from, $to)
    {
        $from = str_replace('\\', '/', $from);
        $to = str_replace('\\', '/', $to);
        $from     = explode('/', $from);
        $to       = explode('/', $to);
        $relPath  = $to;

        foreach($from as $depth => $dir) {
            // find first non-matching dir
            if($dir === $to[$depth]) {
                // ignore this directory
                array_shift($relPath);
            } else {
                // get number of remaining dirs to $from
                $remaining = count($from) - $depth;
                if($remaining > 1) {
                    // add traversals up to first matching dir
                    $padLength = (count($relPath) + $remaining - 1) * -1;
                    $relPath = array_pad($relPath, $padLength, '..');
                    break;
                } else {
                    $relPath[0] = './' . $relPath[0];
                }
            }
        }
        return Less_Configuration::normalize_path( implode('/', $relPath) );
    }

    static function normalize_path($path){
        $path = str_ireplace('\\', '/', $path);
        $path = preg_replace('/\/[^\/\.]+\/\.\.\//', '/', $path);
        $path = preg_replace('/(?>!\.)\.\//', '', $path);
        $path = str_ireplace('/', DIRECTORY_SEPARATOR, $path);
        return $path;
    }
    
}

