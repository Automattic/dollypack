<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Dollypack_Package_Helper' ) ) {
	class Dollypack_Package_Helper {

		/**
		 * Return the GitHub releases page used for manual installs.
		 *
		 * @return string
		 */
		public static function get_release_install_url() {
			return 'https://github.com/Automattic/dollypack/releases';
		}

		/**
		 * Queue a standard WordPress admin notice.
		 *
		 * @param string $message Notice message. HTML allowed.
		 * @param string $type    Notice type.
		 * @return void
		 */
		public static function add_admin_notice( $message, $type = 'warning' ) {
			add_action(
				'admin_notices',
				static function () use ( $message, $type ) {
					printf(
						'<div class="notice notice-%1$s"><p>%2$s</p></div>',
						esc_attr( $type ),
						wp_kses_post( $message )
					);
				}
			);
		}

		/**
		 * Abort plugin boot when conflicting Dollypack packages are active.
		 *
		 * @param string   $plugin_name    Human-readable plugin name.
		 * @param string   $current_plugin Current plugin basename.
		 * @param string[] $conflicts      Conflicting plugin basenames.
		 * @return bool
		 */
		public static function abort_if_conflicting_plugins_active( $plugin_name, $current_plugin, $conflicts ) {
			$active_conflicts = self::get_active_plugin_labels( $conflicts, $current_plugin );

			if ( empty( $active_conflicts ) ) {
				return false;
			}

			self::add_admin_notice(
				sprintf(
					'%1$s cannot run while %2$s %3$s active. Deactivate the overlapping Dollypack package%4$s first.',
					esc_html( $plugin_name ),
					esc_html( self::implode_human_list( $active_conflicts ) ),
					1 === count( $active_conflicts ) ? 'is' : 'are',
					1 === count( $active_conflicts ) ? '' : 's'
				)
			);

			return true;
		}

		/**
		 * Ensure the shared core runtime is available for an add-on.
		 *
		 * @param string $plugin_name Human-readable plugin name.
		 * @return bool
		 */
		public static function ensure_core_runtime( $plugin_name ) {
			if ( class_exists( 'Dollypack_Runtime', false ) ) {
				return true;
			}

			self::add_admin_notice(
				sprintf(
					'%1$s requires <strong>Dollypack Core</strong>. Install it from <a href="%2$s" target="_blank" rel="noopener noreferrer">GitHub Releases</a>.',
					esc_html( $plugin_name ),
					esc_url( self::get_release_install_url() )
				)
			);

			return false;
		}

		/**
		 * Return active plugin basenames for the current site and network.
		 *
		 * @return string[]
		 */
		private static function get_active_plugins() {
			$plugins = (array) get_option( 'active_plugins', array() );

			if ( is_multisite() ) {
				$plugins = array_merge(
					$plugins,
					array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
				);
			}

			return array_values( array_unique( $plugins ) );
		}

		/**
		 * Return labels for conflicting plugins that are currently active.
		 *
		 * @param string[] $conflicts      Conflicting plugin basenames.
		 * @param string   $current_plugin Current plugin basename.
		 * @return string[]
		 */
		private static function get_active_plugin_labels( $conflicts, $current_plugin ) {
			$active_plugins = self::get_active_plugins();
			$labels         = array();

			foreach ( $conflicts as $plugin_file ) {
				if ( $plugin_file === $current_plugin || ! in_array( $plugin_file, $active_plugins, true ) ) {
					continue;
				}

				$labels[] = self::get_plugin_label( $plugin_file );
			}

			return array_values( array_unique( $labels ) );
		}

		/**
		 * Return a human-readable label for a known plugin basename.
		 *
		 * @param string $plugin_file Plugin basename.
		 * @return string
		 */
		private static function get_plugin_label( $plugin_file ) {
			$labels = array(
				'dollypack/dollypack.php'                 => 'Dollypack Full',
				'dollypack-full/dollypack-full.php'       => 'Dollypack Full',
				'dollypack-core/dollypack-core.php'       => 'Dollypack Core',
				'dollypack-github/dollypack-github.php'   => 'Dollypack GitHub',
				'dollypack-google/dollypack-google.php'   => 'Dollypack Google',
			);

			return $labels[ $plugin_file ] ?? $plugin_file;
		}

		/**
		 * Join a list into human-readable prose.
		 *
		 * @param string[] $items Items to join.
		 * @return string
		 */
		private static function implode_human_list( $items ) {
			$count = count( $items );

			if ( 0 === $count ) {
				return '';
			}

			if ( 1 === $count ) {
				return $items[0];
			}

			if ( 2 === $count ) {
				return $items[0] . ' and ' . $items[1];
			}

			$last = array_pop( $items );

			return implode( ', ', $items ) . ', and ' . $last;
		}
	}
}
