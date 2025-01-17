<?php
/**
 * File: sosere-controller.php
 * Class: Sosere_Controller
 * Description: Main plugin controller
 *
 * Text Domain: social-semantic-recommendation-sosere
 * Domain Path: /sosere_languages/
 * @package sosere 
 * @author Arthur Kaiser <social-semantic-recommendation@sosere.com>
 */
/*
 * avoid to call it directly
 */
if ( ! function_exists( 'add_action' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
} // end: if(!function_exists('add_action'))

if ( ! class_exists( 'Sosere_Controller' ) ) {

	class Sosere_Controller
	{
		// class vars
		public $max_view_history = 30; // in days
		
		public $max_post_age = 1000;

		public $max_results = 3;

		private $taxonomy_selection = array();

		private $category_selection = array();

		private $user_selection = array();

		private $viewed_post_IDs = array();

		private $use_cache = false;

		private $max_cache_time = 1;

		private $recommendation_box_title_default = 'Recommended for you';

		private $included_post_types = 'post';
		
		private $show_on_page_types = array('post'); 

		private $browser_locate;

		private $plugin_options_name = 'plugin_sosere';

		private $array_sosere_options;

		private $prefetch_request = false;

		private $show_thumbs_title = false;

		private $title_leng = 50;

		private $show_thumbs = false;

		private $sosere_custom_thumbnail_size = '150x150';

		private $default_thumbnail_img_url = null;

		private $use_custom_css = false;

		private $hide_output = false;

		private $dnt = null;

		private $data_sources = array('tag'=>'tag', 'category'=>'category', 'session'=>'session');
		/**
		 * PHP 5 Object Constructor
		 *
		 * @since 1.0
		 * @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		 */
		function __construct() {
			if ( ! is_admin() ) {
				$this->array_sosere_options = get_option( $this->plugin_options_name );
				
				if ( isset( $this->array_sosere_options['use_cache'] ) && 'on' == $this->array_sosere_options['use_cache'] ) $this->use_cache = true;
				if ( isset( $this->array_sosere_options['max_cache_time'] ) ) $this->max_cache_time = (int) $this->array_sosere_options['max_cache_time'];
				if ( isset( $this->array_sosere_options['recommendation_box_title_default'] ) ) $this->recommendation_box_title_default = $this->array_sosere_options['recommendation_box_title_default'];
				if ( isset( $this->array_sosere_options['recommendation_box_title'] ) ) $this->recommendation_box_title = $this->array_sosere_options['recommendation_box_title'];
				if ( isset( $this->array_sosere_options['show_thumbs'] ) && 1 == $this->array_sosere_options['show_thumbs'] ) $this->show_thumbs_title = true;
				if ( isset( $this->array_sosere_options['show_thumbs'] ) && 2 == $this->array_sosere_options['show_thumbs'] ) $this->show_thumbs = true;
				if ( isset( $this->array_sosere_options['sosere_custom_thumbnail_size'] ) && 0 < strlen( $this->array_sosere_options['sosere_custom_thumbnail_size'] ) ) $this->sosere_custom_thumbnail_size = $this->array_sosere_options['sosere_custom_thumbnail_size'];
				if ( isset( $this->array_sosere_options['default_thumbnail_img_url'] ) ) $this->default_thumbnail_img_url = $this->array_sosere_options['default_thumbnail_img_url'];
				
				if ( isset( $this->array_sosere_options['include_pages'] ) && 'on' == $this->array_sosere_options['include_pages'] ) $this->included_post_types = 'any';
				if ( isset( $this->array_sosere_options['use_custom_css'] ) && 'on' == $this->array_sosere_options['use_custom_css'] ) $this->use_custom_css = true;
				
				if ( isset( $this->array_sosere_options['show_on_page_types'] ) ) $this->show_on_page_types =  $this->array_sosere_options['show_on_page_types'];
				if ( isset( $this->array_sosere_options['hide_output'] ) && 'on' == $this->array_sosere_options['hide_output'] ) $this->hide_output = true;
				if ( isset( $this->array_sosere_options['result_count'] ) ) $this->max_results = (int) $this->array_sosere_options['result_count'];
				if ( isset( $this->array_sosere_options['max_view_history'] ) ) $this->max_view_history = (int) $this->array_sosere_options['max_view_history'];
				if ( isset( $this->array_sosere_options['max_post_age'] ) ) $this->max_post_age = (int) $this->array_sosere_options['max_post_age'];
				if ( isset( $this->array_sosere_options['data_source'] ) ) $this->data_sources = $this->array_sosere_options['data_source'] ;
				
				$this->now = time();
				
				global $post;
				$this->post = $post;
				$this->post_language = $this->get_current_language();
				
				// be sure a a session is available
				add_action( 'init', array( $this, 'sosere_start_session' ), 1 );
				add_action( 'wp_logout', array( $this, 'sosere_end_session' ) );
				add_action( 'wp_login', array( $this, 'sosere_end_session' ) );
				
				// get prefetch header
				$this->sosere_get_prefetch_header();
				
				// include frontend css
				add_action( 'wp_enqueue_scripts', array( $this, 'sosere_add_stylesheet' ) );
				
				// session handling
				add_action( 'shutdown', array( $this, 'sosere_handle_session' ), 9999 );
				
				// run
				add_filter( 'the_content', array( $this, 'sosere_run' ) );
				
			}
			// set browser locate
			if ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
				$this->browser_locate = explode( ';', $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
				$this->browser_locate = explode( ',', $this->browser_locate[0] );
			}
			
			// register thumbnail-image sizes
			// set custom thumbnail sizes
			if ( function_exists( 'add_image_size' ) && isset( $this->options['sosere_custom_thumbnail_size'] )) { 
				$sosere_thumb_size = explode('x', $this->options['sosere_custom_thumbnail_size']);
				add_image_size( 'sosere_thumb', $sosere_thumb_size[0], $sosere_thumb_size[1], true ); // wide, height, hard crop mode
			} 
		} // end constructor
		
		/**
		 * main function
		 *
		 * @since 1.0
		 * @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		 */
		public function sosere_run( $content ) {
			if ( ( is_single() || is_page() ) && !is_front_page() ) {
				
				if ( !in_array( get_post_type(), $this->show_on_page_types ) ) {
					return $content;
				} 
				
				if ( ! is_object( $this->post ) ) {
					global $post;
					$this->post = $post;
					$this->post_language = $this->get_current_language();
				}
				
				// add current post to network
				if ( (array_key_exists('session', $this->data_sources ) && $this->data_sources['session'] == 'session' ) && true !== $this->dnt ) {
					$this->add_post_to_db();
				}
				
				// get cached selection if used
				if ( true == $this->use_cache ) {
					$cached = get_post_meta( $this->post->ID, 'soseredbviewedpostscache' );
					$cachetime = get_post_meta( $this->post->ID, 'soseredbviewedpostscachedate' );
					
					// diff in hours
					if ( isset( $cachetime[0] ) ) {
						$cachetime = (int) $cachetime[0];
						$diff = ( $this->now - $cachetime ) / ( 60 * 60 );
					} else {
						$diff = null;
					}
					if ( $cached && 0 < $cachetime && 0 < $diff && $diff < $this->max_cache_time ) {
						if ( false === $this->hide_output ) {
							return $content . $cached[0];
						} else {
							return $content;
						}
					}
				}
				
				$db_selection = array();
				
				// get selections
				// distinct filter
				add_filter( 'posts_distinct', array( $this, 'search_distinct' ) );
				// wpml filter
				// add_filter( 'posts_join', array( $this, 'icl_join' ) );
				// xili language filter
				add_filter( 'posts_join', array( $this, 'xili_join' ) );
				// where filter
				add_filter( 'posts_where', array( $this, 'additional_filter' ) );
				
				if ( array_key_exists( 'tag', $this->data_sources ) && $this->data_sources['tag'] === 'tag' ) {
					// get tag id's
					$taxonomy_id_array = wp_get_post_tags( $this->post->ID, array( 'fields' => 'ids' ) );
				}
				if ( array_key_exists( 'category', $this->data_sources ) && $this->data_sources['category'] === 'category' ) {
					// get post categories
					$category_array = get_the_category( $this->post->ID );
					
					// get category id's
					$category_id_array = array();

					foreach ( $category_array as $category ) {
						$category_id_array[] = $category->cat_ID;
					}
				}
				$args_array = array( 
						'posts_per_page' 	=> 32 + $this->max_results + ( count( $category_id_array ) + 1 ) + count( $taxonomy_id_array ), 
						'post_type' 		=> explode( ',', $this->included_post_types ), 
						'post_status' 		=> 'publish', 
						'orderby' 			=> 'rand', 
						'suppress_filters'  => false, 
				);
				if ( is_array( $category_id_array ) 
						&& is_array( $taxonomy_id_array ) 
						&& 0 < count( $taxonomy_id_array ) 
						&& 0 < count( $category_id_array ) ) {
					$args_array['tax_query'] = array( 
											'relation' => 'OR', 
													array(  
														'taxonomy' => 'category', 
														'field'    => 'cat_ID',
														'terms' => $category_id_array, 
														), 
													array( 
														'taxonomy' => 'post_tag',
														'field' => 'term_id', 
														'terms' => $taxonomy_id_array, 
														),
					 );
				} elseif ( is_array( $taxonomy_id_array ) && 0 < count( $taxonomy_id_array ) ) {
					$args_array['tag__in'] = $taxonomy_id_array;
				} elseif ( is_array( $category_id_array ) && 0 < count( $category_id_array ) ) {
					$args_array['category__in'] = $category_id_array;
				}
				
				// fire query
				$posts_arr = get_posts( $args_array );
				
				// add to categories selection
				if ( isset( $posts_arr ) && is_array( $posts_arr ) ) {
					foreach ( $posts_arr as $post_obj ) {
						if ( is_object( $post_obj ) ) {
							$db_selection[] = (int) $post_obj->ID;
						}
					}
				}
				// distinct filter
				remove_filter( 'posts_distinct', array( $this, 'search_distinct' ) );
				// wpml filter
				// remove_filter( 'posts_join', array( $this, 'icl_join' ) );
				// xili filter
				remove_filter( 'posts_join', array( $this, 'xili_join' ) );
				// where filter
				remove_filter( 'posts_where', array( $this, 'additional_filter' ) );
				
				if ( 0 < count( $this->user_selection ) ) {
					// prepare and limit user selection
					shuffle( $this->user_selection );
					$slice_to =  32 + $this->max_results + ( count( $category_id_array ) + 1 ) + count( $taxonomy_id_array );
					$slice_user_selection = array_slice( $this->user_selection, 0, $slice_to, true );
					
					// slice db user data
					if ( (array_key_exists('session', $this->data_sources ) && $this->data_sources['session'] == 'session' ) && true !== $this->dnt ) {
						$this->slice_db_user_data( $slice_to );
					}
					
					// merge selections
					$all_selection = array_merge( $db_selection, $slice_user_selection );
				} else {
					$all_selection = $db_selection;
				}
				if( 0 < count( $all_selection ) ) {
					// get selected post id's
					$selected_post_IDs = $this->preferential_selection( $all_selection );
					
					if ( 0 < count( $selected_post_IDs ) ) {
						// get post content
						$selected_posts_arr = get_posts( array( 'include' => implode( ',', $selected_post_IDs ), 'post_type' => array( $this->included_post_types ), 'posts_per_page' => $this->max_results, 'suppress_filters' => true ) );
						
						$recommendation_string = $this->get_html_output( $selected_posts_arr );
					}
				} 
				// cache it in db if used
				if ( true == $this->use_cache ) {
					if ( isset( $selected_posts_arr ) && isset( $recommendation_string ) && 0 < strlen( $recommendation_string ) ) {
						add_post_meta( $this->post->ID, 'soseredbviewedpostscache', $recommendation_string, true ) or update_post_meta( $this->post->ID, 'soseredbviewedpostscache', $recommendation_string );
						add_post_meta( $this->post->ID, 'soseredbviewedpostscachedate', $this->now, true ) or update_post_meta( $this->post->ID, 'soseredbviewedpostscachedate', $this->now );
					}
				}
				if( isset( $recommendation_string ) && 0 < strlen( $recommendation_string ) ) {
					return $content . $recommendation_string;
				} else {
					return $content; 
				}
			} else {
				return $content;
			}
		}

		/**
		* Slice viewed posts data in db to dynamic value to prevent data garbage
		*
		* @since 2.3
		* @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		*/
		private function slice_db_user_data( $slice_to=32 ){
			$network_data = @unserialize( get_post_meta( $this->post->ID, 'soseredbviewedposts', true ) );
			if ( false !== $network_data && is_array ($network_data) ) {
				$new_network_data = array_slice($network_data, -1*$slice_to );
				
			}
			// safe to db
			if ( isset( $new_network_data ) && is_array( $new_network_data ) ) {
				$new_network_data_DB = serialize( $new_network_data );
			}
			
			if ( isset( $new_network_data_DB ) ) {
				add_post_meta( $this->post->ID, 'soseredbviewedposts', $new_network_data_DB, true ) or update_post_meta( $this->post->ID, 'soseredbviewedposts', $new_network_data_DB );
			}
		}
		/**
		 * Add actual seen post to posts network
		 *
		 * @since 1.0
		 * @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		 */
		private function add_post_to_db() {
			if ( isset( $_SESSION['sosereviewedposts'] ) && is_array( $_SESSION['sosereviewedposts'] ) ) {
				$this->viewed_post_IDs = $_SESSION['sosereviewedposts'];
			}
			$network_data = get_post_meta( $this->post->ID, 'soseredbviewedposts', true ) ;
			if ( false !== $network_data && is_array ($network_data) ) {
				
				foreach ( $network_data as $key => $network_data_set ) {
					if ( isset( $network_data_set['id'] ) && isset( $network_data_set['language'] ) && $network_data_set['id'] != $this->post->ID && $network_data_set['language'] == $this->post_language) {
						if ( 0 < $network_data_set['timestamp'] ) {
							$diff = ( $this->now - $network_data_set['timestamp'] ) / ( 60 * 60 * 24 );
							if ( $diff <= $this->max_view_history || ( 0 === $this->max_view_history ) ) {
								$new_network_data[] = array( 'id' => $network_data_set['id'], 'timestamp' => $network_data_set['timestamp'], 'language' => $network_data_set['language']);
								// add to selection
								$this->user_selection[] = (int) $network_data_set['id'];
							}
						}
					}
				}
			}
			
			if ( ( is_single() || is_page() ) && false === $this->prefetch_request && !is_front_page() ) {
				// add new post to network but prevent self relations and reload entries
				if ( isset( $this->viewed_post_IDs ) && is_array( $this->viewed_post_IDs ) ) {
					$sp_id = null;
					$last_vp_entry = end( $this->viewed_post_IDs );
					if ( is_array($last_vp_entry) && $this->post->ID != $last_vp_entry[0] && $this->post_language == $last_vp_entry[1] ) {
						// add to network
						foreach ( $this->viewed_post_IDs as $sp_id ) {
							if ( (int) $this->post->ID !== (int) $sp_id[0] && $this->post_language == $sp_id[1] ) {
								$new_network_data[] = array( 'id' => $sp_id[0], 'timestamp' => $this->now, 'language' => $sp_id[1] );
							}
						}
					}
					// safe to db
					if ( isset( $new_network_data ) && is_array( $new_network_data ) ) {
						$new_network_data_DB = serialize( $new_network_data );
					}
					
					if ( isset( $new_network_data_DB ) ) {
						add_post_meta( $this->post->ID, 'soseredbviewedposts', $new_network_data_DB, true ) or update_post_meta( $this->post->ID, 'soseredbviewedposts', $new_network_data_DB );
					}
				}
			}
		}

		/**
		 * view
		 *
		 * @since 1.0
		 * @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		 */
		private function get_html_output( $selected_posts ) {
		
		
		
			// return empty string if hidden output
						
			if ( true === $this->hide_output || 0 === count( $selected_posts ) || !in_array( get_post_type(), $this->show_on_page_types ) ) return '';
			
			// return output as html string else
			
			$return_string = '<aside role="complementary"><div class="sosere-recommendation entry-utility"><h3>';
				if ( isset( $this->recommendation_box_title ) && is_array( $this->recommendation_box_title ) && 0 < count( $this->recommendation_box_title ) && isset( $this->recommendation_box_title[$this->post_language] ) )  {
					 $return_string .= $this->recommendation_box_title[$this->post_language];
				} elseif ( 0 < strlen( $this->recommendation_box_title_default ) ) {
					$return_string .= __( $this->recommendation_box_title_default, 'social-semantic-recommendation-sosere' );
				} else {
					$return_string .= __( 'Recommended for you', 'social-semantic-recommendation-sosere' );
				}
			$return_string .= '</h3><ul class="sosere-recommendation">';
			
			if ( isset( $selected_posts ) && is_array( $selected_posts ) ) {

                foreach ( $selected_posts as $post_obj ) {
                    if ( is_object( $post_obj ) ) {
                        $url = null;
                        $post_thumbnail_id = null;
                        $thumb = false;

                        // strip tags from post title
                        $post_obj->post_title = wp_strip_all_tags ( $post_obj->post_title );

                        if ( true === $this->show_thumbs || true === $this->show_thumbs_title ) {
                            // explode custom thumbnail size
                            $thumb_size = explode( 'x', $this->sosere_custom_thumbnail_size );
                            if( !is_array($thumb_size) || 1 > count( $thumb_size )  ) {
                                $thumb_size = array(150, 150);
                            }

                            // get thumbs
                            $post_thumbnail_id = (int)get_post_thumbnail_id( $post_obj->ID );									
									
                            if ( 0 < $post_thumbnail_id && function_exists('wp_get_attachment_image_url') ) {
									$thumb = wp_get_attachment_image_url( $post_thumbnail_id, $thumb_size);
						    } elseif ( 0 < $post_thumbnail_id && false === $thumb ) {
                                    $thumb = wp_get_attachment_image_src( $post_thumbnail_id,  $thumb_size );
									
                            } elseif ( 0 < $post_thumbnail_id && false === $thumb ) {
                                    $thumb = wp_get_attachment_image_src( $post_thumbnail_id, 'thumbnail' );
							} else {
                                // get post attachment
                                $output = preg_match( '/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $post_obj->post_content, $matches );
                                if ( isset( $matches[1] ) ) {
                                        $thumb = array( $matches[1] );
                                    }
                                }
                            if ( isset( $thumb ) && is_array( $thumb ) ) {
                                $url = $thumb[0];
                            } elseif ( isset( $thumb ) && 0 < strlen( $thumb ) ) {
                                $url = $thumb;
                            } elseif ( isset ( $this->default_thumbnail_img_url ) && 0 < strlen( $this->default_thumbnail_img_url ) ) {
                                $url = $this->default_thumbnail_img_url;
                            }

                            // build response string
                            $return_string .= '<li class="sosere-recommendation-thumbs" style="width:' . $thumb_size[0] . 'px;">' . '<a href="' . get_permalink( $post_obj->ID ) . '" style="width:' . $thumb_size[0] . 'px;">';
                            isset( $url ) ? $return_string .= '<img src="' . $url . '" alt="' . $post_obj->post_title . '" title="' . $post_obj->post_title . '" style="width:' . $thumb_size[0] . 'px; "/>' : $return_string .= '<div class="no-thumb" style="width:' . $thumb_size[0] . 'px; height: ' . $thumb_size[1] . 'px;"></div>';

                            // add title
                            if ( true === $this->show_thumbs_title ) {
                                if ( 0 < $this->title_leng && mb_strlen( $post_obj->post_title ) > $this->title_leng ) {
                                    $return_string .= '<span>' . mb_substr( $post_obj->post_title, 0, $this->title_leng ) . '...</span>';
                                } else {
                                    $return_string .= '<span>' . $post_obj->post_title . '</span>';
                                }
                            }
                            // close link, list
                            $return_string .= '</a></li>';
                        } else {
                            $return_string .= '<li><a href="' . get_permalink( $post_obj->ID ) . '">' . $post_obj->post_title . '</a></li>';
                        }
                    }
                } 
			}
			$return_string .= '</ul></div></aside>';
			
			return $return_string;
		}

		/**
		 * selection
		 * 
		 * @since 1.0
		 * @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		 * @param s $allSelection array
		 * @return array
		 */
		private function preferential_selection( $all_selection ) {
			
			// exclude current and seen posts from being recommended again
			$all_selection_diff = array();
			$viewed_post_IDs = array();
			// extract post id's from $this->viewed_post_IDs
			foreach($this->viewed_post_IDs as $k=>$v) {
				$viewed_post_IDs[] = $v[0];	
			}
			$all_selection_diff = array_diff($all_selection, $viewed_post_IDs, array( $this->post->ID ) );
			
			// calculate array size
			$count = count( $all_selection_diff );
			
			// get count of unique queried posts
			$count_unique = count( array_unique( $all_selection_diff ) );
			
			// calculate ratio between max_results and size (count)
			$ratio = floor( $count_unique/$this->max_results );
			
			// take a slice for selection 
			
			if ( 0 < $ratio ) {
				shuffle( $all_selection_diff );
				$slice_selection = array();
				$i = 0;
				
				while ( (count( array_unique( $slice_selection ) ) < $this->max_results ) && $i < $this->max_results*2 ) {
					shuffle( $all_selection_diff );
					$slice_selection = array_slice( $all_selection_diff, 0, round($count/$ratio), true );
					$i++;
				}
				return array_slice( array_unique( $slice_selection ), 0, $this->max_results );
			} else {
				return array_slice( array_diff( array_unique( $all_selection ), array( $this->post->ID ) ) , 0, $this->max_results );
			}
		}
		/*
		 * ######################## Helper section ##########################
		 */
		
		/**
		* Set language of the current post/page
		*
		* @since 2.3
		* @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		*/
		public function get_current_language() {
			if ( defined ( 'ICL_LANGUAGE_CODE' ) ) {
				return ICL_LANGUAGE_CODE;
			} elseif ( defined ( 'XILILANGUAGE_VER' ) ) {
				$xili_lang_obj = xiliml_get_lang_object_of_post ($this->post->ID);
				if(is_object( $xili_lang_obj ) && isset( $xili_lang_obj->slug ) ) {
					return substr($xili_lang_obj->slug, 0, 2);
				} 			
			} elseif ( function_exists ( 'pll_current_language' ) ) {
					return pll_current_language();
			}
			// return default
			return 'xx';
			
		}
		
		/**
		 * Enqueue plugin style-files
		 *
		 * @since 1.0
		 * @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		 */
		public function sosere_add_stylesheet() {
			// custom css
			if ( true === $this->use_custom_css && file_exists( SOSERE_PLUGIN_DIR . 'sosere_css/sosere-recommendation-custom.css' ) ) {
				wp_register_style( 'sosere-recommendation-custom-style', SOSERE_PLUGIN_DIR . 'sosere_css/sosere-recommendation-custom.css' );
				wp_enqueue_style( 'sosere-recommendation-custom-style' );
			} else {
				// base css
				wp_register_style( 'sosere-recommendation-style', SOSERE_PLUGIN_DIR . 'sosere_css/sosere-recommendation.css' );
				wp_enqueue_style( 'sosere-recommendation-style' );
			}
		}

		/**
		 * *
		 * Session handling helper
		 *
		 * @since 1.0
		 * @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		 */
		public function sosere_start_session() {
			if ( ! session_id() && ( !array_key_exists('HTTP_DNT', $_SERVER) || (1 !== (int) $_SERVER['HTTP_DNT']) ) && (array_key_exists('session', $this->data_sources ) && $this->data_sources['session'] == 'session' ) ) {
				
				session_start( [
					'name' => 'sosere_sid', 
					'cookie_secure'=>true, 
					'cookie_httponly'=>true,
					] );
			}
		}

		public function sosere_end_session() {
			if( session_status() === PHP_SESSION_ACTIVE ) {
				session_destroy();
			}
		}

		public function sosere_get_prefetch_header() {
			if ( isset( $_SERVER['HTTP_X_MOZ'] ) && false !== strripos( 'prefetch', $_SERVER['HTTP_X_MOZ'] ) ) {
				$this->prefetch_request = true;
			}
			if ( array_key_exists('HTTP_DNT', $_SERVER) && ( 1 == (int) $_SERVER['HTTP_DNT'] ) ) {
				$this->dnt = 1;
			}
		}

		/**
		 *
		 * Tracking Session handling
		 *
		 * @since 1.0
		 * @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		 */
		public function sosere_handle_session() {
			if ( ! is_object( $this->post ) ) {
				global $post;
				$this->post = $post;
			}
			
			if ( ( is_single() || is_page() ) && false === $this->prefetch_request && 1 !== $this->dnt && (array_key_exists('session', $this->data_sources ) && $this->data_sources['session'] == 'session' ) ) {
				// get viewed postIDs from
				if ( isset( $_SESSION ) && 0 < count( $_SESSION ) && isset( $_SESSION['sosereviewedposts'] ) && is_array( $_SESSION['sosereviewedposts'] ) ) {
					$this->viewed_post_IDs = $_SESSION['sosereviewedposts'];
					$latest_viewed_post = end( $this->viewed_post_IDs );
					// do not add if reload
					if ( (int) $latest_viewed_post[0] != (int) $this->post->ID  ) {
						$_SESSION['sosereviewedposts'][] = array( (int) $this->post->ID, $this->post_language );
					}
				} else {
					$_SESSION['sosereviewedposts'] = array( );
					$_SESSION['sosereviewedposts'][] = array( (int) $this->post->ID, $this->post_language );
				}
			}
		}

		/**
		 * additional query filter
		 *
		 * @since 1.0
		 * @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		 */
		function additional_filter( $where = '' ) {
			// posts in the last 30 days
			if ( $this->max_post_age > 0 ) {
				$where .= " AND post_date >= '" . date( 'Y-m-d', strtotime( '-' . $this->max_post_age . ' days' ) ) . "'";
			}
			
			return $where;
		}

		/**
		 * disable distinct filter
		 *
		 * @since 1.0
		 */
		function search_distinct() {
			return ''; // filter has no effect
		}
		/**
		* join icl_table to get localized posts when wplm plugin is used
		*
		* @since 2.2.0
		* @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		*/
		/*function icl_join ( $join ) {
			global $wpdb;
			
		  if( defined ( 'ICL_LANGUAGE_CODE' ) ) {
			$join .= ' INNER JOIN '.$wpdb->prefix.'icl_translations iclt ON ( '. 
						$wpdb->prefix.'posts.ID = iclt.element_id AND iclt.language_code = "' . ICL_LANGUAGE_CODE . '")'.
					  ' INNER JOIN '.$wpdb->prefix.'icl_languages icll ON ( iclt.language_code=icll.code AND icll.active=1 )';			
		  }
		  
		
		  return $join;
		}
		*/
		/**
		* join taxonomy tables to get localized posts when xili language plugin is used
		*
		* @since 2.1.0
		* @author : Arthur Kaiser <social-semantic-recommendation@sosere.com>
		*/
		function xili_join ( $join ) {
			global $wpdb;
		  if( defined ( 'XILILANGUAGE_VER' ) ) {
			$post_lang_id = xiliml_get_lang_object_of_post ($this->post->ID);
			if ( is_object( $post_lang_id ) && isset( $post_lang_id->term_id ) ) {
				$join .= ' INNER JOIN '.$wpdb->prefix.'term_relationships tr ON ( ' .
							'tr.object_id = '.$wpdb->prefix.'posts.ID  )'.
						 ' INNER JOIN '.$wpdb->prefix.'term_taxonomy tt ON ('.
							'tt.term_id = tr.term_taxonomy_id AND tt.taxonomy=\'language\' AND tt.term_id = '.$post_lang_id->term_id.')';
			}
		  }
		
		  return $join;
		}
	} // end class sosereController
	
} // end: if exists class

$obj = new Sosere_Controller();