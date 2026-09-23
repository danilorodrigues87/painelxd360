<?php

namespace App\Common\Helpers;

class ConectRedesSociaisHelper {

	private const CAMPOS = ['linkedin', 'instagram', 'github', 'portfolio', 'facebook', 'tiktok'];

	/** @return array<string,string> */
	public static function vazio(): array {
		$out = [];
		foreach (self::CAMPOS as $c) {
			$out[$c] = '';
		}
		return $out;
	}

	/** @return array<string,string> */
	public static function decode(?string $json): array {
		$base = self::vazio();
		if ($json === null || trim($json) === '') {
			return $base;
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return $base;
		}
		foreach (self::CAMPOS as $c) {
			if (!empty($decoded[$c]) && is_string($decoded[$c])) {
				$base[$c] = self::normalizarUrl($decoded[$c]);
			}
		}
		return $base;
	}

	/**
	 * @param mixed $input
	 * @return array<string,string>
	 */
	public static function sanitizar($input): array {
		$base = self::vazio();
		if (!is_array($input)) {
			return $base;
		}
		foreach (self::CAMPOS as $c) {
			if (!array_key_exists($c, $input)) {
				continue;
			}
			$url = self::normalizarUrl((string)$input[$c]);
			if ($url !== '') {
				$base[$c] = $url;
			}
		}
		return $base;
	}

	/** @param array<string,string> $dados */
	public static function encode(array $dados): ?string {
		$tem = false;
		foreach ($dados as $v) {
			if ($v !== '') {
				$tem = true;
				break;
			}
		}
		if (!$tem) {
			return null;
		}
		return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function normalizarUrl(string $url): string {
		$url = trim(strip_tags($url));
		if ($url === '') {
			return '';
		}
		if (!preg_match('#^https?://#i', $url)) {
			$url = 'https://'.$url;
		}
		if (mb_strlen($url) > 500) {
			$url = mb_substr($url, 0, 500);
		}
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return '';
		}
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (!in_array(strtolower((string)$scheme), ['http', 'https'], true)) {
			return '';
		}
		return $url;
	}
}
