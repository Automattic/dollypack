<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dollypack_Crypto {

	const PREFIX = 'dollypack_enc:v1:';
	const OPENSSL_CIPHER = 'aes-256-gcm';
	const OPENSSL_TAG_LENGTH = 16;

	/**
	 * Check whether a stored value is in Dollypack's encrypted format.
	 */
	public static function is_encrypted_string( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Encrypt a string for storage.
	 * Falls back to plaintext if no supported crypto backend is available.
	 */
	public static function encrypt( $value ) {
		if ( ! is_string( $value ) || '' === $value || self::is_encrypted_string( $value ) ) {
			return $value;
		}

		$key = self::get_encryption_key();

		if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
			return self::encrypt_with_sodium( $value, $key );
		}

		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_cipher_iv_length' ) && function_exists( 'random_bytes' ) ) {
			return self::encrypt_with_openssl( $value, $key );
		}

		return $value;
	}

	/**
	 * Decrypt a stored string.
	 * Returns plaintext unchanged for legacy unencrypted values.
	 */
	public static function decrypt( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! self::is_encrypted_string( $value ) ) {
			return $value;
		}

		$parts = explode( ':', $value, 4 );
		if ( 4 !== count( $parts ) ) {
			return '';
		}

		list( , , $engine, $payload ) = $parts;
		$key = self::get_encryption_key();

		if ( 'sodium' === $engine ) {
			return self::decrypt_with_sodium( $payload, $key );
		}

		if ( 'openssl' === $engine ) {
			return self::decrypt_with_openssl( $payload, $key );
		}

		return '';
	}

	/**
	 * Build a stable site-specific encryption key from WordPress salts.
	 */
	private static function get_encryption_key() {
		return hash( 'sha256', 'dollypack|' . wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypt with libsodium secretbox.
	 */
	private static function encrypt_with_sodium( $value, $key ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $value, $nonce, $key );

		return self::PREFIX . 'sodium:' . base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt with libsodium secretbox.
	 */
	private static function decrypt_with_sodium( $payload, $key ) {
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Encrypt with OpenSSL AES-256-GCM.
	 */
	private static function encrypt_with_openssl( $value, $key ) {
		$iv_length = openssl_cipher_iv_length( self::OPENSSL_CIPHER );
		if ( false === $iv_length ) {
			return $value;
		}

		$iv  = random_bytes( $iv_length );
		$tag = '';
		$ciphertext = openssl_encrypt(
			$value,
			self::OPENSSL_CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::OPENSSL_TAG_LENGTH
		);

		if ( false === $ciphertext ) {
			return $value;
		}

		return self::PREFIX . 'openssl:' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt with OpenSSL AES-256-GCM.
	 */
	private static function decrypt_with_openssl( $payload, $key ) {
		$decoded   = base64_decode( $payload, true );
		$iv_length = openssl_cipher_iv_length( self::OPENSSL_CIPHER );

		if ( false === $decoded || false === $iv_length || strlen( $decoded ) <= ( $iv_length + self::OPENSSL_TAG_LENGTH ) ) {
			return '';
		}

		$iv         = substr( $decoded, 0, $iv_length );
		$tag        = substr( $decoded, $iv_length, self::OPENSSL_TAG_LENGTH );
		$ciphertext = substr( $decoded, $iv_length + self::OPENSSL_TAG_LENGTH );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			self::OPENSSL_CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plaintext ? '' : $plaintext;
	}
}
