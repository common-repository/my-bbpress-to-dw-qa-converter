<?php
/**
 *  Plugin Name: My BBpress to DW Q&A Converter
 *  Description: Convert your bbpress to DW question and answer
 *  Author: The Doge
 *  Author URI: http://www.thedoge-it.com/
 *  Author Email: pumpkjn1508@gmail.com
 *  Version: 1.0.0
 *  Text Domain: my-convert-bb-to-qa
 *  @since 1.0.0
 */

if ( ! defined( 'BB_TO_QA_DIR' ) ) {
	define( 'BB_TO_QA_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'BB_TO_QA_URI' ) ) {
	define( 'BB_TO_QA_URI', plugin_dir_url( __FILE__ ) );
}

class Convert_BBpress_DWQA_DataConvertTools {


	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 15 );

		add_action( 'wp_ajax_ajax_data_convert', array( $this, 'dwqa_ajax_data_convert' ) );
		add_action( 'wp_ajax_nopriv_ajax_data_convert', array( $this, 'dwqa_ajax_data_convert' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'admin_init', array( $this, 'register_convert_tools_settings' ) );
	}

	function register_convert_tools_settings() {
		register_setting( 'dwqa_convert', '_dwqa_convert_topic_ids');
		register_setting( 'dwqa_convert', '_dwqa_new_questions_ids');
		register_setting( 'dwqa_convert', '_dwqa_new_answers_ids');
	} 
	

	function admin_menu() {
		add_submenu_page( 'tools.php', __( 'BBpress to DW Q&A','my-convert-bb-to-qa' ), __( 'BBpress to DW Q&A','my-convert-bb-to-qa' ), 'manage_options', 'bbpress-dwqaq-tools', array( $this, 'data_tools_settings_display' )  );
		
	}

	public function enqueue_script() {
		wp_enqueue_script( 'bb-to-qa-convert', BB_TO_QA_URI . 'assets/js/bb-to-qa-convert.js', array( 'jquery' ), true );

		$bbToQA_sript = array(
			'plugin_dir_url' => BB_TO_QA_URI,
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
		);

		wp_localize_script( 'bb-to-qa-convert', 'bbToQA', $bbToQA_sript );
	}



	function dwqa_ajax_data_convert() {

	    // --- Questions and Answers convert from topics and replies ---

		$action = isset( $_POST['args'] ) ? $_POST['args'] : null;

	    if ( 'import' == $action ) {
			die;
	    } elseif ( 'convert' == $action ) {

    		// --- Categories & Tags convert from forum & topic-tag ---

			$categories = $this->get_bbpres_categories();



			$tags = $this->get_bbpres_tags();

			$this->create_qa_terms( $categories, $tags );

			// --- Questions, answers convert from topics, replies... ---

	    	$question_ids = ( null !== get_option( '_dwqa_new_questions_ids' ) ) ? get_option( '_dwqa_new_questions_ids' ) : null;
	    	$answer_ids = ( null !== get_option( '_dwqa_new_answers_ids' ) ) ? get_option( '_dwqa_new_answers_ids' ) : null;

	    	$this->refresh( $question_ids, $answer_ids );

	    	$topics = $this->get_all_bbpress_topics();

			$this->convert_bbpress_topics( $topics );
			// save all topics id to wordpress option	    
			$this->save_all_topic_id( $topics );

			$result = array();
			$result['category'] = count( $categories );
			$result['tag'] = count( $tags );
			$result['question'] = count( $topics );

			wp_send_json( $result );

	    }
	    die(0);
	}

	// ----- Categories and Tags convert -------------------------------------------------------------------------------

	function create_qa_terms( $categories , $tags ) {
		foreach ( $categories as $category ) {
			if ( $this->slug_check( $category['slug'] ) ) {
				$term_id = wp_insert_term( $category['name'], 'dwqa-question_category', array( 'slug' => $category['slug'] ) );
				add_term_meta( $term_id['term_id'], '_dwqa_followers', $category['subscribers'], false );
			}
			
		}
		foreach ( $tags as $tag ) {
			wp_insert_term( $tag['name'], 'dwqa-question_tag', array( 'slug' => $tag['slug'] ) );
		}

		$this->update_dwqa_term( $categories );

	}

	function get_bbpres_categories() {
		global $wpdb;

		$categories_query = $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = 'forum'" );
		$categories = array();
		$categories_result = $wpdb->get_results( $categories_query );
		$i = 0;

		foreach ($categories_result as $result) {
			$parent_slug = $this->get_parent_slug( $result->post_parent );
			$categories[$i]['name'] = $result->post_title;
			$categories[$i]['slug'] = $result->post_name;
			$categories[$i]['parent_slug'] = $parent_slug;
			$subscribers = $this->get_forum_subscribers( $result->ID );
			$categories[$i]['subscribers'] = implode( ',', $subscribers );
			$i++;
		}
		return $categories;
	}

	function get_bbpres_tags() {
		global $wpdb;

		$tags_query =  $wpdb->prepare( "SELECT * FROM {$wpdb->terms} t1 JOIN {$wpdb->term_taxonomy} t2 ON t1.term_id = t2.term_id WHERE t2.taxonomy = 'topic-tag'" );
		$tags_results = $wpdb->get_results( $tags_query );
		$tags = array();
		$i = 0;
		foreach ( $tags_results as $result ) {
			$tags[$i]['name'] = $result->name;
			$tags[$i]['slug'] = $result->slug;	
			$i++;
		}

		return $tags;
	}

	function update_dwqa_term( $categories ) {
		foreach ( $categories as $category ) {
			if ( $category['parent_slug'] || '' != $category['parent_slug'] ) {
				$parent = get_term_by( 'slug', $category['parent_slug'], 'dwqa-question_category', OBJECT );
				$parent_id = $parent->term_id;
				$term = get_term_by( 'slug', $category['slug'], 'dwqa-question_category', OBJECT );
				$term_id = $term->term_id;
				wp_update_term( $term_id, 'dwqa-question_category', array( 'parent' => $parent_id ) );
			}
		}
	}

	function get_parent_slug( $post_id ) {
		$post = get_post( $post_id );
		return $post->post_name;
	}

	function get_forum_subscribers( $forum_id ) {
		global $wpdb;
		$users = get_users();
		$users_array = array();
		foreach ( $users as $user ) {
			$forum_follow_key =  $wpdb->prefix . '_bbp_forum_subscriptions';
			$forums_follow = get_user_meta( $user->ID, $forum_follow_key, true );
			if ( $forums_follow && '' != $forums_follow ) {

				$forum_array = explode( ',' , $forums_follow );
				if ( in_array( $forum_id, $forum_array ) ) {

					$users_array[] = $user->ID;
				}
			}
		}

		return $users_array;
	}

	function slug_check( $slug ) {
		$term = get_term_by( 'slug', $slug, 'dwqa-question_category', OBJECT );
		if ( $term ) {
			return false;
		} else {
			return true;
		}
	}

	// -----------------------------------------------------------------------------------------------------------------

	// ----- Questions convert --------------------------------------------------------------------------------------------

	function get_all_bbpress_topics() {
		global $wpdb;
		$topic_query = $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = 'topic'" );
		$topics = $wpdb->get_results( $topic_query );
		return $topics;
	}

	function prepare_topics_data( $topic_id ) {
		$topic = get_post( $topic_id );
		$args = array(
			'comment_status' => $topic->comment_status,
			'post_author'    => $topic->post_author,
			'post_content'   => $topic->post_content,
			'post_status'    => $topic->post_status,
			'post_title'     => $topic->post_title,
			'post_type'      => 'dwqa-question',
		);

		return $args;
	}

	function convert_bbpress_topics( $topics ) {

		$topics_arr = ( null !== get_option( '_dwqa_convert_topic_ids' ) ) ? get_option( '_dwqa_convert_topic_ids' ) : false;
		if ( false == $topics_arr || '' == $topics_arr ) {
			foreach ( $topics as $topic ) {
				$args = $this->prepare_topics_data( $topic->ID );
				$new_question = wp_insert_post( $args, true );

				$this->question_set_meta_data( $topic->ID, $new_question );
				$this->set_question_category( $topic->ID, $new_question );
				$this->set_question_tags( $topic->ID, $new_question );
				$answers = $this->prepare_answers( $topic->ID );
				$this->create_answers( $new_question, $answers );

				$this->save_new_question_id( $new_question );

				$followers = $this->get_users_follow_topic( $topic->ID );

				$this->add_subscribe_to_question( $new_question, $followers );
			}
		} else {
			foreach ( $topics as $topic ) {
				if ( !in_array( $topic->ID , $topics_arr ) ) {
					$args = $this->prepare_topics_data( $topic->ID );
					$new_question = wp_insert_post( $args, true );

					$this->question_set_meta_data( $topic->ID, $new_question );
					$this->set_question_category( $topic->ID, $new_question );
					$this->set_question_tags( $topic->ID, $new_question );
					$answers = $this->prepare_answers( $topic->ID );
					$this->create_answers( $new_question, $answers );

					$this->save_new_question_id( $new_question );

					$followers = $this->get_users_follow_topic( $topic->ID );

					$this->add_subscribe_to_question( $new_question, $followers );
				}
			}
		}
		
	}

	function question_set_meta_data( $topic_id, $new_question ) {

		$topic = get_post( $topic_id );
		$forum_status = $this->get_forum_status( $topic_id );

		if ( $forum_status ) {
			if ( '' == $forum_status ) {
				$question_status = 'open';
			} else {
				$question_status = $forum_status;
			}
		} else {
			$question_status = $topic->post_status;
		}

		update_post_meta( $new_question, '_dwqa_status', $question_status  );
		update_post_meta( $new_question, '_dwqa_views', 0 );
		update_post_meta( $new_question, '_dwqa_votes', 0 );

		$answers_counts = get_post_meta( $topic_id, '_bbp_reply_count', true );
		update_post_meta( $new_question, '_dwqa_answers_count', $answers_counts );
	}

	function set_question_category( $topic_id, $new_question ) {
		$forum_id = get_post_meta( $topic_id, '_bbp_forum_id', true );
		if ( $forum_id && '' != $forum_id ) {
			$forum = get_post( $forum_id );
			$term_slug = $forum->post_name;
			$term = get_term_by( 'slug', $term_slug, 'dwqa-question_category', OBJECT );
			$term_id = $term->term_id;
			wp_set_object_terms( $new_question, $term_id, 'dwqa-question_category', true );
		} else {
			return false;
		}
	}

	function set_question_tags( $topic_id, $new_question ) {
		$topic_tags = $this->get_topic_tag( $topic_id );
		foreach ($topic_tags as $topic_tag) {
			$term_slug = $topic_tag->slug;
			$term = get_term_by( 'slug', $term_slug, 'dwqa-question_tag', OBJECT );
			$term_id = $term->term_id;
			wp_set_object_terms( $new_question, $term_id, 'dwqa-question_tag', true );
		}
	}

	function get_topic_tag( $topic_id ) {
		global $wpdb;
		$topic_tag_query = $wpdb->prepare( "SELECT * FROM {$wpdb->term_relationships} t1 JOIN {$wpdb->term_taxonomy} t2 ON t1.term_taxonomy_id = t2.term_taxonomy_id JOIN {$wpdb->terms} t3 ON t2.term_id = t3.term_id  WHERE t2.taxonomy = 'topic-tag' AND t1.object_id=%d", $topic_id );
		$topic_tags = $wpdb->get_results( $topic_tag_query );
		return $topic_tags;
	}

	function get_forum_status( $topic_id ) {
		$forum_id = get_post_meta( $topic_id, '_bbp_forum_id', true );
		if ( $forum_id && '' != $forum_id ) {
			$forum_status = get_post_meta( $forum_id, '_bbp_status', true );
			return $forum_status;
		} else {
			return false;
		}

	}

	function save_all_topic_id( $topics ) {
		$topic_arr = array();
		foreach ( $topics as $topic ) {
			$topic_arr[] = $topic->ID;
		}

		update_option( '_dwqa_convert_topic_ids', $topic_arr );
	}

	function create_answers( $question, $answers ) {
		foreach ( $answers as $answer ) {

			$answer_args = array(
				'comment_status' => 'open',
				'post_author'    => $answer->post_author,
				'post_content'   => $answer->post_content,
				'post_title'     => $answer->post_title,
				'post_type'      => 'dwqa-answer',
				'post_parent'	 => $question,
				'post_status'	 => $answer->post_status,
			);
			$new_answer = wp_insert_post( $answer_args, true );

			$this->save_new_answer_id( $new_answer );

				if ( !is_wp_error( $new_answer ) ) {

					update_post_meta( $new_answer, '_question', $question  );
					if ( 0 == $answer->post_author || '' == $answer->post_author ) {
						update_post_meta( $new_answer, '_dwqa_is_anonymous', true );
						$post_author_email = get_post_meta( $answer->ID , '_bbp_anonymous_email', true );

						if ( isset( $post_author_email ) ) {
							update_post_meta( $new_answer, '_dwqa_anonymous_email', $post_author_email );
						}

						$post_author_name = get_post_meta( $answer->ID , '_bbp_anonymous_name', true );
						if ( isset( $post_author_name ) && !empty( $post_author_name ) ) {
							update_post_meta( $new_answer, '_dwqa_anonymous_name', $post_author_name );
						}
					}
				} else {
					dwqa_add_wp_error_message( $new_answer );
				}
		}
	}

	function save_new_question_id( $question_id ) {

		$question = ( null !== get_option( '_dwqa_new_questions_ids' ) ) ? get_option( '_dwqa_new_questions_ids' ) : null;
		if ( $question == null ) {
			$question_string = $question_id;
		} else {
			$question_arr = explode( ',' , $question );
			$question_arr[] = $question_id;
			$question_string = implode( ',' , $question_arr );
		}
		
		update_option( '_dwqa_new_questions_ids', $question_string );

	}

	// -----------------------------------------------------------------------------------------------------------------
	// ----- Answers convert -------------------------------------------------------------------------------------------

	function prepare_answers( $topic_id ) {
		global $wpdb;
		$answers_query = $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status != 'auto-draft' AND post_parent=%d", $topic_id );
		$replies = $wpdb->get_results( $answers_query );
		return $replies;
	}

	function save_new_answer_id( $answer_id ) {
		$answer = ( null !== get_option( '_dwqa_new_answers_ids' ) ) ? get_option( '_dwqa_new_answers_ids' ) : null;
		if ( $answer == null ) {
			$answer_string = $answer_id;
		} else {
			$answer_arr = explode( ',' , $answer );
			$answer_arr[] = $answer_id;
			$answer_string = implode( ',' , $answer_arr );
		}
		
		update_option( '_dwqa_new_answers_ids', $answer_string );
	}
	// -----------------------------------------------------------------------------------------------------------------

	// ------- Sucscriber Convert---------------------------------------------------------------------------------------

	function get_users_follow_topic( $topic_id ) {
		global $wpdb;
		$users = get_users();
		$users_array = array();
		foreach ( $users as $user ) {
			$topic_follow_key =  $wpdb->prefix . '_bbp_subscriptions';
			$topics_follow = get_user_meta( $user->ID, $topic_follow_key, true );
			if ( $topics_follow && '' != $topics_follow ) {
				$topic_array = explode( ',' , $topics_follow );
				if ( in_array( $topic_id, $topic_array ) ) {
					$users_array[] = $user->ID;
				}
			}
		}

		return $users_array;
	}

	function add_subscribe_to_question( $new_question, $followers ) {
		$followers_string = implode( ',' , $followers);

		update_post_meta( $new_question, '_dwqa_followers', $followers_string );

	}


	// -----------------------------------------------------------------------------------------------------------------

	function refresh( $question_ids, $answers_ids ) {

		update_option( '_dwqa_convert_topic_ids', null );

		if ( null != $question_ids ) {
			$question_arr = explode( ',' , $question_ids );
			foreach ( $question_arr as $question ) {
				wp_delete_post( $question );
			}
		}

		if ( null != $answers_ids ) {
			$answer_arr = explode( ',' , $answers_ids );
			foreach ( $answer_arr as $answer ) {
				wp_delete_post( $answer );
			}
		}

		update_option( '_dwqa_new_questions_ids', null );
		update_option( '_dwqa_new_answers_ids', null );
	}

	function data_tools_settings_display() { ?>
		<style type="text/css">
			ul.subsubsub {
			    float: left;
			}

			ul.subsubsub > li {
			    display: inline-block;
			}

			ul.subsubsub > li.active > a {
			    color: #000;
			    font-weight: bold;
			}
		</style>
		<div class="wrap">
			<h2><?php _e( 'Convert from BBpress to DW Q&A', 'dwqa' ) ?></h2>
				<div style="float: right;margin-right: 10%;text-align: center;">
					<p>If you find it's usefull, feel free to donate me, thanksssssss!</p>
					<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top">
					<input type="hidden" name="cmd" value="_s-xclick">
					<input type="hidden" name="hosted_button_id" value="EQR2L3LMBL578">
					<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
					<img alt="" border="0" src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1">
					</form>

				</div>
				<p><a href="https://bbpress.org/">BBpress</a></p>
				<p><a href="https://www.designwall.com/wordpress/plugins/dw-question-answer/">DW Question & Answer</a></p>
				<p>
					<i>When convert your bbpress data will not be harmed. All the DW Question & Answer that existed before will not be harmed too.</i> 
				</p>
				<?php echo submit_button( 'Convert', 'primary', 'bb-to-qa' ); ?>
			<div id="bb-qa-convert-result"></div>
		</div>
	<?php }
}

$GLOBALS['convert_bbpress_dwqa'] = new Convert_BBpress_DWQA_DataConvertTools();

?>