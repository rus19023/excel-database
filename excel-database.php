<?php
/**
 * Plugin Name: EXCEL database
 * License:     GPL3
 *
 */

/*
This file is part of the EXCEL database.

The EXCEL database is free software: you can
redistribute it and/or modify it under the terms of the GNU
General Public License as published by the Free Software
Foundation, either version 3 of the License, or
(at your option) any later version.

The EXCEL database is distributed in the hope that
it will be useful, but WITHOUT ANY WARRANTY; without even the
implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
PURPOSE.  See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with the EXCEL database.
If not, see <https://www.gnu.org/licenses/>.
 */


defined('ABSPATH') or die('Unauthorized access!');
require_once __DIR__.'/spout/src/Spout/Autoloader/autoload.php';
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;

add_action( 'init', 'excel_database_rewrite_rule');
add_shortcode( 'excel_database', 'excel_database_shortcode' );
if ( is_admin() ){ // admin actions
    add_action( 'admin_menu', 'excel_database_menu' );
    add_action( 'admin_init', 'register_excel_database_settings' );
}

add_filter( 'query_vars', 'excel_database_add_query' );
function excel_database_add_query( $vars )
{
    $vars[] = 'item';
    $vars[] = 'search';
    $vars[] = 'query';
    $vars[] = 'start';
    return $vars;
}

function excel_database_rewrite_rule() {
    $page = get_option('excel_database_page');
    $page_id = get_option('excel_database_page_id');
    if ($page !== false && $page_id !== false) {
        add_rewrite_rule(
            '^'.urlencode($page).'/item/([^/]*)/?',
            'index.php?page_id='.$page_id.'&item=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^'.urlencode($page).'/search/query=([^/&]*)(&start=([^/]*))?/?',
            'index.php?page_id='.$page_id.'&search=1&query=$matches[1]&start=$matches[3]',
            'top'
        );
        flush_rewrite_rules();
    }
}

