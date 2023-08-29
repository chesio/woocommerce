<?php

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableQuery;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Class OrdersTableQueryTests.
 */
class OrdersTableQueryTests extends WC_Unit_Test_Case {
	use HPOSToggleTrait;

	/**
	 * Stores the original COT state.
	 *
	 * @var bool
	 */
	private $cot_state;

	/**
	 * Setup - enable COT.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->setup_cot();
		$this->cot_state = OrderUtil::custom_orders_table_usage_is_enabled();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Restore the original COT state.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( $this->cot_state );
		parent::tearDown();
	}

	/**
	 * Helper function to create different orders with different dates for testing.
	 *
	 * @return array Array of WC_Order objects.
	 */
	private function create_orders_with_different_dates() {
		$order1 = OrderHelper::create_order();
		$order2 = OrderHelper::create_order();
		$order3 = OrderHelper::create_order();

		$order1->set_date_created( '2000-01-01T10:00:00' );
		$order1->set_date_modified( '2001-02-01T10:00:00' );
		$order1->set_date_paid( '2002-03-01T10:00:00' );
		$order1->save();

		$order2->set_date_created( '2000-02-01T10:00:00' );
		$order2->set_date_modified( '2001-01-01T10:00:00' );
		$order2->set_date_paid( '2002-03-01T10:00:00' );
		$order2->save();

		$order3->set_date_created( '2001-01-01T10:00:00' );
		$order3->set_date_modified( '2001-02-01T10:00:00' );
		$order3->set_date_paid( '2002-03-01T10:00:00' );
		$order3->save();

		return array( $order1, $order2, $order3 );
	}

	/**
	 * @testDox Nested date queries works as expected.
	 */
	public function test_nested_date_queries_single() {
		$orders = $this->create_orders_with_different_dates();

		$date_query_created_in_2000 = array(
			array(
				'relation' => 'AND',
				array(
					'column'    => 'date_created',
					'inclusive' => true,
					'after'     => '2000-01-01T00:00:00',
				),
				array(
					'column'    => 'date_created',
					'inclusive' => false,
					'before'    => '2001-01-01T10:00:00',
				),
			),
		);

		$queried_orders = wc_get_orders(
			array(
				'return'     => 'ids',
				'date_query' => $date_query_created_in_2000,
			)
		);

		$this->assertEquals( 2, count( $queried_orders ) );
		$this->assertContains( $orders[0]->get_id(), $queried_orders );
		$this->assertContains( $orders[1]->get_id(), $queried_orders );
	}

	/**
	 * @testDox Multiple nested date queries works as expected.
	 */
	public function test_nested_date_queries_multi() {
		$orders = $this->create_orders_with_different_dates();

		$date_query_created_in_2000_and_modified_in_2001 = array(
			array(
				'relation' => 'AND',
				array(
					'column'    => 'date_created',
					'inclusive' => true,
					'after'     => '2000-01-01T00:00:00',
				),
				array(
					'column'    => 'post_date',
					'inclusive' => false,
					'before'    => '2001-01-01T10:00:00',
				),
			),
			array(
				'column' => 'date_modified',
				'before' => '2001-01-02T10:00:00',
			),
		);

		$queried_orders = wc_get_orders(
			array(
				'return'     => 'ids',
				'date_query' => $date_query_created_in_2000_and_modified_in_2001,
			)
		);

		$this->assertEquals( 1, count( $queried_orders ) );
		$this->assertContains( $orders[1]->get_id(), $queried_orders );
	}

