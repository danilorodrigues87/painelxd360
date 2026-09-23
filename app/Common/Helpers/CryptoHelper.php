<?php

namespace App\Common\Helpers;

use App\Common\Environment;

class CryptoHelper {

	/**
	 * Chave AES-256: APP_KEY (preferencial) ou SYSTEM_TOKEN.
	 * Sem chave configurada → null (não usa fallback previsível).
	 */
	private static function key(): ?string {
		$key = Environment::get('APP_KEY');
		if ($key === null || $key === '') {
			$key = Environment::get('SYSTEM_TOKEN');
		}
		$key = is_string($key) ? trim($key) : '';
		if ($key === '') {
			error_log('[CryptoHelper] APP_KEY/SYSTEM_TOKEN ausentes — criptografia indisponível.');
			return null;
		}
		return hash('sha256', $key, true);
	}

	public static function encrypt(?string $plain): ?string {
		if ($plain === null || $plain === '') {
			return null;
		}

		$material = self::key();
		if ($material === null) {
			return null;
		}

		$iv = random_bytes(16);
		$cipher = openssl_encrypt($plain, 'AES-256-CBC', $material, OPENSSL_RAW_DATA, $iv);
		if ($cipher === false) {
			return null;
		}

		return base64_encode($iv.$cipher);
	}

	public static function decrypt(?string $encoded): ?string {
		if ($encoded === null || $encoded === '') {
			return null;
		}

		$material = self::key();
		if ($material === null) {
			return null;
		}

		$raw = base64_decode($encoded, true);
		if ($raw === false || strlen($raw) < 17) {
			return null;
		}

		$iv = substr($raw, 0, 16);
		$cipher = substr($raw, 16);
		$plain = openssl_decrypt($cipher, 'AES-256-CBC', $material, OPENSSL_RAW_DATA, $iv);

		return $plain === false ? null : $plain;
	}
}