//[foobar]
function excel_database_shortcode( $atts ){
    $db_url = get_option('excel_database_url');
    $primary_key_idx = get_option('excel_database_primary') - 1;
    $page = get_option('excel_database_page');
    $items_on_page = get_option('excel_database_items_on_page');
    if (empty($items_on_page)) $items_on_page = 10;
    $page_url = get_site_url(null,'/'.urlencode($page));
    $upload_dir = wp_upload_dir();
    $db_file = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $db_url);


    $project = get_query_var( 'item' );
    $search = get_query_var( 'search' );
    $query = get_query_var( 'query' );
    $page_no = get_query_var( 'start' );
    $search_form =  '<form role="search" method="get" id="excel_database_search"'."\n\t".
                'class="search-form" action="'.$page_url.'">'."\n\t".
            '<label>'."\n\t\t".
                '<span class="screen-reader-text">Search for:</span>'."\n\t\t".
                '<input type="search" class="search-field" placeholder="Search …" value="" name="query"/>'."\n\t".
                '<input type="hidden" value="1" name="search"/>'."\n\t".
            '</label>'."\n\t".
            '<input type="submit" class="search-submit"'."\n\t\t".
                'value="Search database" />'."\n\t".
            '</form>'."\n";
    if (empty($search) && empty($project))
        return $search_form;
    $out = "";
    //echo "Page No: '$page_no'";
    if (empty($page_no) || $page_no <= 0) $page_no = 1;
    $start = ($page_no - 1) * $items_on_page + 1;
    //if (isset($search) && !empty($search)) {
    //    $out .= "<p>Searching for $query starting at $start</p>";
    //}
    $count = 0;
    $single = isset($project) && !empty($project);
    $template_id = -1;
    if ($single) {
        $count = get_option('excel_database_full');
        $template_id = get_option('excel_database_template_page_id');
    //    $out .= "<p><a href='$page_url'>Back to $page.</a></p>";
    } else {
        $count = get_option('excel_database_summary');
        $template_id = get_option('excel_database_short_template_page_id');
    }
    if ($count == 0) {
        $template = get_post($template_id);
    }
    $fieldcount = 0;
    $headrow = array();
    $description = array();
    $entries = array();
    $idx = 0;
    $valid = function ($key, $current) use ($project, $start, & $idx, $query, $headrow, $items_on_page) {
        if(isset($project) && !empty($project))
            return $project == $key;
        $notfound = true;
        if (!empty($query)) {
            foreach ($current as $field)
                if (strpos($field, $query) !== false) {
                    $notfound = false;
                    break;
                }
            if ($notfound) return false;
        }
        $idx ++;
        if(intval($idx) < intval($start) || intval($idx) >= intval($start)+$items_on_page) {
            return false;
        }

        return true;
    };
    $fieldcount = excel_database_read($db_file, $primary_key_idx, $headrow, $description, $entries);
    $entries = excel_database_query($valid, $entries);
    $pages = ceil($idx / $items_on_page);
    if ($pages < $page_no) $page_no = $pages + 1;
    $navigation_links = excel_database_navigation_links($page_url, $query, $page_no, $items_on_page, $idx);
    $out .= $navigation_links;
    /*
    $js_url = plugins_url('excel-database.js', __FILE__ );
    wp_register_script('excel-database-js', $js_url);

    wp_localize_script('excel-database-js', 'entries', $entries);

    wp_enqueue_script('jquery','',array('json2'));
    wp_enqueue_script('excel-database-js', '', array('jquery'));


    if (!$single) {
        echo '<div class="entries-search">';
        echo '    <input type="text" name="input-filter" class=form-control id="input-filter" placeholder="Filter results">';
        echo '</div>';
    }
     */
    foreach ($entries as $key => $current) {
        if (!$single) {
            $href = get_site_url(null,'/'.urlencode($page).'/item/'.urlencode($key));
        } else {
            $href = null;
        }
        if ($count != 0) {
            $out .= excel_database_default_format($headrow, $description, $fieldcount, $count, $current, $href);
            $out .= '<hr style="    max-width: 100%;"/>';
        } else {
            $out .= excel_database_template_format($headrow, $fieldcount, $template->post_content, $current, $href);
        }
    }
    $out .= $navigation_links;
    return $out;
}

function excel_database_default_format($headrow, $description, $fieldcount, $count, $current, $href) {
    $out = "<dl class='excel_database_row'>";
    for ($i = 0; $i < $count; $i++) {
        $hkey = $headrow[$i];
        $value = excel_database_get_value($current, $hkey, isset($href) ? $href : null);
        $desc = $description[$hkey];
        if (!empty($value)) {
            $out .= "<dt>".$desc."</dt>";
            $out .= "<dd>";
            $out .= $value;
            $out .= "</dd>";
        }
        unset($href);
    }
    $out .= "</dl>";
    return $out;
}

function excel_database_template_format($headrow, $fieldcount, $template, $current, $href) {
    for ($i = 0; $i < $fieldcount; $i++) {
        $hkey = $headrow[$i];
        $value = excel_database_get_value($current, $hkey);
        if (!empty($value)) {
            $template = str_replace('{{'.$hkey.'}}', $value, $template);
        }
    }
    if (!empty($href)) {
        $href = substr($href,strlen("http://"));
        $template = str_replace('{{href}}', $href, $template);
    }
    return apply_filters( 'the_content', $template );
}

function excel_database_navigation_links($page_url, $query, $page_no, $items_on_page, $idx) {
    $out = "";
    if ($idx <= $items_on_page) return $out;
    $link = $page_url.'/search/query='.urlencode($query).'&start=';
    if ($page_no > 1) {
        $left = $page_no - 1;
        $out .= '<a href="'.$link.urlencode($left).'">Previous</a> ';
    }
    $pages = ceil($idx / $items_on_page);
    $out .= 'Page '.$page_no.' of '.$pages;
    if ($page_no < $pages) {
        $right = $page_no + 1;
        $out .= ' <a href="'.$link.urlencode($right).'">Next</a>';
    }
    return '<p>'.$out.'</p>';
}