	/**
	 * @testDox 'suppress_filters' arg is honored in queries.
	 */
	public function test_query_suppress_filters() {
		$hooks = array(
			'woocommerce_orders_table_query_clauses',
			'woocommerce_orders_table_query_sql',
		);

		$filters_called  = 0;
		$filter_callback = function ( $arg ) use ( &$filters_called ) {
			$filters_called++;
			return $arg;
		};

		foreach ( $hooks as $hook ) {
			add_filter( $hook, $filter_callback );
		}

		// Check that suppress_filters = false is honored.
		foreach ( $hooks as $hook ) {
			wc_get_orders( array() );
		}

		$this->assertNotEquals( $filters_called, 0 );

		// Check that suppress_filters = true is honored.
		$filters_called = 0;
		foreach ( $hooks as $hook ) {
			wc_get_orders(
				array(
					'suppress_filters' => true,
				)
			);
		}
		$this->assertEquals( $filters_called, 0 );

		foreach ( $hooks as $hook ) {
			remove_all_filters( $hook );
		}
	}

	/**
	 * @testdox Query filters successfully allow modificatio of order queries.
	 */
	public function test_query_filters() {
		$order1 = new \WC_Order();
		$order1->set_date_created( time() - HOUR_IN_SECONDS );
		$order1->save();

		$order2 = new \WC_Order();
		$order2->save();

		$this->assertCount( 2, wc_get_orders( array() ) );

		// Force a query that returns nothing.
		$filter_callback = function( $clauses ) {
			$clauses['where'] .= ' AND 1=0 ';
			return $clauses;
		};

		add_filter( 'woocommerce_orders_table_query_clauses', $filter_callback );
		$this->assertCount( 0, wc_get_orders( array() ) );
		remove_all_filters( 'woocommerce_orders_table_query_clauses' );

		// Force a query that sorts orders by id ASC (as opposed to the default date DESC) if a query arg is present.
		$filter_callback = function( $clauses, $query, $query_args ) {
			if ( ! empty( $query_args['my_custom_arg'] ) ) {
				$clauses['orderby'] = $query->get_table_name( 'orders' ) . '.id ASC';
			}

			return $clauses;
		};

		add_filter( 'woocommerce_orders_table_query_clauses', $filter_callback, 10, 3 );
		$this->assertEquals(
			wc_get_orders(
				array(
					'return'        => 'ids',
					'my_custom_arg' => true,
				)
			),
			array(
				$order1->get_id(),
				$order2->get_id(),
			)
		);
		$this->assertEquals(
			wc_get_orders(
				array(
					'return' => 'ids',
				)
			),
			array(
				$order2->get_id(),
				$order1->get_id(),
			)
		);
		remove_all_filters( 'woocommerce_orders_table_query_clauses' );
	}

	/**
	 * @testdox The pre-query escape hook allows replacing the order query.
	 */
	public function test_pre_query_escape_hook() {
		$order1 = new \WC_Order();
		$order1->set_date_created( time() - HOUR_IN_SECONDS );
		$order1->save();

		$order2 = new \WC_Order();
		$order2->save();

		$query = new OrdersTableQuery( array() );
		$this->assertCount( 2, $query->orders );
		$this->assertEquals( 2, $query->found_orders );
		$this->assertEquals( 0, $query->max_num_pages );

		$callback = function( $query ) use ( $order1 ) {
			// Only return one of the orders to show that we are replacing the query result.
			$order_ids = array( $order1->get_id() );
			// These are made up to show that we are actually replacing the values. 
			$found_orders = 17;
			$max_num_pages = 23;
			return [ $order_ids, $found_orders, $max_num_pages ];
		};
		add_filter( 'woocommerce_hpos_pre_query', $callback, 10, 3 );

		$query = new OrdersTableQuery( array() );
		$this->assertCount( 1, $query->orders );
		$this->assertEquals( 17, $query->found_orders );
		$this->assertEquals( 23, $query->max_num_pages );
		$this->assertEquals( $order1->get_id(), $query->orders[0] );

		$orders = wc_get_orders( array() );
		$this->assertCount( 1, $orders );
		$this->assertEquals( $order1->get_id(), $orders[0]->get_id() );

		remove_all_filters( 'woocommerce_hpos_pre_query' );
	}

}
