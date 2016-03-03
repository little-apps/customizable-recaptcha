<?php
/*
Plugin Name: Customizable reCAPTCHA
Plugin URI: https://github.com/little-apps/customizable-recaptcha
Description: Customizable reCAPTCHA allows WordPress admins to use and customize the popular anti-spam solution
Author: Little Apps
Version: 1.0
Author URI: https://www.little-apps.com
License: GNU General Public License v3
*/

( defined( 'ABSPATH' ) ) or die();

define( 'WP_GRECAPTCHA_FILE', __FILE__ );
define( 'WP_GRECAPTCHA_PATH', plugin_dir_path( WP_GRECAPTCHA_FILE ) );

class WPGRecaptcha {
	protected $recaptcha = null;
	
	protected $errorMessages = array(
        'missing-input-secret'		=>	'The secret parameter is missing.',
        'invalid-input-secret'		=>	'The secret parameter is invalid or malformed.',
		'missing-input'				=>	'The response parameter is missing.',
        'missing-input-response'	=>	'The response parameter is missing.',
        'invalid-input-response'	=>	'The response parameter is invalid or malformed.',
    );
	
	protected $captcha_error = null;
	
	protected $required_fields = array(
		'register'			=>	array( '{recaptcha_class}', '{recaptcha_public_key}', '{recaptcha_theme}', '{recaptcha_js}' ),
		'login'				=>	array( '{recaptcha_class}', '{recaptcha_public_key}', '{recaptcha_theme}', '{recaptcha_js}' ),
		'lostpassword'		=>	array( '{recaptcha_class}', '{recaptcha_public_key}', '{recaptcha_theme}', '{recaptcha_js}' ),
		'comments'			=>	array( '{recaptcha_class}', '{recaptcha_public_key}', '{recaptcha_theme}', '{recaptcha_js}' ),
		'comments_error'	=>	array( '{recaptcha_error}' ),
		'shortcode'			=>	array( '{recaptcha_class}', '{recaptcha_public_key}', '{recaptcha_theme}', '{recaptcha_js}', '{error_recaptcha_code}' ),
		'error'				=>	array( '{recaptcha_error}' )
	);
	
	protected $options;
	
	function __construct() {
		 $defaults = array(
			'public_key'			=> '',
			'private_key'			=> '',
			'theme'					=> 'light',
			'custom_css'			=> '',
			'enable_login'			=> false,
			'enable_register'		=> false,
			'enable_lostpassword'	=> false,
			'enable_comments'		=> false,
			'enable_cf7'			=> false,
			'role_except'			=> 'none',
			
			'register_recaptcha_code'		=>	'<div class="{recaptcha_class}" data-sitekey="{recaptcha_public_key}" data-theme="{recaptcha_theme}"></div>
{recaptcha_js}',
			'login_recaptcha_code'			=>	'<div class="{recaptcha_class}" data-sitekey="{recaptcha_public_key}" data-theme="{recaptcha_theme}"></div>
{recaptcha_js}',
			'lostpassword_recaptcha_code'	=>	'<div class="{recaptcha_class}" data-sitekey="{recaptcha_public_key}" data-theme="{recaptcha_theme}"></div>
{recaptcha_js}',
			'comments_error_recaptcha_code'	=>	'<div class="info-box box-info"><span class="close"></span><p>{recaptcha_error}</p></div>',
			'comments_recaptcha_code'		=>	'<div class="{recaptcha_class}" data-sitekey="{recaptcha_public_key}" data-theme="{recaptcha_theme}"></div>
{recaptcha_js}',
			'cf7_recaptcha_code'			=>	'{error_recaptcha_code}
<div class="{recaptcha_class}" data-sitekey="{recaptcha_public_key}" data-theme="{recaptcha_theme}"></div>
{recaptcha_js}',
			'error_recaptcha_code'			=>	'<div id="g-recaptcha-comment-error">{recaptcha_error}</div>',
		);
		
		require_once( WP_GRECAPTCHA_PATH . 'options.php' );
		$this->options = new WPGRecaptchaOptions( 'wp-grecaptcha', $defaults );
		
		add_action( 'init', array( $this, 'init' ) );

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		
		// Listen for the activate event
        register_activation_hook( WP_GRECAPTCHA_FILE, array( $this, 'activate' ) );

        // Deactivation plugin
        register_deactivation_hook( WP_GRECAPTCHA_FILE, array( $this, 'deactivate' ) );
	}
	
