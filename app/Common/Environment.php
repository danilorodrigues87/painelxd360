<?php

namespace App\Common;

class Environment {
	/**
	 * Carrega variáveis de ambiente a partir de $dir/.env
	 * Preenche putenv + $_ENV + $_SERVER (alguns hosts desabilitam putenv).
	 */
	public static function load($dir) {
		$path = rtrim(str_replace('\\', '/', (string)$dir), '/').'/.env';
		if (!is_file($path)) {
			return false;
		}

		$lines = file($path, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			return false;
		}

		foreach ($lines as $line) {
			$line = trim($line);

			if ($line === '' || strpos($line, '#') === 0) {
				continue;
			}

			if (strpos($line, '=') === false) {
				continue;
			}

			list($key, $value) = explode('=', $line, 2);
			$key = trim($key);
			$value = trim($value);

			if ($key === '') {
				continue;
			}

			// Remove aspas simples/duplas opcionais
			$len = strlen($value);
			if ($len >= 2) {
				$q = $value[0];
				if (($q === '"' || $q === "'") && $value[$len - 1] === $q) {
					$value = substr($value, 1, -1);
				}
			}

			putenv($key.'='.$value);
			$_ENV[$key] = $value;
			$_SERVER[$key] = $value;
		}

		return true;
	}

	/**
	 * Obtém uma variável de ambiente com fallbacks.
	 */
	public static function get($key, $default = null) {
		if (array_key_exists($key, $_ENV)) {
			return $_ENV[$key];
		}

		$value = getenv($key);
		if ($value !== false) {
			return $value;
		}

		if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
			return $_SERVER[$key];
		}

		return $default;
	}
}
