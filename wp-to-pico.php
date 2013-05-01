<?php
/*
Plugin Name: WordPress to Pico Exporter
Description: Exports WordPress posts to Markdown files for http://pico.dev7studios.com
Version: 1.0
Author: Gilbert Pellegrom
Author URI: http://gilbert.pellegrom.me
License: GPLv3 or Later

Based on https://github.com/benbalter/wordpress-to-jekyll-exporter
*/

class WP_To_Pico_Export {

	private $zip_folder = 'wp-to-pico-export/'; //folder zip file extracts to

	/**
	 * Hook into WP Core
	 */
	function __construct() {
		add_action( 'admin_menu', array( &$this, 'register_menu' ) );
		add_action( 'current_screen', array( &$this, 'callback' ) );
	}

	/**
	 * Listens for page callback, intercepts and runs export
	 */
	function callback() {
		if ( !isset( $_GET['page'] ) || $_GET['page'] != 'wp-to-pico-export' )
			 return;
	
		if ( !current_user_can( 'manage_options' ) )
			 return;
			 
		if ( empty($_POST) )
			return;
	
		$this->export();
		exit();
	}


	/**
	 * Add menu option to tools list
	 */
	function register_menu() {
		add_management_page( __( 'Export to Pico', 'wp-to-pico-export' ), __( 'Export to Pico', 'wp-to-pico-export' ), 'manage_options', 'wp-to-pico-export', array( &$this, 'page' ) );
	}
	
	function page()
	{
		echo '<div class="wrap"><div id="icon-tools" class="icon32"></div>
		<h2>WordPress to Pico Exporter</h2></div>
		<br /><form method="post" action=""><p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Download Export File"></p></form>';
	}


	/**
	 * Get an array of all post and page IDs
	 * Note: We don't use core's get_posts as it doesn't scale as well on large sites
	 */
	function get_posts() {
		global $wpdb;
		return $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'post'" );
	}


	/**
	 * Convert a posts meta data (both post_meta and the fields in wp_posts) to key value pairs for export
	 */
	function convert_meta( $post ) {
		$categories = get_the_category( $post->ID );
		$output = array(
			 'title'		=> get_the_title( $post->ID ),
			 'author'		=> get_userdata( $post->post_author )->display_name,
			 'date'			=> get_the_time( 'Y/m/d', $post )
		);
	
		return $output;
	}

	/**
	 * Convert the main post content to Markdown.
	 */
	function convert_content( $post ) {
		$content = apply_filters( 'the_content', $post->post_content );

		require_once plugin_dir_path(__FILE__) .'markdownify_extra.php';
		$md = new Markdownify_Extra;
		return $md->parseString($content);
	}

	/**
	 * Loop through and convert all posts to MD files with YAML headers
	 */
	function convert_posts() {
		global $post;
	
		foreach ( $this->get_posts() as $postID ) {
			 $post = get_post( $postID );
			 setup_postdata( $post );
	
			 $meta = $this->convert_meta( $post );
	
			 $output = "/*\n";
			 $output .= 'Title: '. $meta['title'] ."\n";
			 $output .= 'Author: '. $meta['author'] ."\n";
			 $output .= 'Date: '. $meta['date'] ."\n";
			 $output .= "*/\n\n";
	
			 $output .= $this->convert_content( $post );
			 $this->write( $output, $post );
		}
	}

	function filesystem_method_filter() {
		return 'direct';
	}

	/**
	 * Main function, bootstraps, converts, and cleans up
	 */
	function export() {
		global $wp_filesystem;
	
		define( 'DOING_PICO_EXPORT', true );
	
		add_filter( 'filesystem_method', array( &$this, 'filesystem_method_filter' ) );
	
		WP_Filesystem();
	
		$temp_dir = get_temp_dir();
		$this->dir = $temp_dir . 'wp-pico-' . md5( time() ) . '/';
		$this->zip = $temp_dir . 'wp-pico.zip';
		$wp_filesystem->mkdir( $this->dir );
		$wp_filesystem->mkdir( $this->dir . 'content/' );
	
		$this->convert_posts();
		$this->zip();
		$this->send();
		$this->cleanup();
	}


	/**
	 * Write file to temp dir
	 */
	function write( $output, $post ) {
		global $wp_filesystem;
	
		$filename = 'content/'. $post->post_name .'.md';
		$wp_filesystem->put_contents( $this->dir . $filename, $output );
	}


	/**
	 * Zip temp dir
	 */
	function zip() {
		//create zip
		$zip = new ZipArchive();
		$zip->open( $this->zip, ZIPARCHIVE::CREATE );
		$this->_zip( $this->dir, $zip );
		$zip->close();
	}


	/**
	 * Helper function to add a file to the zip
	 */
	function _zip( $dir, &$zip ) {
		//loop through all files in directory
		foreach ( glob( trailingslashit( $dir ) . '*' ) as $path ) {
			 if ( is_dir( $path ) ) {
			 	$this->_zip( $path, $zip );
			 	continue;
			 }
	
			 //make path within zip relative to zip base, not server root
			 $local_path = '/' . str_replace( $this->dir, $this->zip_folder, $path );
	
			 //add file
			 $zip->addFile( realpath( $path ), $local_path );
		}
	}


	/**
	 * Send headers and zip file to user
	 */
	function send() {
		//send headers
		@header( 'Content-Type: application/zip' );
		@header( "Content-Disposition: attachment; filename=wp-pico.zip" );
		@header( 'Content-Length: ' . filesize( $this->zip ) );
	
		//read file
		readfile( $this->zip );
	}


	/**
	 * Clear temp files
	 */
	function cleanup( ) {
		global $wp_filesystem;
	
		$wp_filesystem->delete( $this->dir, true );
		$wp_filesystem->delete( $this->zip );
	}

}
$wtpe = new WP_To_Pico_Export();