function excel_database_read($db_file, $primary_key_idx, & $headrow, & $description, & $entries) {
    $reader = ReaderEntityFactory::createReaderFromFile($db_file);
    $reader->open($db_file);
    foreach ($reader->getSheetIterator() as $sheet) {
        $rowc = 0;
        foreach ($sheet->getRowIterator() as $row) {
            $current = array();
            foreach ($row->getCells() as $cell) {
                $current[] = $cell->getValue();
            }
            if ($rowc == 0) {
                $headrow = $current;
                $rowc = 1;
                continue;
            }
            if ($rowc == 1) {
                for ($i = 0; !empty($headrow[$i]); $i++) {
                    $description[$headrow[$i]] = $current[$i];
                }
                $fieldcount = $i;
                $rowc = 2;
                continue;
            }
            $primary_key = $current[$primary_key_idx];
            if (!isset($entries[$primary_key])) {
                $entries[$primary_key] = array();
            }
            for ($i = 0; $i < $fieldcount; $i++) {
                if (!empty($current[$i])) {
                    if (empty($entries[$primary_key][$headrow[$i]])) {
                        $entries[$primary_key][$headrow[$i]] = $current[$i];
                    } else if (strpos(strval($entries[$primary_key][$headrow[$i]]), strval($current[$i])) === false) {
                        $entries[$primary_key][$headrow[$i]] .= "; ".$current[$i];
                    }
                }
            }
        }
        break; // only reads first sheet
    }
    $reader->close();
    return $fieldcount;
}

function excel_database_query($validate, & $entries) {
    $result = array();
    foreach ($entries as $key => $current) {
        if ($validate($key, $current)) {
            $result[$key] = $current;
        }
    }
    return $result;
}

function excel_database_get_value(& $current, $hkey, $href = null) {
    $value = isset($current[$hkey]) ? $current[$hkey] : null;
    if (!empty($value)) {
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $value = '<a href="mailto:'.$value.'">'.$value.'</a>';
        } else if (filter_var($value, FILTER_VALIDATE_URL)) {
            $value = '<a href="'.$value.'">'.$value.'</a>';
        }
        if (isset($href)) $value = '<a href="'.$href.'">'.$value.'</a>';
    }
    return $value;
}


function excel_database_filter_the_title( $title ) {
    $page = get_option('excel_database_page');
    $project = get_query_var( 'project' );
    if ( is_page( $page ) && isset($project) && !empty($project) ) {
        return $project;
    }
    return $title;
}
add_filter( 'the_title', 'excel_database_filter_the_title' );