	public function init() {
		if ( !empty( $this->options->public_key ) && !empty( $this->options->private_key ) && $this->enable_for_user() ) {
			add_action( 'wp_head', array( $this, 'custom_styles' ) );
			
			if ( $this->options->enable_register ) {
				add_action( 'register_form', array( $this, 'display_register' ) );
				add_filter( 'registration_errors', array( $this, 'check_recaptcha_register' ), 10, 3 );
			}
			
			if ( $this->options->enable_login ) {
				add_action( 'login_form', array( $this, 'display_login' ) );
				add_action( 'authenticate', array( $this, 'check_recaptcha_login' ), 22 );
			}
			
			if ( $this->options->enable_lostpassword ) {
				add_action( 'lostpassword_form', array( $this, 'display_lostpassword' ) );
				add_filter( 'allow_password_reset', array( $this, 'check_recaptcha_lostpassword' ), 10, 3 );
			}
			
			if ( $this->options->enable_comments ) {
				if ( !empty( $_GET['captcha'] ) && $_GET['captcha'] == 'failed' )
					$this->captcha_error = true;
				
				//add_action('comment_form', array($this, 'display_comments'));
				add_action( 'comment_form_before', array( $this, 'comment_form_errors' ) );
				add_filter( 'comment_form_submit_field', array( $this, 'display_comments' ), PHP_INT_MAX, 2 );
				add_action( 'wp_head', array( $this, 'check_recaptcha_comments_head' ) );
				add_filter( 'preprocess_comment', array( $this, 'check_recaptcha_comments' ) );
				add_filter( 'comment_post_redirect', array( $this, 'check_recaptcha_comments_post_redirect' ), 10, 2 );
			}
			
			if ( $this->options->enable_cf7 ) {
				add_action( 'wp_head', array( $this, 'check_recaptcha_shortcode' ) );
				add_shortcode( 'recaptcha', array( $this, 'display_shortcode' ) );
			}
		}
	}
	
	protected function enable_for_user() {
		if ( $this->options->role_except == 'none' )
			// Display for all users
			return true;
		else if ( current_user_can( $this->options->role_except ) )
			// User has role so don't display recaptcha
			return false;
		else
			// User doesn't have role so display recaptcha
			return true;
	}
	
	protected function cf7_loaded( $output_warnings = false ) {
		if ( !defined( 'WPCF7_VERSION' ) )
			return false;
		
		if ( version_compare( WPCF7_VERSION, '4.1', '>=' ) ) {
			return true;
		} else {
			if ( $output_warnings && current_user_can( 'manage_options' ) )
				echo '<br><strong>Note:</strong> You appear to running a version of Contact Form 7 that has been fully tested and therefore some features may not work properly.<br>';
			
			return true;
		}
	}
	
	public function display_register() {
		$this->display( 'register' );
	}
	
	public function display_login() {
		$this->display( 'login' );
	}
	
	public function display_lostpassword() {
		$this->display( 'lostpassword' );
	}
	
	public function comment_form_errors() {
		if ( $this->captcha_error ) {
			$this->display( 'comments_error' );
			//echo '<div class="info-box box-info"><span class="close"></span><p>' . __( 'You have entered an incorrect CAPTCHA value.' ) . '</p></div>';
		}
	}
	
	public function display_comments( $submit_field, $args ) {
		//$this->display('comments');
		
		return $this->display( 'comments', false ) . $submit_field;
	}
	
	public function display_shortcode($atts = '', $content = '') {
		$this->display( 'shortcode' );
	}
	
