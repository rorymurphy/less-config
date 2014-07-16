<?php
/*
Plugin Name: WordPress LESS Config
Description: Gives users the power to tweak styles based on LESS stylesheets.
Author: Rory Murphy
Author URI: https://github.com/rorymurphy/
Version: 1.0.0
License: Modified MIT License - See LICENSE.TXT
*/

//require_once "lessphp/lessc.inc.php";
require_once "LessConfiguration.php";
require_once "LessVariable.php";
class Less_Config {
    const FILTER_NAME = 'less-config';
    const CURRENT_SETTINGS_OPTION = 'less-config-settings';
    const VERSIONS_OPTION = 'less-config-versions';
    const SETTINGS_VERSION_PATT = 'less-config-settings-%1$s';
    const PAGE_NAME = 'wp_less_config';
    public $less_configuration;
    function __construct(){
        $this->register_hooks();
    }
    
    function register_hooks(){
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts_and_styles'));
        add_filter('print_styles_array', array($this, 'filter_styles'));
        add_action( 'wp_ajax_create_less_files', array($this, 'create_less_files') );
        add_action( 'wp_ajax_save_css_files', array($this, 'save_css_files') );
        
    }
    
    function enqueue_scripts_and_styles()
    {
        $screen = get_current_screen();
        if($screen->base=='appearance_page_' + Less_Config::PAGE_NAME){
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-validate', plugin_dir_url(__FILE__) . 'js/jquery.validate.min.js', array('jquery'), '1.8.3');
            wp_enqueue_style('wp-bootstrap-config', plugin_dir_url(__FILE__) . 'css/wp_bootstrap_config.css');
            //wp_enqueue_script('underscore', plugin_dir_url(__FILE__) . 'js/underscore.js');
            wp_enqueue_script('underscore');
            wp_enqueue_script('bootstrap', plugin_dir_url(__FILE__) . 'js/bootstrap.min.js', array('jquery'));
            wp_enqueue_script('less', plugin_dir_url(__FILE__) . 'js/less-1.7.3.js');
            wp_enqueue_script('wp-bootstrap-config', plugin_dir_url(__FILE__) . 'js/bootstrap-config.js', array('jquery', 'bootstrap', 'underscore', 'less'), null, true);
        }
    }
    
    function get_less_configs(){
        $configs = apply_filters(Less_Config::FILTER_NAME, array());
        $configs = array_map(function($c){
            if($c->template){
                $template_filename = __DIR__;
                $template_filename = sprintf('%1$s/templates/%2$s.json', realpath(__DIR__), $c->template);
                $template_filename = realpath($template_filename);
                $contents = file_get_contents($template_filename);
                $template_values = (array)json_decode($contents);
                $template_values['path'] = $template_filename;
                $template_values['url'] = '';
                $template = new Less_Configuration($template_values);
                $template = $template->merge($c);
            }else{
                $template = $c;
            }
            return $template;
        }, $configs);
        return $configs;
    }
    