function register_excel_database_settings() { // whitelist options
    register_setting( 'excel-database-option-group', 'excel_database_page_id' );
    register_setting( 'excel-database-option-group', 'excel_database_template_page_id' );
    register_setting( 'excel-database-option-group', 'excel_database_short_template_page_id' );
    register_setting( 'excel-database-option-group', 'excel_database_url' );
    register_setting( 'excel-database-option-group', 'excel_database_page' );
    register_setting( 'excel-database-option-group', 'excel_database_primary' );
    register_setting( 'excel-database-option-group', 'excel_database_summary' );
    register_setting( 'excel-database-option-group', 'excel_database_full' );
    register_setting( 'excel-database-option-group', 'excel_database_items_on_page' );

    // get the value of the setting we've registered with register_setting()
    $page_id = get_option('excel_database_page_id');
    $template_page_id = get_option('excel_database_template_page_id');
    $short_template_page_id = get_option('excel_database_short_template_page_id');

    if ($page_id !== false) {
        $post = get_post($page_id);
        if ($post === null || $post->post_status != 'publish')
            $page_id = false;
    } 

    $page = get_option('excel_database_page');
    if ($page !== false) {
        $my_post = array(
            'post_title'    => ucfirst($page),
            'post_type'     => 'page',
            'post_content'  => '',
            'post_status'   => 'publish',
            'post_author'   => 1,
        );
        if (empty($page_id)) {
            $my_post['post_content'] = "[excel_database]";
            $my_post['post_title'] = ucfirst($page);
            // Create post object
            $page_id = wp_insert_post($my_post, true);
            if (is_wp_error($page_id)) {
                echo $page_id->get_error_message();
            } else {
                update_option('excel_database_page_id', $page_id);
            }
        }
        if (empty($short_template_page_id)) {
            $my_post['post_content'] = "";
            $my_post['post_title'] = ucfirst($page." short template");
            $short_template_page_id = wp_insert_post($my_post, true);
            if (is_wp_error($short_template_page_id)) {
                echo $short_template_page_id->get_error_message();
            } else {
                update_option('excel_database_short_template_page_id', $short_template_page_id);
            }
        }
        if (empty($template_page_id)) {
            $my_post['post_content'] = "";
            $my_post['post_title'] = ucfirst($page." template");
            $template_page_id = wp_insert_post($my_post, true);
            if (is_wp_error($template_page_id)) {
                echo $template_page_id->get_error_message();
            } else {
                update_option('excel_database_template_page_id', $template_page_id);
            }
        }
    }

    // register a new section in the "group" page
    add_settings_section(
        'excel_database_settings_section',
        'Excel Database Settings Section',
        'excel_database_settings_section_cb',
        'excel-database-option-group'
    );

    // register a new field in the section, inside the "group" page
    add_settings_field(
        'excel_database_settings_url_field',
        'Excel Database URL',
        'excel_database_settings_url_field_cb',
        'excel-database-option-group',
        'excel_database_settings_section'
    );

    // register a new field in the section, inside the "group" page
    add_settings_field(
        'excel_database_settings_page_field',
        'Excel Database Main Page prefix',
        'excel_database_settings_page_field_cb',
        'excel-database-option-group',
        'excel_database_settings_section'
    );

    // register a new field in the section, inside the "group" page
    add_settings_field(
        'excel_database_settings_primary_field',
        'Excel Database Unique ID column no',
        'excel_database_settings_primary_field_cb',
        'excel-database-option-group',
        'excel_database_settings_section'
    );

    // register a new field in the section, inside the "group" page
    add_settings_field(
        'excel_database_settings_summary_field',
        'Excel Database number of columns for summary',
        'excel_database_settings_summary_field_cb',
        'excel-database-option-group',
        'excel_database_settings_section'
    );

    // register a new field in the section, inside the "group" page
    add_settings_field(
        'excel_database_settings_full_field',
        'Excel Database number of columns for full record',
        'excel_database_settings_full_field_cb',
        'excel-database-option-group',
        'excel_database_settings_section'
    );

    // register a new field in the section, inside the "group" page
    add_settings_field(
        'excel_database_settings_items_on_page_field',
        'Excel Database number of item to display per page',
        'excel_database_settings_items_on_page_field_cb',
        'excel-database-option-group',
        'excel_database_settings_section'
    );

    // register a new field in the section, inside the "group" page
    add_settings_field(
        'excel_database_settings_page_id_field',
        'Excel Database Main Page id',
        'excel_database_settings_page_id_field_cb',
        'excel-database-option-group',
        'excel_database_settings_section'
    );


    // register a new field in the section, inside the "group" page
    add_settings_field(
        'excel_database_settings_template_page_id_field',
        'Excel Database Template Page id',
        'excel_database_settings_template_page_id_field_cb',
        'excel-database-option-group',
        'excel_database_settings_section'
    );

    // register a new field in the section, inside the "group" page
    add_settings_field(
        'excel_database_settings_short_template_page_id_field',
        'Excel Database Short Template Page id',
        'excel_database_settings_short_template_page_id_field_cb',
        'excel-database-option-group',
        'excel_database_settings_section'
    );


}

/**
 * callback functions
 */

