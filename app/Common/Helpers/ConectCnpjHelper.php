<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;

class ConectCnpjHelper {

	public static function normalizar(string $cnpj): string {
		return preg_replace('/\D+/', '', $cnpj) ?: '';
	}

	public static function validar(string $cnpj): bool {
		$cnpj = self::normalizar($cnpj);
		if (strlen($cnpj) !== 14) {
			return false;
		}
		if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
			return false;
		}

		$peso1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
		$soma = 0;
		for ($i = 0; $i < 12; $i++) {
			$soma += (int)$cnpj[$i] * $peso1[$i];
		}
		$resto = $soma % 11;
		$d1 = $resto < 2 ? 0 : 11 - $resto;
		if ((int)$cnpj[12] !== $d1) {
			return false;
		}

		$peso2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
		$soma = 0;
		for ($i = 0; $i < 13; $i++) {
			$soma += (int)$cnpj[$i] * $peso2[$i];
		}
		$resto = $soma % 11;
		$d2 = $resto < 2 ? 0 : 11 - $resto;

		return (int)$cnpj[13] === $d2;
	}
}
