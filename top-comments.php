<?php
/*
Plugin Name: Top Comments
Plugin URI: http://wordpress.org/extend/plugins/top-comments/
Description: Simple, easy to manage comments rating.
Author: Andrew Ozz
Author URI: azaozz.wordpress.com
Version: 1.1-beta
*/

/*
Some ideas from Comment Karma by Alex Bailey, http://cyber-knowledge.net
Uses the hash check from "WP Hashcash", http://wordpress.org/extend/plugins/wp-hashcash/ by Elliott Back, http://elliottback.com and Donncha, http://ocaoimh.ie/
Uses code from XHR.js 832 2008-05-02 11:01:57Z spocke, Moxiecode, http://tinymce.moxiecode.com/
Icons from Matt Thomas, http://iammattthomas.com/, Crystal Project Icons, Everaldo Coelho, http://www.everaldo.com

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

// Appends the comments rating button and the current rating to the comment.
function topc_template($text) {

	if ( is_admin() || defined('DOING_AJAX') ) {
		$str =  "\n".'<p class="top-comments"><small>' . __('Current score: ', 'topc') . '</small> ' . topc_display_ratings() . '</p>';
	} elseif ( is_feed() ) {
		$str =  '<p class="top-comments">' . __('Current score: ', 'topc') . topc_display_ratings() . ' <small>(' . __('to vote for this comment, please visit the site', 'topc') . ')</small></p>';
	} else {
		global $post, $topc_expire;

		if ( ! isset($topc_expire) )
			$topc_expire = topc_timelimit($post->post_date_gmt);

		$rate_btn = $topc_expire ? ' ' . topc_rate_button() : '';
		$str =  "\n".'<div class="top-comments"><small>' . __('Current score:', 'topc') . '</small> ' . topc_display_ratings() . $rate_btn . '</div>';
	}

	return $text . $str;
}
add_filter('get_comment_text', 'topc_template');

function topc_style() {
	global $topc_display_opt;

	if ( is_singular() ) {
		$topc_display_opt = (array) get_option('topc_display');
?>
	<style type="text/css">
	.top-comments img { display: inline; padding: 0 !important; margin: 0 !important; border: 0 !important; vertical-align: text-top; cursor: pointer; }
	.top-comments-button { <?php echo $topc_display_opt['css']; ?> }
	.top-comments { cursor: default; }
	.top-comments small { color: #888; }
	</style>
<?php	}
}
add_action('wp_head', 'topc_style');

// Returns the ratings button in the comments loop. Can be used as template tag with "echo topc_rate_button();".
function topc_rate_button()	{
	global $topc_display_opt;

	$comment_id = get_comment_ID();
	if ( topc_block_user_ip($comment_id) || topc_cookiecheck($comment_id) )
		return;

	$topc_display_opt = isset($topc_display_opt) ? (array) $topc_display_opt : (array) get_option('topc_display');

	return '<noscript><small>('.__('Please enable JavaScript to vote.', 'topc').')</small></noscript><span style="display:none;" class="top-comments-button" id="up-'.$comment_id.'" onclick="topcKarma.up(\''.$comment_id.'\');">'.$topc_display_opt['link'].'</span>';
}

// Returns the current comment karma in the comments loop. Can be used as template tag with "echo topc_display_ratings();".
function topc_display_ratings() {
	$comment_id = get_comment_ID();

	return '<span class="top-comments-karma" id="karma-'.$comment_id.'">'.topc_get_karma($comment_id).'</span>';
}

// Template tag. Can be used instead of the widget.
// Prints the 10 top rated comments as UL. Each includes rating, author name, post name and a short excerpt.
function topc_highest_rated($num = 10) {
	$top_rated = (array) get_option('topc-top-rated');

	if ( $num < count($top_rated) )
		$top_rated = array_slice($top_rated, 0, $num);

	echo "<ul class='topc-comments'>\n";
	foreach ( $top_rated as $row )
		echo "\t<li>".stripslashes($row)."</li>\n";

	echo "</ul>\n";
}

// Prepares the top rated comments. Options can be changed below.
function topc_make_highest_rated() {
	global $wpdb;

	$limit = 10; // how many to include
	$length = 75; // character lenght of the comment excerpt
	$chars_per_word = 25; // max chars per word, will force-wrap longer words so they stay whitin the width of the sidebar

	$comments = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $wpdb->comments WHERE comment_approved = '1' ORDER BY comment_karma DESC LIMIT %d", $limit) );

	if ( ! $comments ) return;

	$top_rated = array();
	foreach ($comments as $comment) {
		$author = $comment->comment_author;
		$author = wordwrap($author, $chars_per_word, ' ', true);

		$text = $comment->comment_content;
		$text = strip_tags(wptexturize($text));
		if ( strlen($text) > $length ) {
			$text = substr( $text, 0, $length );
			$text = substr( $text, 0, strrpos($text, ' ') );
			$text .= "&#8230;";
		}
		$text = wordwrap($text, $chars_per_word, ' ', true);
		$text = preg_replace('/\s+/', ' ', $text); // spaces into 1

		$row = __('Score: ', 'topc') . $comment->comment_karma . ', <a href="'. get_permalink($comment->comment_post_ID) . '#comment-' . $comment->comment_ID . '">' . $author . ', ' . get_the_title($comment->comment_post_ID) . ':</a> ' . $text;

		$top_rated[] = $wpdb->escape($row);
	}

	update_option( 'topc-top-rated', $top_rated );
	update_option( 'topc-top-rated-last', $comment->comment_karma );
}

function topc_timelimit($m) {
	global $topc_display_opt;

	if ( ! isset($topc_display_opt) )
		$topc_display_opt = (array) get_option('topc_display');

	if ( ! isset($topc_display_opt['timelimit']) || ! $topc_display_opt['timelimit'] )
		return true;

	$i = mktime(
		(int) substr( $m, 11, 2 ), (int) substr( $m, 14, 2 ), (int) substr( $m, 17, 2 ),
		(int) substr( $m, 5, 2 ), (int) substr( $m, 8, 2 ), (int) substr( $m, 0, 4 )
	);
	
	if ( (time() - $topc_display_opt['timelimit']) < $i )
		return true;

	return false;
}

// Widget
class Widget_TOPC_Top_Rated extends WP_Widget {

	function Widget_TOPC_Top_Rated() {
		$widget_ops = array('classname' => 'topc_widget', 'description' => __('The top rated comments', 'topc') );
		$this->WP_Widget('topc-comments', __('Top Comments', 'topc'), $widget_ops);

		if ( is_active_widget('topc_widget') )
			add_action('wp_head', 'topc_hrated_style');
	}

	function widget( $args, $instance ) {
		extract($args);
		$number = !empty($instance['number']) ? (int) $instance['number'] : 5;
		$title = empty($options['title']) ? '' : apply_filters('widget_title', $instance['title']);

		echo $before_widget;
		if ( $title )
			echo $before_title . $title . $after_title;
		topc_highest_rated($number);
		echo $after_widget;
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = wp_parse_args( (array) $new_instance, array( 'title' => '', 'number' => 5 ) );
		$instance['title'] = strip_tags($new_instance['title']);
		$number = (int) $new_instance['number'];
		if ( $number < 1 )
			$number = 1;
		else if ( $number > 10 )
			$number = 10;
		$instance['number'] = $number;

		return $instance;
	}

	function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, array( 'title' => '', 'count' => 0, 'dropdown' => '') );
		$title = strip_tags($instance['title']);
		if ( !$number = (int) $instance['number'] )
			$number = 5;
?>
		<p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:', 'topc'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" /></p>
		<p>
			<label for="<?php echo $this->get_field_id('number'); ?>"><?php _e('Number of comments to show:', 'topc'); ?> <input style="width: 25px; text-align: center;" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('number'); ?>" type="text" value="<?php echo $number; ?>" /></label>
			<br />
			<small><?php _e('(at most 10)', 'topc'); ?></small>
		</p>
<?php
	}
}

function topc_hrated_style() { ?>
<style type="text/css">.topc-comments a { display: inline !important; padding: 0 !important; margin: 0 !important; }</style>
<?php
}

function topc_widget_register() {
	register_widget('Widget_TOPC_Top_Rated');
}
add_action('widgets_init', 'topc_widget_register');

function topc_set_cookie() {
	if ( ! isset($_COOKIE['topc_'.COOKIEHASH]) ) {
		$cookie = md5( COOKIEHASH.PASS_COOKIE );
		$expire = time() + 3600;
		setcookie('topc_'.COOKIEHASH, $cookie, $expire, COOKIEPATH, COOKIE_DOMAIN);
	}

	load_plugin_textdomain('topc', '', '/top-comments/languages');
}
add_action('init', 'topc_set_cookie');

// Get the karma value for a specific comment. Returns int.
function topc_get_karma($comment_id) {

	if ( $comment = get_comment($comment_id) )
		return (int) $comment->comment_karma;
	else
		return 0;
}

function topc_block_user_ip($comment_id) {
	$ip_array = (array) get_option('topc-ip-array');
	$key = $comment_id.'_'.preg_replace( '/[^0-9.]/', '', $_SERVER['REMOTE_ADDR'] );
	return array_key_exists( $key, $ip_array );
}

function topc_cookiecheck($comment_id) {
	global $topc_cookie_hash;

	if ( isset($topc_cookie_hash) ) {
		if ( is_array($topc_cookie_hash) )
			return in_array($comment_id, $topc_cookie_hash);
		else return false;
	}

	if ( isset($_COOKIE['topc_'.COOKIEHASH]) ) {
		$cookie = $_COOKIE['topc_'.COOKIEHASH];

		if ( substr($cookie, 0, 32 ) != md5( COOKIEHASH.PASS_COOKIE ) ) {
			return 0;
		} else {
			$cookie = substr($cookie, 33);
			if ( $cookie ) {
				$topc_cookie_hash = explode('|', $cookie);
				return in_array($comment_id, $topc_cookie_hash);
			} else {
				$topc_cookie_hash = false;
				return false;
			}
		}
	}
	return 0;
}

function topc_addmenu() {
	if ( function_exists('add_submenu_page') )
		add_submenu_page('plugins.php', __('Top Comments', 'topc'), __('Top Comments', 'topc'), 9,  __FILE__, 'topc_admin_options');
}
add_action('admin_menu', 'topc_addmenu');

function topc_admin_options() {
	$opt = (array) get_option('topc_display');

    $title = attribute_escape( __('+1', 'topc') );
    $url = WP_PLUGIN_URL.'/top-comments/images/';

	if ( isset($_POST['topc_opt']) ) {
        check_admin_referer('topc-options-page');
    	$opt['css'] = isset($_POST['topc_css']) ? stripslashes( trim( $_POST['topc_css'] ) ) : '';
    	$opt['link'] = isset($_POST['topc_link']) ? str_replace( "'", '’', stripslashes( trim( $_POST['topc_link'] ) ) ) : '+1';
    	$opt['timelimit'] = isset($_POST['topc_timelimit']) ? (int) $_POST['topc_timelimit'] : 1209600;
    	update_option( 'topc_display', $opt ); ?>
		<div id="message" class="updated fade"><p><?php _e('Settings saved', 'topc') ?></p></div>
	<?php } ?>

    <style type="text/css">#topc-presets img {vertical-align:middle;}</style>
	<div class="wrap">
    <h2><?php _e('Top Comments', 'topc'); ?></h2>
    <p><?php _e('Select type and style for the voting button.', 'topc'); ?></p>

	<form method="post" name="f1" id="f1" action="">
    <table class="form-table">
        <tr>
		<td><?php _e('Presets:', 'topc'); ?></td>
        <td>
			<script type="text/javascript">
			function topcPresets(n) {
				var link = document.forms.f1.topc_link, css = document.forms.f1.topc_css;

				if ( 1 == n )
					link.value = '+1', css.value = 'cursor: pointer; border: 1px solid #ddd; padding: 1px 3px; background-color: #eee;';
				else if ( 2 == n )
					link.value = '+1', css.value = 'cursor: pointer; border: 1px solid #e6db55; padding: 1px 3px; background-color: #fffbcc;';
				else if ( 3 == n )
					link.value = '+1', css.value = 'cursor: pointer; font-weight: bold;';
				else if ( 4 == n )
					link.value = '+1', css.value = 'cursor: pointer; color: #2782af; font-weight: bold;';
				else if ( 5 == n )
					link.value = '+1', css.value = 'cursor: pointer; color:#4f9915; font-weight: bold;';
				else if ( 6 == n )
					link.value = '+1', css.value = 'cursor: pointer; color:#de403b; font-weight: bold;';
				else if ( 7 == n )
					link.value = '+1', css.value = 'cursor: pointer; padding: 1px 3px; background-color: #e5e5fb;';
				else if ( 8 == n )
					link.value = '+1', css.value = 'cursor: pointer; font-weight: bold; color: #fff; padding: 1px 3px; background-color: #1d6ebf;';
				else if ( 9 == n )
					link.value = '<img src="<?php echo $url; ?>up.gif" width="16" height="16" title="<?php echo attribute_escape( __('Thumbs up!', 'topc') ); ?>" alt="<?php echo attribute_escape( __('Thumbs up!', 'topc') ); ?>" />', css.value = 'cursor: pointer; padding: 0 2px;';
				else if ( 10 == n )
					link.value = '<img src="<?php echo $url; ?>arrow-g.gif" width="16" height="16" title="<?php echo $title; ?>" alt="<?php echo $title; ?>" />', css.value = 'cursor: pointer; padding: 0 2px;';
				else if ( 11 == n )
					link.value = '<img src="<?php echo $url; ?>arrow-r.gif" width="16" height="16" title="<?php echo $title; ?>" alt="<?php echo $title; ?>" />', css.value = 'cursor: pointer; padding: 0 2px;';
				else if ( 12 == n )
					link.value = '<img src="<?php echo $url; ?>heart.gif" width="16" height="16" title="<?php echo attribute_escape( __('Love it!', 'topc') ); ?>" alt="<?php echo attribute_escape( __('Love it!', 'topc') ); ?>" />', css.value = 'cursor: pointer; padding: 0 2px;';
				else if ( 13 == n )
					link.value = '<img src="<?php echo $url; ?>star.gif" width="16" height="16" title="<?php echo attribute_escape( __('The best!', 'topc') ); ?>" alt="<?php echo attribute_escape( __('The best!', 'topc') ); ?>" />', css.value = 'cursor: pointer; padding: 0 2px;';

			}
			</script>
			<div id="topc-presets" style="background-color:#fff;padding:8px 0;">
			<span style="padding:2px 8px;" onclick="topcPresets(1);" />
			<span style="cursor: pointer; border: 1px solid #ddd; padding: 1px 3px; background-color: #eee;">+1</span>
			</span>

			<span style="padding:2px 8px;" onclick="topcPresets(2);" />
			<span style="cursor: pointer; border: 1px solid #e6db55; padding: 1px 3px; background-color: #fffbcc;">+1</span>
			</span>

			<span style="padding:2px 8px;" onclick="topcPresets(3);" />
			<span style="cursor: pointer; font-weight:bold;">+1</span>
			</span>

			<span style="padding:2px 8px;" onclick="topcPresets(4);" />
			<span style="cursor: pointer; color:#2782af; font-weight:bold;">+1</span>
			</span>

			<span style="padding:2px 8px;" onclick="topcPresets(5);" />
			<span style="cursor: pointer; color:#4f9915; font-weight:bold;">+1</span>
			</span>

			<span style="padding:2px 8px;" onclick="topcPresets(6);" />
			<span style="cursor: pointer; color:#de403b; font-weight:bold;">+1</span>
			</span>

			<span style="padding:2px 8px;" onclick="topcPresets(7);" />
			<span style="cursor: pointer; padding: 1px 3px; background-color: #e5e5fb;">+1</span>
			</span>

			<span style="padding:2px 8px;" onclick="topcPresets(8);" />
			<span style="cursor: pointer; font-weight:bold; color: #fff; padding: 1px 3px; background-color: #1d6ebf;">+1</span>
			</span>

			<span style="padding:2px 8px;cursor: pointer;" onclick="topcPresets(9);" />
			<img src="<?php echo $url; ?>up.gif" width="16" height="16" class="top-comments-button" title="<?php echo attribute_escape( __('Thumbs up!', 'topc') ); ?>" alt="<?php echo attribute_escape( __('Thumbs up!', 'topc') ); ?>" />
			</span>

			<span style="padding:2px 8px;cursor: pointer;" onclick="topcPresets(10);" />
			<img src="<?php echo $url; ?>arrow-g.gif" width="16" height="16" class="top-comments-button" title="<?php echo $title; ?>" alt="<?php echo $title; ?>" />
			</span>

			<span style="padding:2px 8px;cursor: pointer;" onclick="topcPresets(11);" />
			<img src="<?php echo $url; ?>arrow-r.gif" width="16" height="16" class="top-comments-button" title="<?php echo $title; ?>" alt="<?php echo $title; ?>" />
			</span>

			<span style="padding:2px 8px;cursor: pointer;" onclick="topcPresets(12);" />
			<img src="<?php echo $url; ?>heart.gif" width="16" height="16" class="top-comments-button" title="<?php echo attribute_escape( __('Love it!', 'topc') ); ?>" alt="<?php echo attribute_escape( __('Love it!', 'topc') ); ?>" />
			</span>

			<span style="padding:2px 8px;cursor: pointer;" onclick="topcPresets(13);" />
			<img src="<?php echo $url; ?>star.gif" width="16" height="16" class="top-comments-button" title="<?php echo attribute_escape( __('The best!', 'topc') ); ?>" alt="<?php echo attribute_escape( __('The best!', 'topc') ); ?>" />
			</span>
			</div>
        </td></tr>

        <tr>
        <td style="width:80px;"><?php _e('Button:', 'topc'); ?></td>
        <td><input type="text" style="width:98%;" name="topc_link" id="topc_link" maxlenght="400" value='<?php echo $opt['link']; ?>' /></td>
		</tr>

		<tr>
        <td style="width:80px;"><?php _e('Style:', 'topc'); ?></td>
        <td><input type="text" style="width:98%;" name="topc_css" id="topc_css" maxlenght="400" value='<?php echo $opt['css']; ?>' /></td>
		</tr>

		<tr>
        <td style="width:80px;"><?php _e('Time limit:', 'topc'); ?></td>
        <td style="line-height: 2.5em;"><?php _e('Allow voting for comments on older posts for: ', 'topc'); ?>&nbsp;
		<select name="topc_timelimit" id="topc_timelimit">
			<option <?php if ( $opt['timelimit'] == 604800 ) echo 'selected="selected" '; ?>value="604800"><?php _e('one week', 'topc'); ?></option>
			<option <?php if ( $opt['timelimit'] == 1209600 ) echo 'selected="selected" '; ?>value="1209600"><?php _e('two weeks', 'topc'); ?></option>
			<option <?php if ( $opt['timelimit'] == 2419200 ) echo 'selected="selected" '; ?>value="2419200"><?php _e('one month', 'topc'); ?></option>
			<option <?php if ( $opt['timelimit'] == 7257600 ) echo 'selected="selected" '; ?>value="7257600"><?php _e('three months', 'topc'); ?></option>
			<option <?php if ( $opt['timelimit'] == 0 ) echo 'selected="selected" '; ?>value="0"><?php _e('forever', 'topc'); ?></option>
		</select>
		</td>
		</tr>
	</table>
	<p class="submit">
	<input type="submit" class="button" name="topc_opt" id="topc_opt" value="<?php echo attribute_escape(__('Save Settings')); ?>" />
	<?php wp_nonce_field( 'topc-options-page' ); ?></p>
    </form>
    </div>
<?php
}

function topc_install() {

	$display_opt = (array) get_option('topc_display');
	if ( ! isset($display_opt['link']) ) {
		$display_opt['link'] = '<img src="'.WP_PLUGIN_URL.'/top-comments/images/up.gif" width="16" height="16" title="'.attribute_escape( __('Thumbs up!', 'topc') ).'" alt="'.attribute_escape( __('Thumbs up!', 'topc') ).'" />';
		$display_opt['css'] = 'cursor: pointer; padding: 0 2px;';
		$display_opt['timelimit'] = 1209600;
		update_option('topc_display', $display_opt);
	}

	$wphc_opt['key'] = array();
	$wphc_opt['key'][] = rand(21474836, 2126008810);
	$wphc_opt['key-date'] = time();

	update_option('topc-wp-hashcash', $wphc_opt);
}
add_action('activate_top-comments/top-comments.php', 'topc_install');

function topc_addfooterjs() {

	if( is_singular() ) { ?>
		<script type="text/javascript" src="<?php echo WP_PLUGIN_URL; ?>/top-comments/top-comments.js?ver=20080818"></script>
		<script type="text/javascript">
		topcKarma.url="<?php bloginfo('wpurl'); ?>";
		topcKarma.errors=["<?php echo js_escape(__('Internal error. Please try again later.', 'topc')); ?>","<?php echo js_escape(__('Connection timed out. Please try again later.', 'topc')); ?>"];
		topcKarma.topc_wphc =<?php echo topc_wphc_getjs(); ?>
		</script>
	<?php }
}
add_action('get_footer', 'topc_addfooterjs');

// WP Hashcash
function topc_wphc_refresh() {

	$options = (array) get_option('topc-wp-hashcash');
	if( (time() - $options['key-date']) > 604800 ) {
		if( count($options['key']) > 4 )
			array_shift($options['key']);

		$options['key'][] = rand(21474836, 2126008810);
		$options['key-date'] = time();
		update_option('topc-wp-hashcash', $options);
	}
}

function topc_wphc_getjs(){
	$options = (array) get_option('topc-wp-hashcash');
	$k = count($options['key']) - 1;
	$val = $options['key'][$k];
	$js = 'function topc_wphc_compute(){';

	switch(rand(0, 3)){
		/* Addition of n times of field value / n, + modulus:
		 Time guarantee:  100 iterations or less */
		case 0:
			$inc = rand($val / 100, $val - 1);
			$n = floor($val / $inc);
			$r = $val % $inc;

			$js .= "var wphc_eax = $inc; ";
			for($i = 0; $i < $n - 1; $i++){
				$js .= "wphc_eax += $inc; ";
			}

			$js .= "wphc_eax += $r; ";
			$js .= 'return wphc_eax; ';
			break;

			/* Conversion from binary:
		Time guarantee:  log(n) iterations or less */
		case 1:
			$binval = strrev(base_convert($val, 10, 2));
			$js .= "var wphc_eax = \"$binval\"; ";
			$js .= 'var wphc_ebx = 0; ';
			$js .= 'var wphc_ecx = 0; ';
			$js .= 'while(wphc_ecx < wphc_eax.length){ ';
			$js .= 'if(wphc_eax.charAt(wphc_ecx) == "1") { ';
			$js .= 'wphc_ebx += Math.pow(2, wphc_ecx); ';
			$js .= '} ';
			$js .= 'wphc_ecx++; ';
			$js .= '} ';
			$js .= 'return wphc_ebx;';

		break;

		/* Multiplication of square roots:
		Time guarantee:  constant time */
		case 2:
			$sqrt = floor(sqrt($val));
			$r = $val - ($sqrt * $sqrt);
			$js .= "return $sqrt * $sqrt + $r; ";
		break;

		/* Sum of random numbers to the final value:
		Time guarantee:  log(n) expected value */
		case 3:
			$js .= 'return ';

			$i = 0;
			while($val > 0){
				if($i++ > 0)
					$js .= '+';

				$temp = rand(1, $val);
				$val -= $temp;
				$js .= $temp;
			}

			$js .= ';';
		break;
	}

	$js .= '} topc_wphc_compute();';

	// pack bytes
	if( !function_exists( 'strToLongs' ) ) {
	function strToLongs($s) {
		$l = array();

		// pad $s to some multiple of 4
		$s = preg_split('//', $s, -1, PREG_SPLIT_NO_EMPTY);

		while(count($s) % 4 != 0){
			$s [] = ' ';
		}

		for ($i = 0; $i < ceil(count($s)/4); $i++) {
			$l[$i] = ord($s[$i*4]) + (ord($s[$i*4+1]) << 8) + (ord($s[$i*4+2]) << 16) + (ord($s[$i*4+3]) << 24);
			}

		return $l;
	}
	}

	// xor all the bytes with a random key
	$key = rand(21474836, 2126008810);
	$js = strToLongs($js);

	for($i = 0; $i < count($js); $i++){
		$js[$i] = $js[$i] ^ $key;
	}

	// libs function encapsulation
	$libs = "function topc_wphc(){\n";

	// write bytes to javascript, xor with key
	$libs .= "\tvar wphc_data = [".join(',',$js)."]; \n";

	// do the xor with key
	$libs .= "\n\tfor (var i=0; i<wphc_data.length; i++){\n";
	$libs .= "\t\twphc_data[i]=wphc_data[i]^$key;\n";
	$libs .= "\t}\n";

	// convert bytes back to string
	$libs .= "\n\tvar a = new Array(wphc_data.length); \n";
	$libs .= "\tfor (var i=0; i<wphc_data.length; i++) { \n";
	$libs .= "\t\ta[i] = String.fromCharCode(wphc_data[i] & 0xFF, wphc_data[i]>>>8 & 0xFF, ";
	$libs .= "wphc_data[i]>>>16 & 0xFF, wphc_data[i]>>>24 & 0xFF);\n";
	$libs .= "\t}\n";

	$libs .= "\n\treturn eval(a.join('')); \n};";

	// return code
	return $libs;
}