// section content cb
function excel_database_settings_section_cb()
{
    $page_id = get_option('excel_database_page_id');
    $template_page_id = get_option('excel_database_template_page_id');
    $short_template_page_id = get_option('excel_database_short_template_page_id');
    /*
    echo '<p>'.$page.'<br/>'.$page_id.'</p>';
     */

    if ($page_id !== false) {
        echo '<p><a href="'.get_site_url(null,'/wp-admin/post.php?post='.$page_id.'&action=edit').'">Customize main page</a></p>'; 
        echo '<p><a href="'.get_site_url(null,'/wp-admin/post.php?post='.$template_page_id.'&action=edit').'">Customize template</a></p>'; 
        echo '<p><a href="'.get_site_url(null,'/wp-admin/post.php?post='.$short_template_page_id.'&action=edit').'">Customize short template</a></p>'; 
    }
}

// field content cb
function excel_database_settings_url_field_cb()
{
    // get the value of the setting we've registered with register_setting()
    $setting = get_option('excel_database_url');
    // output the field
    echo '<input type="url" size="80" name="excel_database_url" value="'.($setting ? esc_attr( $setting ) : '').'">';
}

// field content cb
function excel_database_settings_page_field_cb()
{
    // get the value of the setting we've registered with register_setting()
    $setting = get_option('excel_database_page');
    // output the field
    echo '<input type="text" name="excel_database_page" value="'.(isset( $setting ) ? esc_attr( $setting ) : '').'">';
}

// field content cb
function excel_database_settings_primary_field_cb()
{
    // get the value of the setting we've registered with register_setting()
    $setting = get_option('excel_database_primary');
    // output the field
    echo '<input type="number" name="excel_database_primary" value="'.(isset( $setting ) ? esc_attr( $setting ) : '').'">';
}

// field content cb
function excel_database_settings_summary_field_cb()
{
    // get the value of the setting we've registered with register_setting()
    $setting = get_option('excel_database_summary');
    // output the field
    echo '<input type="number" name="excel_database_summary" value="'.(isset( $setting ) ? esc_attr( $setting ) : '').'">';
}

// field content cb
function excel_database_settings_full_field_cb()
{
    // get the value of the setting we've registered with register_setting()
    $setting = get_option('excel_database_full');
    // output the field
    echo '<input type="number" name="excel_database_full" value="'.(isset( $setting ) ? esc_attr( $setting ) : '').'">';
}


// field content cb
function excel_database_settings_items_on_page_field_cb()
{
    // get the value of the setting we've registered with register_setting()
    $setting = get_option('excel_database_items_on_page');
    // output the field
    echo '<input type="number" name="excel_database_items_on_page" value="'.(isset( $setting ) ? esc_attr( $setting ) : '').'">';
}

// field content cb
function excel_database_settings_page_id_field_cb()
{
    // get the value of the setting we've registered with register_setting()
    $setting = get_option('excel_database_page_id');
    // output the field
    echo '<input type="number" name="excel_database_page_id" value="'.(isset( $setting ) ? esc_attr( $setting ) : '').'">';
}


// field content cb
function excel_database_settings_template_page_id_field_cb()
{
    // get the value of the setting we've registered with register_setting()
    $setting = get_option('excel_database_template_page_id');
    // output the field
    echo '<input type="number" name="excel_database_template_page_id" value="'.(isset( $setting ) ? esc_attr( $setting ) : '').'">';
}

// field content cb
function excel_database_settings_short_template_page_id_field_cb()
{
    // get the value of the setting we've registered with register_setting()
    $setting = get_option('excel_database_short_template_page_id');
    // output the field
    echo '<input type="number" name="excel_database_short_template_page_id" value="'.(isset( $setting ) ? esc_attr( $setting ) : '').'">';
}



/** Step 1. */
function excel_database_menu() {
    add_options_page( 'Excel Database Options', 'Excel Database', 'manage_options', 'excel-database-options-page', 'excel_database_options' );
}

/** Step 3. */
function excel_database_options() {
    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    echo '<div class="wrap">';
    echo '<h1>Excel database options page</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields( 'excel-database-option-group' );
    do_settings_sections( 'excel-database-option-group' );
    submit_button('Save settings');
    echo '</form>';
    echo '</div>';
}
?>
