<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Dollypack_Custom_Ability' ) ) {
	abstract class Dollypack_Custom_Ability extends Dollypack_Ability {

		/**
		 * Group generated abilities together in admin contexts.
		 *
		 * @var string
		 */
		protected $group_label = 'Custom';
	}
}