    function add_admin_menu(){
        add_submenu_page('themes.php', 'LESS Configuration', 'LESS Configuration', 'manage_options', Less_Config::PAGE_NAME, array($this, 'admin_page') );
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

    protected function get_css_filename($css_url, $version){
        $wpurl = get_bloginfo('wpurl');
        if(strpos($css_url, get_bloginfo('wpurl')) === 0){
            $css_url = '~/' . substr($css_url, strlen($wpurl));
        }
        return md5($css_url) . '-' . $version . '.css';
    }
    
    static function canonicalize($address)
    {
        $address = explode('/', $address);
        $keys = array_keys($address, '..');

        foreach($keys as $keypos => $key)
        {
            array_splice($address, $key - ($keypos * 2 + 1), 2);
        }

        $address = implode('/', $address);
        $address = str_replace('./', '', $address);
        return $address;
    }
    
    function string_slugify($string){
        return preg_replace('/[^a-zA-Z0-9]+/', '-', $string);
    }
    
    function filter_styles($handles){
        global $wp_styles;
        $uploaddir = $this->get_output_dir();

        if(is_admin()){return $handles;}
        
        $configs = $this->get_less_configs();
        //Construct an array where the full url of the CSS file is the key and the LESS relative path is the value
        $style_urls = array();
        
        foreach($configs as $c){
            foreach($c->entrypoint_files as $key => $value){
                $url = '';
                if(preg_match('/^https?\:\/\//', $value)){
                    $url = $value;   
                }else{
                    $url = Less_Config::canonicalize(substr($c->url, 0, strrpos($c->url, '/')) . '/' . $value);
                }

                $style_urls[] = $url;
            }
        }
        foreach($handles as $h){
            $s = $wp_styles->registered[$h];
            if(in_array($s->src, $style_urls)){
                $filename = $this->get_output_dir() . '/' . $this->get_css_filename($s->src, null);
                printf('<!-- stylesheet %1$s :: %2$s-->', $s->src, $this->get_css_filename($s->src, null));
                if(file_exists($filename)){
                    $s->src = $this->get_output_url() . '/' . $this->get_css_filename($s->src, null);
                }
            }
        }
        
        return $handles;
    }
    
    protected function save_settings($values){
        $configs = $this->get_less_configs();
        $next = array();
        $current = array();
        foreach($configs as $c){
            foreach($c->variables as $v){
                $current[$v->name] = $v->default_value;
            }
        }
        
        foreach($values as $key => $value){
            $curr = $current[$key];
            if($curr != $value){
               $next[$key] = $value;
            }
        }
        
        update_option(Less_Config::CURRENT_SETTINGS_OPTION, $next);
    }
    
    protected function get_variables_with_values(){
        $configs = $this->get_less_configs();
        $values = array();
        foreach($configs as $c){
            foreach($c->variables as $v){
                if(array_key_exists($v->name, $values)){
                    $values[$v->name] = $values[$v->name].merge($v);
                }else{
                    $values[$v->name] = $v;
                }
                
                $values[$v->name]->value = $v->default_value;
            }
        }
        
        $curr_settings = get_option(Less_Config::CURRENT_SETTINGS_OPTION, array());
        foreach($curr_settings as $key => $val){
            $values[$key]->value = $val;
        }
        
        return $values;
    }
    
    protected function get_settings(){
        $configs = $this->get_less_configs();
        $values = array();
        foreach($configs as $c){
            foreach($c->variables as $v){
                $values[$v->name] = $v->default_value;
            }
        }
        
        $curr_settings = get_option(Less_Config::CURRENT_SETTINGS_OPTION, array());
        foreach($curr_settings as $key => $val){
            $values[$key] = $val;
        }
        
        return $values;
    }
    
    function create_less_files(){
        $settings = $_POST['settings'];
        $settings = array_map(function($item){
            return str_replace('\"', '"', $item);
        }, $settings);
        $this->save_settings($settings);
        $configs = $this->get_less_configs();
        $compiled = array();
        foreach($configs as $c){
            try{
                $temp = $c->compile($settings);
                foreach($temp as $key => $val){
                    $k = $this->canonicalize(substr($c->url, 0, strrpos($c->url, '/')) . '/' . $c->entrypoint_files[$key]);
                    $compiled[$k] = $val;
                }
                //$compiled = array_merge($compiled, $c->compile($settings));
                
            }catch(Exception $e){
                $success = false;
            }
        }

//        if($success){
//            foreach($compiled as $url => $file){
//                $filename = $this->get_css_filename($url, null);
//                file_put_contents($this->get_output_dir() . '/' . $filename, $file);
//            }
//        }
        
        header('HTTP/1.0 200 OK');
        header('Content-Type: application/json');
        print json_encode( $compiled );
        wp_die();
    }
    
    function save_css_files(){
        $compiled = $_POST['files'];
        $result = array();
        foreach($compiled as $url => $contents){
            $filename = $this->get_css_filename($url, null);
            $result[$url] = $filename;
            file_put_contents($this->get_output_dir() . '/' . $filename, $contents);
        }
        
        header('HTTP/1.0 200 OK');
        header('Content-Type: application/json');
        print json_encode( $result );
        wp_die();
    }
    
    function admin_page(){
        
//        $dirs = scandir($this->get_output_dir());
//        print '<ul>';
//        foreach($dirs as $d){
//            printf('<li>%1$s</li>', $d);
//        }
//        print '</ul>';
        
        printf('<form id="less-config" method="POST" action="%1$s" data-save-url="%2$s"><div class="wrap">', get_bloginfo('wpurl') . '/wp-admin/admin-ajax.php?action=create_less_files', get_bloginfo('wpurl') . '/wp-admin/admin-ajax.php?action=save_css_files');

        $vars = $this->get_variables_with_values();
        $vars_by_cat = array();
        foreach($vars as $v){
            if(!array_key_exists($v->category, $vars_by_cat)){
                $vars_by_cat[$v->category] = array();   
            }
            
            $vars_by_cat[$v->category][] = $v;
        };
        
        $columns = array();
        $columns[0] = '';
        $columns[1] = '';
        $columns[2] = '';
        $columns[3] = '';
        $index = 0;
        foreach($vars_by_cat as $cat => $cat_vars){
            ob_start();
            ?>
            <div class="panel panel-default">
                <div class="panel-heading">
                  <h4 class="panel-title">
                      <a data-toggle="collapse" href="#collapse-<?php print $this->slugify($cat) ?>"><?php print $cat; ?></a>
                  </h4>
                </div>
                <div id="collapse-<?php print $this->slugify($cat) ?>" class="panel-collapse collapse">
                    <div class="panel-body">
<?php
            foreach($cat_vars as $v){
                $field = $this->get_input_field($v);
                print $field;
            }
?>
                    </div>
                </div>
            </div>
<?php
            $columns[$index % 4] .= ob_get_contents();
            ob_end_clean();
            $index++;
        }
        
        printf('<div class="row"><div class="col-md-3">%1$s</div><div class="col-md-3">%2$s</div><div class="col-md-3">%3$s</div><div class="col-md-3">%4$s</div></div>', $columns[0], $columns[1], $columns[2], $columns[3]);
        print '<button type="submit" class="btn btn-primary">Save Settings</button>';
        print '</div></form>';
    }
    
    function get_input_field($item){
        $input = null;
        switch($item->type)
        {
            default:
                $input = sprintf('<input type="text" name="settings[%1$s]" class="%2$s" value="%3$s"/>', htmlspecialchars($item->name), htmlspecialchars($item->type), htmlspecialchars($item->value));
                break;
        }

        $title = $item->title;
        return sprintf('<div class="form-field %2$s"><label><a href="#" data-toggle="tooltip" title="%4$s (%5$s)">%1$s</a></label>%3$s</div>', $title, $item->type, $input, $item->name, $item->type);
    }
    
    function slugify($str){
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9]+/', '-', $str);
        return $str;
    }
}

$less_config = new Less_Config();
add_filter(Less_Config::FILTER_NAME, function($configs){
    $file_name = get_stylesheet_directory() . '/less-config.json';
    if(file_exists($file_name)){
        $template_values = (array)json_decode(file_get_contents($file_name));
        $template_values['path'] = realpath($file_name);
        $template_values['url'] = get_stylesheet_directory_uri() . '/less-config.json';
        $configs[] = new Less_Configuration($template_values);        
        return $configs;
    }
}, 10);