function topc_wphc_check() {
	$options = (array) get_option('topc-wp-hashcash');

	// Check the wphc values against the last five keys
	if ( isset($_GET['topcwphc']) && (int) $_GET['topcwphc'] )
		return in_array( (int) $_GET['topcwphc'], (array) $options['key'] );

	return false;
}

function topc_update() {
    global $wpdb, $topc_comment_id;

	if ( ! topc_wphc_check() ) // it's a bot
		exit('1');

	load_plugin_textdomain('topc', '', '/top-comments/languages');

	// (check cookies first)
	$check_cookie = topc_cookiecheck($topc_comment_id);

	if ( 0 === $check_cookie )
		exit ( js_escape( 'error|' . __('Error: Invalid cookie.', 'topc') ) . '|' );
	elseif ( $check_cookie )
		exit ( js_escape( 'error|' . __('Error: You have already rated this comment.', 'topc') ) . '|' );

	$comment = get_comment($topc_comment_id);
	if ( ! $comment )
		exit ( js_escape( 'error|' . __('Error: Bad request.', 'topc') ) . '|' );

	$ip_array = (array) get_option('topc-ip-array');

	// remove previous IPs
	$time = time();
	foreach ( $ip_array as $k => $v ) {
		if ( ($time - 600) > $v ) // 10 min delay
			unset($ip_array[$k]);
	}

	$key = $topc_comment_id . '_' . preg_replace( '/[^0-9.]/', '', $_SERVER['REMOTE_ADDR'] );
	if ( array_key_exists( $key, $ip_array ) )
		$error = __('Please try again later.', 'topc');

	$ip_array[$key] = $time;
	update_option('topc-ip-array', $ip_array);

	if ( isset($error) )
		exit ( js_escape('error|' . $error) . '|' );

	$post = get_post($comment->comment_post_ID);

	if ( ! topc_timelimit($post->post_date_gmt) )
		exit ( js_escape( 'error|' . __('Voting for this comment is closed.', 'topc') ) . '|' );

	$cookie = $_COOKIE['topc_' . COOKIEHASH] . '|' . $topc_comment_id;
	$expire = time() + 2592000; // 30 days
	setcookie('topc_' . COOKIEHASH, $cookie, $expire, COOKIEPATH, COOKIE_DOMAIN);

	$karma = (int) $comment->comment_karma + 1;
	$updated = $wpdb->query( $wpdb->prepare("UPDATE $wpdb->comments SET comment_karma = %d WHERE comment_ID = %d LIMIT 1", $karma, $topc_comment_id) );

	//This sends the data back to the js to process and show on the page
	echo('done|' . $topc_comment_id . '|' . $karma . '|');

	topc_wphc_refresh();

	if ( $karma > (int) get_option( 'topc-top-rated-last' ) )
		topc_make_highest_rated();

	die();
}

// ajax action
$topc_comment_id = ( isset($_GET['topcid']) && isset($_GET['topcwphc']) ) ? (int) $_GET['topcid'] : 0;

if ( $topc_comment_id )
	add_action( 'init', 'topc_update' );

