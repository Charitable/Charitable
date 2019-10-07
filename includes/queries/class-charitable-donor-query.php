<?php
/**
 * The class responsible for querying donors.
 *
 * @package     Charitable/Classes/Charitable_Donor_Query
 * @version     1.0.0
 * @author      Eric Daams
 * @copyright   Copyright (c) 2019, Studio 164a
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Charitable_Donor_Query' ) ) :

	/**
	 * Charitable_Donor_Query
	 *
	 * @since  1.0.0
	 */
	class Charitable_Donor_Query extends Charitable_Query {

		public $table_name = '';

		/**
		 * Create new query object.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args Query arguments.
		 */
		public function __construct( $args = array() ) {
			global $wpdb;

			$this->table_name = "d";

			/**
			 * Filter the default arguments for Charitable_Donor_Query.
			 *
			 * @since 1.0.0
			 *
			 * @param array $args The default arguments.
			 */
			$defaults = apply_filters( 'charitable_donor_query_default_args', array(
				'output'          => 'donors',
				'status'          => array( 'charitable-completed', 'charitable-preapproved' ),
				'orderby'         => 'date',
				'order'           => 'DESC',
				'number'          => 20,
				'paged'           => 1,
				'fields'          => 'all',
				'campaign'        => 0,
				'distinct_donors' => true,
				'donor_id'        => 0,
				'include_erased'  => 1,
				'date_query'      => array(),
				'meta_query'      => array(),
				's'               => null,
			) );

			$this->args             = wp_parse_args( $args, $defaults );
			$this->args['campaign'] = $this->sanitize_campaign();
			$this->position         = 0;

			$this->prepare_query();

			$this->results = $this->get_donors();
		}

		/**
		 * Return list of donor IDs together with the number of donations they have made.
		 *
		 * @since  1.0.0
		 *
		 * @return object[]
		 */
		public function get_donors() {
			$records = $this->query();

			if ( 'donors' != $this->get( 'output' ) ) {
				return $records;
			}

			return array_map( array( $this, 'get_donor_object' ), $records );
		}

		/**
		 * Returns a Charitable_Donor object for a database record.
		 *
		 * @since  1.5.0
		 *
		 * @param  object $record Database record for the donor.
		 * @return Charitable_Donor
		 */
		public function get_donor_object( $record ) {
			$donation_id = isset( $record->donation_id ) ? $record->donation_id : false;
			$donor       = new Charitable_Donor( $record->donor_id, $donation_id );

			/**
			 * Filter the returned object.
			 *
			 * Note that this should always return a Charitable_Donor object.
			 *
			 * @since 1.5.7
			 *
			 * @param Charitable_Donor       $donor  The instance of `Charitable_Donor`.
			 * @param object                 $record Database record for the donor.
			 * @param Charitable_Donor_Query $query  This query object.
			 */
			return apply_filters( 'charitable_donor_query_donor_object', $donor, $record, $this );
		}

		/**
		 * When retrieving the donor count, get distinct donor IDs.
		 *
		 * @since  1.5.4
		 *
		 * @return string
		 */
		public function select_donor_count() {
			return 'COUNT(DISTINCT d.donor_id)';
		}

		/**
		 * Set up fields query argument.
		 *
		 * @since  1.0.0
		 *
		 * @return void
		 */
		public function setup_fields() {
			if ( ! $this->get( 'distinct_donors', true ) ) {
				add_filter( 'charitable_query_fields', array( $this, 'donation_fields' ), 4 );
			}

			$fields = $this->get( 'fields', 'all' );

			if ( 'all' == $fields ) {
				add_filter( 'charitable_query_fields', array( $this, 'donation_calc_fields' ), 5 );
				add_filter( 'charitable_query_fields', array( $this, 'donor_fields' ), 6 );
			}

			if ( is_array( $fields ) ? in_array( 'amount', $fields ) : 'amount' == $fields ) {
				add_filter( 'charitable_query_fields', array( $this, 'donation_amount_sum_field' ), 6 );
			}
		}

		/**
		 * Set up orderby query argument.
		 *
		 * @since  1.0.0
		 *
		 * @return void
		 */
		public function setup_orderby() {
			$orderby = $this->get( 'orderby', false );

			if ( ! $orderby ) {
				return;
			}

			switch ( $orderby ) {
				case 'date':
					add_filter( 'charitable_query_orderby', array( $this, 'orderby_date' ) );
					break;

				case 'donations':
					add_filter( 'charitable_query_orderby', array( $this, 'orderby_count' ) );
					break;

				case 'amount':
					add_filter( 'charitable_query_orderby', array( $this, 'orderby_donation_amount' ) );
					break;

				case 'name':
					add_filter( 'charitable_query_orderby', array( $this, 'orderby_name' ) );
					break;
			}
		}

		/**
		 * Order the results by name.
		 *
		 * @since  1.5.0
		 *
		 * @return string
		 */
		public function orderby_name() {
			return 'ORDER BY d.last_name, d.first_name';
		}

		/**
		 * Set up query grouping.
		 *
		 * @since  1.0.0
		 *
		 * @return void
		 */
		public function setup_grouping() {
			if ( ! $this->get( 'distinct_donors', false ) ) {

				add_filter( 'charitable_query_groupby', array( $this, 'groupby_ID' ) );
				return;
			}

			add_filter( 'charitable_query_groupby', array( $this, 'groupby_donor_id' ) );
		}

		/**
		 * Do not include donors that have had their data erased.
		 *
		 * @since  1.6.0
		 *
		 * @param  string $where The WHERE SQL query part.
		 * @return string
		 */
		public function where_donor_is_not_erased( $where ) {
			if ( ! $this->get( 'include_erased' ) ) {
				$where .= ' AND d.data_erased = "0000-00-00 00:00:00"';
			}

			return $where;
		}

		/**
		 * Remove any hooks that have been attached by the class to prevent contaminating other queries.
		 *
		 * @since  1.0.0
		 *
		 * @return void
		 */
		public function unhook_callbacks() {
			remove_action( 'charitable_pre_query', array( $this, 'setup_fields' ) );
			remove_action( 'charitable_pre_query', array( $this, 'setup_orderby' ) );
			remove_action( 'charitable_pre_query', array( $this, 'setup_grouping' ) );
			remove_filter( 'charitable_select_count_fields', array( $this, 'select_donor_count' ) );
			remove_filter( 'charitable_query_fields', array( $this, 'donation_fields' ), 4 );
			remove_filter( 'charitable_query_fields', array( $this, 'donation_calc_fields' ), 5 );
			remove_filter( 'charitable_query_fields', array( $this, 'donor_fields' ), 6 );
			remove_filter( 'charitable_query_fields', array( $this, 'donation_amount_sum_field' ), 6 );
			remove_filter( 'charitable_query_join', array( $this, 'join_campaign_donations_table_on_donation' ), 5 );
			remove_filter( 'charitable_query_join', array( $this, 'join_donors_table' ), 6 );
			remove_filter( 'charitable_query_join', array( $this, 'join_meta' ), 7 );
			remove_filter( 'charitable_query_where', array( $this, 'where_status_is_in' ), 5 );
			remove_filter( 'charitable_query_where', array( $this, 'where_campaign_is_in' ), 6 );
			remove_filter( 'charitable_query_where', array( $this, 'where_donor_id_is_in' ), 7 );
			remove_filter( 'charitable_query_where', array( $this, 'where_donor_is_not_erased' ), 8 );
			remove_filter( 'charitable_query_where', array( $this, 'where_date' ), 9 );
			remove_filter( 'charitable_query_where', array( $this, 'where_meta' ), 10 );
			remove_filter( 'charitable_query_where', array( $this, 'where_search' ), 11 );
			remove_filter( 'charitable_query_where', array( $this, 'where_email' ), 12 );
			remove_filter( 'charitable_query_where', array( $this, 'where_donor' ), 13 );
			remove_filter( 'charitable_query_groupby', array( $this, 'groupby_donor_id' ) );
			remove_filter( 'charitable_query_orderby', array( $this, 'orderby_date' ) );
			remove_filter( 'charitable_query_orderby', array( $this, 'orderby_count' ) );
			remove_filter( 'charitable_query_orderby', array( $this, 'orderby_donation_amount' ) );
			remove_filter( 'charitable_query_orderby', array( $this, 'orderby_name' ) );
			remove_action( 'charitable_post_query', array( $this, 'unhook_callbacks' ) );
		}

		/**
		 * Set up callbacks for WP_Query filters.
		 *
		 * @since  1.0.0
		 *
		 * @return void
		 */
		protected function prepare_query() {
			add_action( 'charitable_pre_query', array( $this, 'setup_fields' ) );
			add_action( 'charitable_pre_query', array( $this, 'setup_orderby' ) );
			add_action( 'charitable_pre_query', array( $this, 'setup_grouping' ) );
			add_filter( 'charitable_select_count_fields', array( $this, 'select_donor_count' ) );
			add_filter( 'charitable_query_join', array( $this, 'join_campaign_donations_table_on_donation' ), 5 );
			add_filter( 'charitable_query_join', array( $this, 'join_donors_table' ), 6 );
			add_filter( 'charitable_query_join', array( $this, 'join_meta' ), 7 );
			add_filter( 'charitable_query_where', array( $this, 'where_status_is_in' ), 5 );
			add_filter( 'charitable_query_where', array( $this, 'where_campaign_is_in' ), 6 );
			add_filter( 'charitable_query_where', array( $this, 'where_donor_id_is_in' ), 7 );
			add_filter( 'charitable_query_where', array( $this, 'where_donor_is_not_erased' ), 8 );
			add_filter( 'charitable_query_where', array( $this, 'where_date' ), 9 );
			add_filter( 'charitable_query_where', array( $this, 'where_meta' ), 10 );
			add_filter( 'charitable_query_where', array( $this, 'where_search' ), 11 );
			add_filter( 'charitable_query_where', array( $this, 'where_email' ), 12 );
			add_filter( 'charitable_query_where', array( $this, 'where_donor' ), 13 );
			add_action( 'charitable_post_query', array( $this, 'unhook_callbacks' ) );
		}

		/**
		 * Set search where clause.
		 *
		 * @since  1.7.0
		 * @access public
		 *
		 * @param string $where_statement
		 *
		 * @global wpdb $wpdb
		 * @return string
		 */
		public function where_search( $where_statement ) {

			// Donors created for a specific date or in a date range
			if ( ! empty( $this->args['s'] ) && false !== strpos( $this->args['s'], ':' ) ) {
				$search_parts = explode( ':', $this->args['s'] );

				if ( ! empty( $search_parts[0] ) ) {
					switch ( $search_parts[0] ) {
						case 'name':
							$where_statement = " AND {$this->table_name}.first_name LIKE '%{$search_parts[1]}%' AND {$this->table_name}.last_name LIKE '%{$search_parts[1]}%'";
							break;
					}
				}
			}

			return $where_statement;
		}

		/**
		 * Set email where clause.
		 *
		 * @since  1.7.0
		 * @access public
		 *
		 * @param string $where_statement
		 *
		 * @global wpdb $wpdb
		 * @return string
		 */
		public function where_email( $where_statement ) {

			if ( ! empty( $this->args['email'] ) ) {
				global $wpdb;

				if ( is_array( $this->args['email'] ) ) {

					$emails_count       = count( $this->args['email'] );
					$emails_placeholder = array_fill( 0, $emails_count, '%s' );
					$emails             = implode( ', ', $emails_placeholder );

					$where_statement .= $wpdb->prepare( " AND {$this->table_name}.email IN( $emails )", $this->args['email'] );
				} else {
					$where_statement .= $wpdb->prepare( " AND {$this->table_name}.email = %s", $this->args['email'] );
				}
			}

			return $where_statement;
		}

		/**
		 * Set donor where clause.
		 *
		 * @since  1.7.0
		 * @access public
		 *
		 * @param string $where_statement
		 *
		 * @global wpdb $wpdb
		 * @return string
		 */
		public function where_donor( $where_statement ) {

			// Specific donors.
			if ( ! empty( $this->args['donor'] ) ) {
				if ( ! is_array( $this->args['donor'] ) ) {
					$this->args['donor'] = explode( ',', $this->args['donor'] );
				}
				$donor_ids = implode( ',', array_map( 'intval', $this->args['donor'] ) );

				$where_statement .= " AND {$this->table_name}.donor_id IN( {$donor_ids} )";
			}

			return $where_statement;
		}

		/**
		 * Sanitize the campaign argument.
		 *
		 * @since  1.5.0
		 *
		 * @return int
		 */
		protected function sanitize_campaign() {
			switch ( $this->args['campaign'] ) {
				case '':
				case 'all':
					return 0;

				case 'current':
					return charitable_get_current_campaign_id();

				default:
					return $this->args['campaign'];
			}
		}
	}

endif;
