<?php

namespace EP4\WPCLI_Anonymizer;

use Faker\Factory;
use WP_CLI;
use WP_CLI_Command;

/**
 * Rewrites personally identifying information (PII) in user profiles and comments.
 *
 * @package EP4\WPCLI_Anonymizer
 */
class WPCLI_Anonymize_Users_Command extends WP_CLI_Command {
	/**
	 * User ids to skip.
	 *
	 * @var array
	 */
	protected $excluded_user_ids = array();

	/**
	 * If we can't find a user id for a name or email, should we bail?
	 *
	 * @var boolean
	 */
	protected $skip_not_found_users = false;

	/**
	 * Site id to restrict rewrite to.
	 *
	 * @var integer
	 */
	protected $limit_to_site = null;

	/**
	 * Whether empty user fields should be updated or not. Default: FALSE.
	 *
	 * @var boolean
	 */
	protected $ignore_empty_fields = false;

	/**
	 * Language of the fake content. Default: 'en_US'.
	 * @see https://github.com/FakerPHP/Faker/tree/main/src/Faker/Provider List of available locales.
	 *
	 * @var string
	 */
	protected $locale = 'en_US';

	/**
	 * A number used to keep the same fake generated content. Default: NULL.
	 *
	 * @var null|integer
	 */
	protected $seed = null;

	/**
	 * A list of custom domains to use for generating fake emails. Default: empty array.
	 *
	 * @var array
	 */
	protected $custom_email_domains = array();

	/**
	 * A list of custom user meta fields for which fake data must be generated. Default: empty array.
	 *
	 * @var array
	 */
	protected $custom_fields = array();