	protected function display($form, $echo = true) {
		$fields = array(
			'{recaptcha_class}'			=>	esc_attr( 'g-recaptcha g-recaptcha-'.$form ), 
			'{recaptcha_public_key}'	=>	esc_attr( $this->options->public_key ), 
			'{recaptcha_theme}'			=>	esc_attr( $this->options->theme ), 
			'{recaptcha_js}'			=>	'<script type="text/javascript" src="https://www.google.com/recaptcha/api.js"></script><noscript>Javascript must enabled in order to continue.</noscript>', 
			'{recaptcha_error}'			=>	'<strong>' . __( 'Error' ) . '</strong>: ' . __( 'You have entered an incorrect CAPTCHA value.' ),
			//'{error_recaptcha_code}' => (in_array('{error_recaptcha_code}', $this->required_fields[$form]) && $this->captcha_error ? $this->display('error', false) : ''), 
		);
		
		$code = $this->options->{$form.'_recaptcha_code'};
		
		foreach ( $this->required_fields[$form] as $field ) {
			if ( !isset( $fields[$field] ) )
				continue;
			
			$data = $fields[$field];
			
			$code = str_replace( $field, $data, $code );
		}
		
		if ($echo)
			echo $code;
		else
			return $code;
	}
	
	public function check_recaptcha_lostpassword( $allow = true, $user_id = 0 ) {
		if ( !$this->check_recaptcha() ) {
			$errors = new WP_Error('wpgrecaptcha_error', '<strong>' . __( 'Error' ) . '</strong>: ' . __( 'You have entered an incorrect CAPTCHA value.' ));
			
			return $errors;
		}
		
		return $allow;
	}
	
	public function check_recaptcha_register( $errors = null, $sanitized_user_login = '', $user_email = '' ) {
		if ( !isset( $errors ) )
			$errors = new WP_Error();
		
		if ( !$this->check_recaptcha() )
			$errors->add( 'wpgrecaptcha_error', '<strong>' . __( 'Error' ) . '</strong>: ' . __( 'You have entered an incorrect CAPTCHA value.' ) );
		else
			return $errors;
	}
	
	public function check_recaptcha_login( $user = null ) {
		if ( !isset( $user ) )
			// User isn't set so this will always fail
			return null;
			
		if ( $this->check_recaptcha() ) {
			return $user;
		} else {
			wp_clear_auth_cookie();
			
			if ( is_wp_error( $user ) )
				$error = $user;
			else
				$error = new WP_Error();
			
			$error->add( 'wpgrecaptcha_error', '<strong>' . __( 'Error' ) . '</strong>: ' . __( 'You have entered an incorrect CAPTCHA value.' ) );

			return $error;
		}
	}
	
	public function check_recaptcha_comments_post_redirect( $location, $comment ) {
        if ( isset( $this->captcha_error ) && $this->captcha_error ) {
            $location = 
				add_query_arg( 
					array( 'comment-id' => $comment->comment_ID, 'captcha' => 'failed' ),
					$location
				);
        }
 
        return $location;
	}
	
	public function check_recaptcha_comments_head() {
		if ( !empty( $_GET['comment-id'] ) && is_numeric( $_GET['comment-id'] ) ) {
			$comment_id = absint( $_GET['comment-id'] );
			
            wp_delete_comment( $comment_id );
        }
	}
	
	public function check_recaptcha_comments( $comment ) {
		// If comment made via admin panel -> skip recaptcha check
		if ( !empty( $_REQUEST['action'] ) && $_REQUEST['action'] == 'replyto-comment' &&
			( check_ajax_referer( 'replyto-comment', '_ajax_nonce', false ) || check_ajax_referer( 'replyto-comment', '_ajax_nonce-replyto-comment', false ) ) )
			return $comment;

		// If trackback or pingback -> skip recaptcha check
		if ( !empty($comment['comment_type']) && $comment['comment_type'] != 'comment' )
			return $comment;

		$this->check_recaptcha();
			
		return $comment;
	}
	
