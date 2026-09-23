<?php

namespace App\Common\Helpers;

class EmailValidator {

	/** Partes locais (antes do @) sempre inválidas para envio */
	private static $locaisBloqueados = [
		'sem',
		'sememail',
		'sem-email',
		'sem_email',
		'naotem',
		'nao-tem',
		'naotememail',
		'naopossui',
		'nao_possui',
		'nenhum',
		'nenhuma',
		'fake',
		'falso',
		'teste',
		'test',
		'xxx',
		'xxxx',
		'abc',
		'abcd',
		'email',
		'mail',
		'correio',
		'indefinido',
		'invalido',
		'inválido',
		'naopossuiemail',
		'naotememail',
	];

	/** Domínios placeholder comuns */
	private static $dominiosBloqueados = [
		'email.com',
		'email.br',
		'email.net',
		'email.org',
		'sememail.com',
		'sememail.com.br',
		'naotem.com',
		'naotem.com.br',
		'teste.com',
		'teste.com.br',
		'test.com',
		'fake.com',
		'exemplo.com',
		'example.com',
		'dominio.com',
		'emailfake.com',
		'naopossui.com',
	];

	public static function normalizar(?string $email): string {
		return strtolower(trim((string)$email));
	}

	public static function isValido(?string $email): bool {
		return self::getRejeicao($email) === null;
	}

	/** Vazio é aceito; se preenchido, deve passar em isValido() */
	public static function isValidoOuVazio(?string $email): bool {
		$email = self::normalizar($email);
		if ($email === '') {
			return true;
		}
		return self::isValido($email);
	}

	/** Retorna motivo só se o e-mail estiver preenchido e inválido */
	public static function getRejeicaoSePreenchido(?string $email): ?string {
		$email = self::normalizar($email);
		if ($email === '') {
			return null;
		}
		return self::getRejeicao($email);
	}

	public static function mensagemErro(?string $email, bool $obrigatorio = false): ?string {
		$email = self::normalizar($email);
		if ($email === '') {
			return $obrigatorio ? 'Informe um e-mail válido.' : null;
		}
		$motivo = self::getRejeicao($email);
		return $motivo !== null ? 'E-mail inválido: '.$motivo.'.' : null;
	}

	public static function getRejeicao(?string $email): ?string {
		$email = self::normalizar($email);

		if ($email === '') {
			return 'E-mail vazio';
		}

		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return 'Formato inválido';
		}

		$partes = explode('@', $email, 2);
		if (count($partes) !== 2) {
			return 'Formato inválido';
		}

		[$local, $dominio] = $partes;
		$localLimpo = preg_replace('/[^a-z0-9]/', '', $local);

		if (strlen($localLimpo) < 2) {
			return 'Endereço incompleto';
		}

		if (in_array($local, self::$locaisBloqueados, true) || in_array($localLimpo, self::$locaisBloqueados, true)) {
			return 'E-mail placeholder (sem endereço real)';
		}

		if (in_array($dominio, self::$dominiosBloqueados, true)) {
			return 'Domínio genérico/placeholder';
		}

		foreach (['sememail', 'naotem', 'naopossui', 'nenhumemail', 'sememail'] as $trecho) {
			if (strpos($localLimpo, $trecho) !== false) {
				return 'E-mail indica ausência de conta ('.$trecho.')';
			}
		}

		if (preg_match('/^(sem|nao|teste|fake|xxx|mail|email)[0-9]*$/', $localLimpo)) {
			return 'Padrão de e-mail fictício';
		}

		if (preg_match('/@(email|sememail|naotem|teste|fake)(\.|$)/', $email)) {
			return 'Domínio fictício';
		}

		// Repetição suspeita: aaaaa@..., 11111@...
		if (preg_match('/^(.)\1{4,}@/', $email)) {
			return 'Endereço suspeito (repetição)';
		}

		return null;
	}

	public static function filtrarLista(array $emails): array {
		$validos = [];
		foreach ($emails as $email) {
			$norm = self::normalizar($email);
			if ($norm !== '' && self::isValido($norm) && !in_array($norm, $validos, true)) {
				$validos[] = $norm;
			}
		}
		return $validos;
	}
}