	/**
	 * Performs personal information replacement.
	 *
	 * ## OPTIONS
	 *
	 * [--keep=<user_id|user_login|email>]
	 * : User(s) to skip during replacement.
	 *
	 * [--skip-not-found]
	 * : Skip users to keep if not found, fails otherwise.
	 *
	 * [--site=<site_id>]
	 * : Site id to limit rewrites to.
	 *
	 * [--ignore-empty-fields]
	 * : Don't update fields that are currently empty.
	 *
	 * [--language=<locale>]
	 * : The language of the fake content. Default: 'en_US'.
	 *
	 * [--seed=<integer>]
	 * : A number used to keep generating the same fake content. Default: NULL.
	 *
	 * [--custom-email-domains=<list.com,domains.net>]
	 * : A list of domains separated by comma to use for fake emails. Default: NULL.
	 *
	 * [--custom-fields=<user_meta_name::faker_format_method,user_phone_number::phone,user_custom_meta>]
	 * : A list of custom user meta fields separated by comma for which fake data must be generated. Default: NULL.
	 * : A custom user meta name must be associated to a faker method, by appending the method to the meta name followed by ::.
	 * : For example, to create fake data for the user_phone meta, use `user_phone::phone`. If no method is provided, the default
	 * : method used will be `realText( 10, 20, 3 )`.
	 *
	 * ## EXAMPLES
	 *
	 *     # Rewrite all user profiles and comments.
	 *     $ wp anonymize users
	 *     Success: Rewrote all user data.
	 *
	 *     # Rewrite all user profiles except for user_id 123.
	 *     $ wp anonymize users --keep=1
	 *     Success: All comments and users except: '1' rewritten.
	 *
	 *     # Rewrite all user profiles except ones matching user id 123, user login admin, and/or test@example.com and skip those if not found.
	 *     $ wp anonymize users --keep="2,admin,test@example.com" --skip-not-found
	 *     Success: All comments and users except: '2,1,3' rewritten.
	 *
	 *     # Rewrite only comments and users for one site on a multi-site install.
	 *     $ wp anonymize users --site=3
	 *     Success: All comments and users on site '3' rewritten.
	 *
	 *     # Rewrite all user profiles but don't update empty user fields.
	 *     $ wp anonymize users --ignore-empty-fields
	 *     Success: Rewrote all user data.
	 *
	 *     # Rewrite all user profiles using French data.
	 *     $ wp anonymize users --language=fr_FR --seed=1000
	 *     Success: Rewrote all user data.
	 *
	 *     # Rewrite all user profiles using either the 'test.com', 'test.org' or 'domain.net' domain for emails.
	 *     $ wp anonymize users --custom-email-domains=test.com,test.org,domain.net
	 *     Success: Rewrote all user data.
	 *
	 *     # Rewrite all user profiles in French along with specific user meta, but don't update empty user fields.
	 *     $ wp anonymize users --ignore-empty-fields --language=fr_FR --custom-fields=user_phone::phone,user_city::city,user_company
	 *     Success: Rewrote all user data.
	 */
	public function __invoke( $args, $assoc_args ) {
		if ( ! empty( $args ) ) {
			WP_CLI::warning( 'unknown argument' );
		}

		if ( isset( $assoc_args['skip-not-found'] ) ) {
			$this->skip_not_found_users = true;
		}

		if ( isset( $assoc_args['ignore-empty-fields'] ) ) {
			$this->ignore_empty_fields = true;
		}

		if ( isset( $assoc_args['ignore-empty-fields'] ) ) {
			$this->ignore_empty_fields = true;
		}

		if ( ! empty( $assoc_args['language'] ) ) {
			$this->locale = $assoc_args['language'];
		}

		if ( ! empty( $assoc_args['seed'] ) && is_numeric( $assoc_args['seed'] ) ) {
			$this->seed = $assoc_args['seed'];
		}

		if ( ! empty( $assoc_args['custom-email-domains'] ) ) {
			$this->custom_email_domains = explode( ',', $assoc_args['custom-email-domains'] );
		}

		if ( ! empty( $assoc_args['custom-fields'] ) ) {
			$fields        = explode( ',', $assoc_args['custom-fields'] );
			$custom_fields = array();

			foreach ( $fields as $field ) {
				$field        = explode( '::', $field );
				$field_name   = $field[0];
				$faker_method = ! empty( $field[1] ) ? $field[1] : null;

				$custom_fields[ $field_name ] = $faker_method;
			}

			$this->custom_fields = $custom_fields;
		}

		if ( isset( $assoc_args['keep'] ) && ! empty( $assoc_args['keep'] ) ) {
			$this->set_excluded_user_ids( $assoc_args['keep'] );
		}

		if ( isset( $assoc_args['site'] ) ) {
			if ( ! is_multisite() ) {
				WP_CLI::error( 'site parameter only valid on multi-site installs.' );
			}
			if ( is_numeric( $assoc_args['site'] ) ) {
				$this->limit_to_site = (int) $assoc_args['site'];

			} else {
				WP_CLI::error( 'site must be a number' );
			}
		}

		WP_CLI::confirm( 'Rewrite all user data?', $assoc_args );
		$users_updated    = $this->obfuscate_users();
		$comments_updated = $this->obfuscate_comments();
		$items            = array(
			array(
				'Updated' => 'Users',
				'Count'   => $users_updated,
			),
			array(
				'Updated' => 'Comments',
				'Count'   => $comments_updated,
			),
		);
		WP_CLI\Utils\format_items( 'table', $items, array( 'Updated', 'Count' ) );
		if ( count( $this->excluded_user_ids ) > 0 ) {
			$ids_string = implode( ',', $this->excluded_user_ids );
			if ( ! empty( $this->limit_to_site ) ) {
				WP_CLI::success( sprintf(
					'All comments and users except: \'%s\' on site \'%s\' rewritten.',
					$ids_string,
					$this->limit_to_site
				) );
			} else {
				WP_CLI::success( sprintf( 'All comments and users except: \'%s\' rewritten.', $ids_string ) );
			}
		} else {
			if ( ! empty( $this->limit_to_site ) ) {
				WP_CLI::success( sprintf( 'All comments and users on site \'%s\' rewritten.' ) );
			} else {
				WP_CLI::success( sprintf( 'All comments and users rewritten.' ) );
			}
		}
	}