	protected function check_recaptcha() {
		if ( !isset( $this->recaptcha ) || !is_a( $this->recaptcha, 'ReCaptcha' ) ) {
			if ( !class_exists( 'ReCaptcha' ) )
				require_once( WP_GRECAPTCHA_PATH . '/recaptchalib.php' );
			$this->recaptcha = new ReCaptcha( $this->options->private_key );
		}
		
		$response = ( isset($_POST["g-recaptcha-response"]) ? $_POST["g-recaptcha-response"] : '' );
		
		$resp = $this->recaptcha->verifyResponse( $_SERVER['REMOTE_ADDR'], $response );
		
		if ( !isset( $resp ) || !is_a( $resp, 'ReCaptchaResponse' ) ) {
			wp_die( __( 'Error: There was an error verifying your CAPTCHA response. Please try again.' ) );
		} 
		
		if ( $resp->success ) {
			$this->captcha_error = false;
			return true;
		} else {
			$this->captcha_error = true;
			return false;
		}
	}
	
	public function custom_styles() {
		if ( !empty( $this->options->custom_css ) ) {
?>
			<style type="text/css">
				<?php echo strip_tags( $this->options->custom_css ); ?>
			</style>
<?php
		}
	}
	
	public function activate() {
        $this->options->update_options();
    }

    public function deactivate() {
        $this->options->delete_options();
    }
	
    public function admin_init() {
		if ( $this->cf7_loaded() && function_exists( 'wpcf7_add_tag_generator' ) ) {
			wpcf7_add_tag_generator(
				'customizable-recaptcha',
				'Customizable reCAPTCHA', // this string needs no translation
				'wpcf7-tg-pane-customizable-recaptcha',
				array( $this, 'display_cf7_tag' )
			);
		}
		
		if ( current_user_can( 'manage_options' ) )
			// White list our options using the Settings API
			register_setting( 'wpgrecaptcha_options', $this->options->get_option_name(), array( $this, 'validate' ) );
    }
	
	public function display_cf7_tag() {
?>
<div id="wpcf7-tg-pane-customizable-recaptcha" class="hidden">
	<form>
		<table>
			<tr>
				<td colspan="2">
					<strong style="color: #e6255b"><?php echo esc_html( __( 'This reCAPTCHA tag is provided by the Customizable reCAPTCHA plugin.' ) ); ?></strong>
				</td>
			</tr>

			<tr>
				<td><?php echo esc_html( __( 'Name', 'contact-form-7' ) ); ?><br />
				<input type="text" name="name" class="tg-name oneline" /></td>
				<td></td>
			</tr>
		</table>

		<div class="tg-tag">
			<?php echo esc_html( __( "Copy this code and paste it into the form on the left.", 'contact-form-7' ) ); ?><br />
			<input type="text" name="customizable-recaptcha" class="tag" readonly="readonly" onfocus="this.select()" />
		</div>

	</form>

</div>
<?php
	}

    // Add entry in the settings menu
    public function add_page() {
		if ( current_user_can( 'manage_options' ) )
			add_options_page( 'Customizable reCAPTCHA', 'Customizable reCAPTCHA', 'manage_options', 'wpgrecaptcha_options', array( $this, 'options_do_page' ) );
    }

