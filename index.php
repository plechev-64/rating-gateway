<?php

add_action( 'rcl_payments_gateway_init', 'rcl_add_rating_gateway' );
function rcl_add_rating_gateway() {
	rcl_gateway_register( 'rating', 'Rcl_Rating_Payment' );
}

class Rcl_Rating_Payment extends Rcl_Gateway_Core {
	function __construct() {
		parent::__construct( array(
			'request'	 => 'rrp_payment',
			'name'		 => rcl_get_commerce_option( 'rrp_custom_name', 'Рейтинг' ),
			'submit'	 => __( 'Оплатить рейтингом' ),
			'icon'		 => rcl_addon_url( 'icon.jpg', __FILE__ )
		) );
	}

	function get_options() {

		return array(
			array(
				'type'			 => 'text',
				'slug'			 => 'rrp_custom_name',
				'title'			 => __( 'Наименование способа оплаты' ),
				'placeholder'	 => __( 'Рейтинг' )
			),
			array(
				'type'		 => 'number',
				'slug'		 => 'rrp_course',
				'title'		 => __( 'Курс рейтинга' ),
				'default'	 => 1,
				'notice'	 => __( 'Укажите отношение единицы рейтинга к единице стоимости, '
					. 'оно будет определять сколько будет требоваться единиц рейтинга '
					. 'для оплаты единицы стоимости товара или услуги. Например, '
					. 'если указано 2, то при стоимости 100 рублей будет списываться 200 единиц рейтинга' )
			),
			array(
				'type'	 => 'select',
				'slug'	 => 'rrp_balance',
				'title'	 => __( 'Вывод в форме пополнения баланса' ),
				'values' => array(
					__( 'Отключено' ),
					__( 'Включено' )
				),
				'notice' => __( 'Если включено, то пользователи смогут '
					. 'пополнять свой внутренний баланс на сайте рейтингом по установленному курсу' )
			)
		);
	}

	function get_form( $data ) {

		return parent::construct_form( [
				'action'	 => "https://wallet.advcash.com/sci/",
				'submit'	 => __( 'Оплатить рейтингом' ) . ' (-' . ($data->pay_summ * rcl_get_commerce_option( 'rrp_course', 1 )) . ')',
				'onclick'	 => 'rcl_send_form_data("rrp_send_payment",this);return false;',
				'fields'	 => array(
					'rrp_payment'	 => 1,
					'rrp_amount'	 => $data->pay_summ,
					'rrp_type'		 => $data->pay_type,
					'rrp_id'		 => $data->pay_id,
					'rrp_baggage'	 => $data->baggage_data,
					'rrp_sign'		 => md5( implode( ':', array(
						$data->pay_summ,
						$data->pay_type,
						$data->pay_id,
						$data->user_id,
						rcl_get_option( 'security-key' )
					) ) )
				)
			] );
	}

}

rcl_ajax_action( 'rrp_send_payment' );
function rrp_send_payment() {
	global $user_ID;

	rcl_verify_ajax_nonce();

	if ( ! function_exists( 'rcl_get_user_rating' ) )
		wp_send_json( array(
			'error' => __( 'Не активирован рейтинговая система!' )
		) );

	$rrp_id		 = $_POST['rrp_id'];
	$rrp_amount	 = $_POST['rrp_amount'];
	$rrp_type	 = $_POST['rrp_type'];
	$rrp_baggage = $_POST['rrp_baggage'];
	$rrp_sign	 = $_POST['rrp_sign'];

	$sign = md5( implode( ':', array(
		$rrp_amount,
		$rrp_type,
		$rrp_id,
		$user_ID,
		rcl_get_option( 'security-key' )
		) ) );

	if ( $sign != $rrp_sign ) {
		wp_send_json( array(
			'error' => __( 'Некорректная цифровая подпись!' )
		) );
	}

	$userRating = rcl_get_user_rating( $user_ID );

	$ratingAmount = rcl_get_commerce_option( 'rrp_course', 1 ) * $rrp_amount;

	if ( $ratingAmount > $userRating ) {
		wp_send_json( array(
			'error' => __( 'Недостаточно рейтинга для оплаты!' )
		) );
	}

	$args = array(
		'user_id'		 => $user_ID,
		'object_id'		 => $user_ID,
		'object_author'	 => $user_ID,
		'rating_value'	 => $ratingAmount,
		'rating_status'	 => 'minus',
		'user_overall'	 => 1,
		'rating_type'	 => 'rating-payment'
	);

	rcl_insert_rating( $args );

	$data = array(
		'pay_id'		 => $rrp_id,
		'pay_summ'		 => $rrp_amount,
		'pay_type'		 => $rrp_type,
		'baggage_data'	 => $rrp_baggage ? json_decode( base64_decode( $rrp_baggage ) ) : false,
		'user_id'		 => $user_ID
	);

	$data = ( object ) $data;

	do_action( 'rcl_success_pay_system', $data );

	wp_send_json( array(
		'success'	 => __( 'Успешная оплата!' ),
		'redirect'	 => get_permalink( rcl_get_commerce_option( 'page_successfully_pay' ) )
	) );
}

add_filter( 'rcl_list_votes', 'rrp_edit_history_comments', 10, 2 );
function rrp_edit_history_comments( $row, $data ) {

	if ( $data->rating_type != 'rating-payment' )
		return $row;

	$row = mysql2date( 'd.m.Y', $data->rating_date ) . ' ' . __( 'списано в счет оплаты' ) . ': ' . rcl_format_rating( $data->rating_value );

	return $row;
}

add_filter( 'rcl_user_balance_form_args', 'rrp_edit_user_balance_form_args', 10 );
function rrp_edit_user_balance_form_args( $args ) {

	if ( rcl_get_commerce_option( 'rrp_balance' ) )
		return $args;

	if ( ! is_array( $args ) )
		$args = array();

	if ( isset( $args['ids__not_in'] ) ) {
		if ( is_array( $args['ids__not_in'] ) ) {
			$args['ids__not_in'][] = 'rating';
		} else {
			$args['ids__not_in'] .= ',rating';
		}
	} else {
		$args['ids__not_in'][] = 'rating';
	}

	return $args;
}