	/**
	 * Rewrite the PII found in standard WordPress comments.
	 *
	 * @return integer Number of comments updated.
	 */
	protected function obfuscate_comments() {
		$faker        = $this->get_faker();
		$count        = 0;
		$all_comments = array();

		if ( ! is_multisite() ) {
			$data                        = $this->gather_comments( null );
			$count                       = count( $data );
			$all_comments['single_site'] = $data;
		} else {
			if ( ! empty( $this->limit_to_site ) ) {
				$site = get_site( $this->limit_to_site );
				if ( ! empty( $site ) ) {
					$data                           = $this->gather_comments( $site->blog_id );
					$count                          = $count + count( $data );
					$all_comments[ $site->blog_id ] = $data;
				} else {
					WP_CLI::error( 'Site not found.' );
				}
			} else {
				$sites = get_sites();
				foreach ( $sites as $site ) {
					$data                           = $this->gather_comments( $site->blog_id );
					$count                          = $count + count( $data );
					$all_comments[ $site->blog_id ] = $data;
				}
			}
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Rewriting comments...', $count );
		foreach ( $all_comments as $blog_id => $comments ) {
			foreach ( $comments as $comment ) {
				if ( 'single_site' !== $blog_id && is_multisite() ) {
					switch_to_blog( $blog_id );
				}
				$commentarr                         = $comment->to_array();
				$commentarr['comment_author']       = $faker->name;
				$commentarr['comment_author_email'] = $faker->safeEmail;
				$commentarr['comment_author_url']   = $faker->url;
				$commentarr['comment_author_IP']    = $faker->ipv4;
				$commentarr['comment_agent']        = $faker->userAgent;
				/**
				 * Pre-update Comment.
				 *
				 * Triggered before a single comment is updated with fake information. Allows you to modify custom meta fields when the plugin is triggered.
				 *
				 * @since 1.0.0
				 *
				 * @param WP_Comment $comment original WP_Comment object.
				 * @param array $commentarr new data about to be written to the database.
				 * @param Factory $faker the faker object, made available for you to generate fake data for meta fields etc.
				 */
				do_action( 'ep4_wpcli_anonymizer_pre_update_comment', $comment, $commentarr, $faker );
				wp_update_comment( $commentarr );
				/**
				 * Post update comment.
				 *
				 * Triggered after a single comment is updated with fake information.
				 *
				 * @since 1.0.0
				 *
				 * @param WP_Comment $comment original WP_Comment object.
				 * @param array $commentarr new data about to be written to the database.
				 * @param Factory $faker the faker object, made available for you to generate fake data for meta fields etc.
				 */
				do_action( 'ep4_wpcli_anonymizer_post_update_comment', $comment, $commentarr, $faker );
				$progress->tick();
				if ( is_multisite() ) {
					restore_current_blog();
				}
			}
		}
		$progress->finish();
		return $count;
	}

	/**
	 * Gather comments for a single blog.
	 *
	 * @param integer $blog_id blog id.
	 *
	 * @return array
	 */
	protected function gather_comments( $blog_id ) {
		if ( is_int( $blog_id ) && is_multisite() ) {
			switch_to_blog( $blog_id );
		}
		$trash_comments   = get_comments( array( 'status' => 'trash' ) );
		$spam_comments    = get_comments( array( 'status' => 'spam' ) );
		$regular_comments = get_comments();

		if ( is_multisite() ) {
			restore_current_blog();
		}

		return array_merge( $regular_comments, $trash_comments, $spam_comments );
	}

	/**
	 * Loop over all the users found and replace their personal data.
	 *
	 * @return integer Number of users updated.
	 */
	protected function obfuscate_users() {
		$users = array();
		if ( is_multisite() ) {
			if ( ! empty( $this->limit_to_site ) ) {
				$sites[] = get_site( $this->limit_to_site );
			} else {
				$sites = get_sites();
			}
			foreach ( $sites as $site ) {
				$site_users = get_users(
					array(
						'blog_id' => $site->blog_id,
						'exclude' => $this->excluded_user_ids,
					)
				);
				$users      = array_merge( $users, $site_users );
			}
		} else {
			$users = get_users(
				array(
					'exclude' => $this->excluded_user_ids,
				)
			);
		}
		if ( count( $users ) <= 0 ) {
			WP_CLI::warning( 'No users changed (did you exclude them all?)' );
			return 0;
		}
		$count    = count( $users );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Rewriting users...', $count );
		foreach ( $users as $user ) {
			$this->obfuscate_user( $user );
			if ( null !== $this->seed ) {
				$this->seed++; // Increases the seed or else the data will be the same for all users.
			}
			$progress->tick();
		}
		$progress->finish();

		return $count;
	}

	/**
	 * Replace a single user's data.
	 *
	 * @param WP_User $user WordPress user object.
	 * @return void
	 */
	private function obfuscate_user( $user ) {
		$faker         = $this->get_faker();
		$original_user = $user;
		$new_data      = $this->get_fake_user_profile_data();

		foreach ( $new_data as $key => $value ) {
			if ( $user->has_prop( $key ) ) {
				if ( ! $this->ignore_empty_fields || ! empty( $user->get( $key ) ) ) {
					$user->{$key} = $value;
				}
			}
		}

		/**
		 * Pre update user.
		 *
		 * Triggered after a single user is updated with fake information.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_User $original_user original WP_User object.
		 * @param WP_User $user new data about to be written to the database.
		 * @param Factory $faker the faker object, made available for you to generate fake data for meta fields etc.
		 */
		do_action( 'ep4_wpcli_anonymizer_pre_update_user', $original_user, $user, $faker );
		wp_update_user( $user );

		$this->update_user_login( $user->ID, $new_data['user_login'] );

		/**
		 * Post update user.
		 *
		 * Triggered after a single user is updated with fake information.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_User $original_user original WP_User object.
		 * @param WP_User $user new data about to be written to the database.
		 * @param Factory $faker the faker object, made available for you to generate fake data for meta fields etc.
		 */
		do_action( 'ep4_wpcli_anonymizer_pre_update_user', $original_user, $user, $faker );
	}

	protected function get_fake_user_profile_data() {
		$faker = $this->get_faker();

		$first_name    = $faker->firstName();
		$last_name     = $faker->lastName();
		$display_name  = $first_name . ' ' . $last_name;
		$user_login    = strtolower( str_replace( '-', '.', sanitize_title( $last_name . ' ' . $first_name ) ) );
		$user_login    = $this->generate_unused_user_login( $user_login );
		$user_nicename = sanitize_title( $user_login );
		if ( ! empty( $this->custom_email_domains ) ) {
			$domains = $this->custom_email_domains;
			shuffle( $domains ); // Randomize the order of domains.

			$domain     = reset( $domains );
			$domain     = false === strpos( $domain, '.', 1 ) ? $domain . '.' . $faker->tld() : $domain;
			$user_email = $user_login . '@' . $domain;
		} else {
			$user_email = $user_login . '@' . $faker->safeEmailDomain();
		}

		$profile_fields = array(
			// Fields from the users table.
			'user_pass'       => $faker->password,
			'user_nicename'   => $user_nicename,
			'user_email'      => $user_email,
			'user_url'        => $faker->url,
			'display_name'    => $display_name,
			'user_login'      => $user_login,
			'user_registered' => $faker->dateTimeThisDecade()->format( 'Y-m-d H:i:s' ),

			// Other fields from the usermeta table.
			'nickname'    => $user_login,
			'first_name'  => $first_name,
			'last_name'   => $last_name,
			'description' => $faker->realTextBetween( 100, 200, 3 ),
		);

		$contact_methods = wp_get_user_contact_methods();
		foreach ( $contact_methods as $method_key => $method_label ) {
			$profile_fields[ $method_key ] = $user_login . '.' . str_replace( '-', '.', sanitize_title( $method_label ) );
		}

		foreach ( $this->custom_fields as $user_meta => $faker_method ) {
			if ( ! empty( $faker_method ) ) {
				$faker_method = str_replace( array( '(', ')' ), '', $faker_method ); // Avoids duplicate parenthesis issues.
				$fake_data    = $faker->$faker_method();
			} else {
				$fake_data = $faker->realText( 10, 20, 3 );
			}
			
			$profile_fields[ $user_meta ] = $fake_data;
		}

		return $profile_fields;
	}

	/**
	 * Return a fake login name that doesn't exist yet.
	 *
	 * @return string
	 */
	private function generate_unused_user_login( $user_login_to_check = '' ) {
		$faker               = $this->get_faker();
		$new_user_login      = false;
		$sanity_check        = 0;

		while ( ! $new_user_login ) {
			$user_login_to_check = $sanity_check > 5 || empty( $user_login_to_check ) ? $faker->userName() : $user_login_to_check;
			$user                = get_user_by( 'user_login', $user_login_to_check );
			if ( ! $user ) {
				$new_user_login = $user_login_to_check;
			} elseif ( $sanity_check > 0 ) { // it would be crazy to get here, but lets try adding some random numbers.
				$user_login_to_check = $faker->numerify( $user_login_to_check . '#####' );
				$user                = get_user_by( 'user_login', $user_login_to_check );
				if ( ! $user ) {
					$new_user_login = $user_login_to_check;
				}
			}
			$sanity_check ++;
			// it should be impossible to get here.
			if ( $sanity_check > 30 ) {
				WP_CLI::error( 'Unable to find a fake username that was not already in use' );
			}
		}

		return $new_user_login;
	}

	/**
	 * WordPress does not update user names via the wp_update_user function, so we need to do that manually.
	 *
	 * @param int    $user_id WP user id.
	 * @param string $new_login New user login.
	 *
	 * @return void
	 */
	private function update_user_login( $user_id, $new_login ) {
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_login' => $new_login ), array( 'ID' => $user_id ) );
	}

	/**
	 * Process incoming --keep argument into excluded user ids array
	 *
	 * @param string $arg_string The --keep argument value.
	 *
	 * @return integer Number of user ids excluded.
	 */
	private function set_excluded_user_ids( $arg_string ) {
		$excluded_user_ids = array();
		if ( stristr( $arg_string, ',' ) ) {
			$strings = explode( ',', $arg_string );
			foreach ( $strings as $string ) {
				$excluded_user_ids[] = $this->string_to_user( $string );
			}
		} else {
			$excluded_user_ids[] = $this->string_to_user( $arg_string );
		}
		$this->excluded_user_ids = $excluded_user_ids;
		return count( $this->excluded_user_ids );
	}

	/**
	 * Returns a user id from an email, user login, or string id
	 *
	 * @param string $string A single segment of the --keep argument.
	 *
	 * @return integer|boolean User id or false if not found but skipping is ok.
	 */
	private function string_to_user( $string ) {
		if ( is_numeric( $string ) ) {
			return (int) $string;
		}
		if ( stristr( $string, '@' ) ) {
			$user = ( is_multisite() ) ? get_user_by( 'email', $string ) : $this->ms_get_user_by( 'email', $string );
			if ( $user ) {
				return $user->ID;
			} else {
				if ( $this->skip_not_found_users ) {
					WP_CLI::warning( 'user email not found' );
				} else {
					WP_CLI::error( 'user email not found' );
				}
			}
		}
		$user = ( is_multisite() ) ? get_user_by( 'login', $string ) : $this->ms_get_user_by( 'login', $string );
		if ( $user ) {
			return $user->ID;
		}
		if ( $this->skip_not_found_users ) {
			WP_CLI::warning( 'username to keep not found, skipping' );
		} else {
			WP_CLI::error( 'username to keep not found' );
		}

		return false;
	}

	/**
	 * Get a user by field for multisite.
	 *
	 * @param string $field field name.
	 * @param string $string string to search by.
	 */
	protected function ms_get_user_by( $field, $string ) {
		global $wpdb;
		if ( 'login' === $field ) {
			$user_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM $wpdb->users WHERE `user_login` = %s LIMIT 1",
				$string
			) );
		} elseif ( 'email' ) {
			$user_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM $wpdb->users WHERE `user_email` = %s LIMIT 1",
				$string
			) );
		} else {
			WP_CLI::error( 'Unrecognized user search field.' );
		}
		if ( ! empty( $user_id ) ) {
			$user     = new stdClass();
			$user->ID = $user_id;
			return $user;
		}
		return false;
	}

	/**
	 * Get the faker object.
	 *
	 * @return object $faker The faked object.
	 */
	protected function get_faker( $reset_data = false ) {
		$faker = Factory::create( $this->locale );
		
		if ( null !== $this->seed ) {
			$faker->seed( $this->seed );
		}

		return $faker;
	}
}