    // Print the menu page itself
    public function options_do_page() {
        $this->options->get_options();

?>
        <div class="wrap">
            <h2>Customizable reCAPTCHA Options</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'wpgrecaptcha_options' ); ?>
                <table class="form-table">
                    <tr valign="top"><th scope="row">Public Key:</th>
                        <td><input type="text" name="<?php echo $this->options->get_option_name( '[public_key]' ); ?>" value="<?php echo $this->options->public_key; ?>" style="width: 100%" /></td>
                    </tr>
                    <tr valign="top"><th scope="row">Private Key:</th>
                        <td><input type="text" name="<?php echo $this->options->get_option_name( '[private_key]' ); ?>" value="<?php echo $this->options->private_key; ?>" style="width: 100%" /></td>
                    </tr>
					<tr valign="top">
						<th colspan="2" style="font-weight: normal">
							You can get the public and private key for recaptcha from <a href="https://www.google.com/recaptcha/admin" target="_blank">www.google.com/recaptcha/admin</a>.
						</th>
					</tr>
					<tr valign="top"><th scope="row">Theme:</th>
                        <td>
							<select name="<?php echo $this->options->get_option_name( '[theme]' ); ?>">
								<option value="light"<?php echo ( $this->options->theme == 'light' ? ' selected' : '' ); ?>>Light</option>
								<option value="dark"<?php echo ( $this->options->theme == 'dark' ? ' selected' : '' ); ?>>Dark</option>
							</select>
						</td>
                    </tr>
					<tr valign="top"><th scope="row">Enable reCaptcha On:</th>
						<td>
							<label><input type="checkbox" name="<?php echo $this->options->get_option_name( '[enable_register]' ); ?>" value="enable_register"<?php echo ( $this->options->enable_register ? ' checked' : '' ); ?>>Registration Form</label><br />
							<label><input type="checkbox" name="<?php echo $this->options->get_option_name( '[enable_login]' ); ?>" value="enable_login"<?php echo ( $this->options->enable_login ? ' checked' : '' ); ?>>Login Form</label><br />
							<label><input type="checkbox" name="<?php echo $this->options->get_option_name( '[enable_lostpassword] '); ?>" value="enable_lostpassword"<?php echo ( $this->options->enable_lostpassword ? ' checked' : '' ); ?>>Lost Password Form</label><br />
							<label><input type="checkbox" name="<?php echo $this->options->get_option_name( '[enable_comments] '); ?>" value="enable_comments"<?php echo ( $this->options->enable_comments ? ' checked' : '' ); ?>>Comments</label><br />
							<?php if ($this->cf7_loaded(true)) : ?>
							<label><input type="checkbox" name="<?php echo $this->options->get_option_name( '[enable_cf7]' ); ?>" value="enable_comments"<?php echo ( $this->options->enable_cf7 ? ' checked' : '' ); ?>>Contact Form 7</label><br />
							<?php else : ?>
							<label><input type="checkbox" name="<?php echo $this->options->get_option_name( '[enable_cf7]' ); ?>" value="enable_comments"<?php echo ( $this->options->enable_cf7 ? ' checked' : '' ); ?> disabled>Contact Form 7</label><br />
							<?php endif; ?>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Register reCAPTCHA Code:</th>
						<td><textarea name="<?php echo $this->options->get_option_name( '[register_recaptcha_code] '); ?>" style="width: 100%; height: 185px;"><?php esc_attr_e( $this->options->register_recaptcha_code ); ?></textarea></td>
					</tr>
					<tr valign="top">
						<th scope="row">Login reCAPTCHA Code:</th>
						<td><textarea name="<?php echo $this->options->get_option_name( '[login_recaptcha_code] '); ?>" style="width: 100%; height: 185px;"><?php esc_attr_e( $this->options->login_recaptcha_code ); ?></textarea></td>
					</tr>
					<tr valign="top">
						<th scope="row">Lost Password reCAPTCHA Code:</th>
						<td><textarea name="<?php echo $this->options->get_option_name( '[lostpassword_recaptcha_code] '); ?>" style="width: 100%; height: 185px;"><?php esc_attr_e( $this->options->lostpassword_recaptcha_code ); ?></textarea></td>
					</tr>
					<tr valign="top">
						<th scope="row">Comments Error Code:</th>
						<td><textarea name="<?php echo $this->options->get_option_name( '[comments_error_recaptcha_code]' ); ?>" style="width: 100%; height: 185px;"><?php esc_attr_e( $this->options->comments_error_recaptcha_code ); ?></textarea></td>
					</tr>
					<tr valign="top">
						<th scope="row"></th>
						<td style="font-weight: normal">This is the error that will be displayed above the comments form.</td>
					</tr>
					<tr valign="top">
						<th scope="row">Comments reCAPTCHA Code:</th>
						<td><textarea name="<?php echo $this->options->get_option_name( '[comments_recaptcha_code]' ); ?>" style="width: 100%; height: 185px;"><?php esc_attr_e( $this->options->comments_recaptcha_code ); ?></textarea></td>
					</tr>
					<tr valign="top">
						<th scope="row">reCAPTCHA Error Code:</th>
						<td><textarea name="<?php echo $this->options->get_option_name( '[error_recaptcha_code]' ); ?>" style="width: 100%; height: 185px;"><?php esc_attr_e( $this->options->error_recaptcha_code ); ?></textarea></td>
					</tr>
					<tr valign="top">
						<th scope="row">Contact Form 7 Code:</th>
						<td><textarea name="<?php echo $this->options->get_option_name( '[cf7_recaptcha_code]' ); ?>" style="width: 100%; height: 185px;"<?php echo ( !$this->cf7_loaded() ? ' disabled' : '' ); ?>><?php esc_attr_e( $this->options->cf7_recaptcha_code ); ?></textarea></td>
					</tr>
					<tr valign="top">
						<th colspan="2" style="font-weight: normal">
							<strong>{recaptcha_class}</strong> represents the class that the recaptcha element will have (see below for defaults) and is required for the recaptcha to be displayed.<br />
							<strong>{recaptcha_public_key}</strong> represents the public key for recaptcha and is required for the recaptcha to be displayed.<br />
							<strong>{recaptcha_theme}</strong> represents the theme recaptcha will use and is required for the recaptcha to be displayed.<br />
							<strong>{recaptcha_js}</strong> represents the needed Javascript library to load recaptcha and is required for the recaptcha to be displayed.<br />
							<strong>{recaptcha_error}</strong> represents the error message (without HTML) if the recaptcha fails to be validated. This is only required in the recaptcha error code.<br />
							<strong>{error_recaptcha_code}</strong> represents the error message (with HTML) if the recaptcha fails to be validated. This is only required in the comments code.
						</th>
					</tr>
					<tr valign="top"><th scope="row">Don't Enable reCAPTCHA For:</th>
                        <td>
							<select name="<?php echo $this->options->get_option_name( '[role_except]' ); ?>">
								<?php foreach ( get_editable_roles() as $role_name => $role_info ) : ?>
								<option value="<?php esc_attr_e( $role_name ); ?>"<?php echo ( $this->options->role_except == $role_name ? ' selected' : '' ); ?>><?php echo ucfirst( $role_name ); ?></option>
								<?php endforeach; ?>
								<option value="none"<?php echo ( $this->options->role_except == 'none' ? ' selected' : '' ); ?>>None</option>
							</select>
						</td>
                    </tr>
					<tr valign="top"><th scope="row">Custom CSS:</th>
                        <td><textarea name="<?php echo $this->options->get_option_name( '[custom_css]' ); ?>" style="width: 100%; height: 185px;"><?php esc_attr_e( $this->options->custom_css ); ?></textarea></td>
                    </tr>
					<tr valign="top">
						<th scope="row">CSS Notes:</th>
						<td>
							All HTML tags will be stripped from the CSS.<br />
							All recaptcha fields have the class <strong>g-recaptcha</strong>.<br />
							The login recaptcha has the class <strong>g-recaptcha-login</strong>.<br />
							The registration recaptcha has the class <strong>g-recaptcha-register</strong>.<br />
							The lost password recaptcha has the class <strong>g-recaptcha-lostpassword</strong>.<br />
							The comments recaptcha has the class <strong>g-recaptcha-comments</strong>.
						</td>
					</tr>
					<tr valign="top">
						<th colspan="2" style="text-align: center; font-weight: bold">
							This Wordpress plugin was created by <a target="_blank" href="https://www.little-apps.com">Little Apps</a>. If you would like to show your support for this Wordpress plugin, then you can <a target="_blank" href="https://www.little-apps.com/?donate">make a donation</a>.
						</th>
					</tr>
                </table>
                <p class="submit">
                    <input type="submit" class="button-primary" value="<?php _e( 'Save Changes' ) ?>" />
                </p>
            </form>
        </div>
        <?php
    }

    public function validate( $input ) {
        $valid = array();
		
        $valid['public_key'] = sanitize_text_field( $input['public_key'] );
        $valid['private_key'] = sanitize_text_field( $input['private_key'] );
		$valid['theme'] = sanitize_text_field( $input['theme'] );
		$valid['custom_css'] = $input['custom_css'];
		$valid['role_except'] = sanitize_text_field( $input['role_except'] );

        if ( empty( $valid['public_key'] ) ) {
            add_settings_error(
                    'public_key', 					// setting title
                    'wpgrecaptcha_texterror',			// error ID
                    'Please enter a valid public key',		// error message
                    'error'							// type of message
            );
			
			# Set it to the default value
			$valid['public_key'] = $this->options->get_option_default('public_key');
        }
		
		if ( empty( $valid['private_key'] ) ) {
            add_settings_error(
                    'private_key', 					// setting title
                    'wpgrecaptcha_texterror',			// error ID
                    'Please enter a valid private key',		// error message
                    'error'							// type of message
            );
			
			# Set it to the default value
			$valid['private_key'] = $this->options->get_option_default('private_key');
        }
		
		if ( !in_array( $valid['theme'], array( 'light', 'dark' ) ) ) {
			add_settings_error(
                    'theme', 					// setting title
                    'wpgrecaptcha_texterror',			// error ID
                    'The theme must be either "light" or "dark"',		// error message
                    'error'							// type of message
            );
			
			# Set it to the default value
			$valid['theme'] = $this->options->get_option_default( 'theme' );
		}
		
		$valid_roles = array_merge( array_keys( get_editable_roles() ), array( 'none' ) );
		
		if ( !in_array( $valid['role_except'], $valid_roles ) ) {
			add_settings_error(
                    'role_except', 					// setting title
                    'wpgrecaptcha_texterror',			// error ID
                    'The role must be one of the following: ' . implode( ', ', $valid_roles ),		// error message
                    'error'							// type of message
            );
			
			# Set it to the default value
			$valid['role_except'] = $this->options->get_option_default( 'role_except' );
		}
		
		foreach ( array( 'register', 'login', 'lostpassword', 'comments', 'comments_error', 'error' ) as $form ) {
			$this->validate_code( $valid, $input, $form );
		}
		
        return $valid;
    }
	
	protected function validate_code( &$valid, $input, $form ) {
		$setting_enable = 'enable_' . $form;
		$setting_code = $form . '_recaptcha_code';
		
		if ( $form != 'error' && $form != 'comments_error' ) {
			if ( isset( $input[$setting_enable] ) && $input[$setting_enable] == $setting_enable )
				$valid[$setting_enable] = true;
			else
				$valid[$setting_enable] = false;
		}
		
		if ( !isset( $this->required_fields[$form] ) )
			return;

		if ( !empty( $input[$setting_code] ) ) {
			$error_found = false;
			
			foreach ( $this->required_fields[$form] as $field ) {
				if ( strpos( $input[$setting_code], $field ) === false ) {
					$message = 'The code for "' . $form . '" is missing one of the following fields: ' . implode( ', ', $this->required_fields[$form] ) . '. ';
					
					if ( $form == 'error' || $form == 'comments_error' ) {
						$message .= 'The comments recaptcha has been disabled.';
						$valid['enable_comments'] = false;
					} else {
						$message .= 'The recaptcha for the "'.$form.'" form has been disabled.';
						$valid[$setting_enable] = false;
					}
					
					add_settings_error(
						$setting_code, 				// setting title
						'wpgrecaptcha_texterror',	// error ID
						$message,					// error message
						'error'						// type of message
					);
					
					$error_found = true;

					break;
				}
			}
			
			if ( !$error_found )
				$valid[$setting_code] = $input[$setting_code];
			else
				$valid[$setting_code] = $this->options->get_option_default( $setting_code );
		} else {
			$valid[$setting_code] = $this->options->get_option_default( $setting_code );
			
			add_settings_error(
					$setting_code, 					// setting title
					'wpgrecaptcha_texterror',			// error ID
					'The recaptcha code for the "' . $form . '" cannot be empty and was set to the default code',		// error message
					'error'							// type of message
			);
		}
		
		
	}
}

if ( !defined( 'WPGRECAPTCHA_INITIALIZED' ) ) {
	new WPGRecaptcha();
	define( 'WPGRECAPTCHA_INITIALIZED', true );
}