<?php

namespace App\Common\Helpers;

/**
 * Idade mínima 12 anos; menores de 18 exigem responsável no cadastro.
 */
class ConectIdadeHelper {

	public const IDADE_MINIMA = 12;
	public const IDADE_MENOR = 18;
	public const IDADE_MAX = 100;

	public static function normalizarNascimento(?string $raw): ?string {
		$raw = trim((string)$raw);
		if ($raw === '') {
			return null;
		}
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
			return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
		}
		$d = \DateTime::createFromFormat('d/m/Y', $raw);
		if ($d instanceof \DateTime) {
			return $d->format('Y-m-d');
		}
		return null;
	}

	public static function calcularIdade(?string $nascimento): ?int {
		$nasc = self::normalizarNascimento($nascimento);
		if ($nasc === null) {
			return null;
		}
		try {
			$dn = new \DateTime($nasc);
			$hoje = new \DateTime('today');
			if ($dn > $hoje) {
				return null;
			}
			return (int)$dn->diff($hoje)->y;
		} catch (\Throwable $e) {
			return null;
		}
	}

	public static function isMenor(?int $idade): bool {
		return $idade !== null && $idade < self::IDADE_MENOR;
	}

	/**
	 * @return array{ok:bool,idade:?int,erro?:string}
	 */
	public static function validarElegibilidade(?string $nascimento): array {
		$nasc = self::normalizarNascimento($nascimento);
		if ($nasc === null) {
			return ['ok' => false, 'idade' => null, 'erro' => 'Informe uma data de nascimento válida.'];
		}
		$idade = self::calcularIdade($nasc);
		if ($idade === null) {
			return ['ok' => false, 'idade' => null, 'erro' => 'Data de nascimento inválida.'];
		}
		if ($idade < self::IDADE_MINIMA) {
			return [
				'ok' => false,
				'idade' => $idade,
				'erro' => 'É necessário ter pelo menos '.self::IDADE_MINIMA.' anos para participar do Conecta Jovem.',
			];
		}
		if ($idade > self::IDADE_MAX) {
			return ['ok' => false, 'idade' => $idade, 'erro' => 'Data de nascimento inválida.'];
		}
		return ['ok' => true, 'idade' => $idade];
	}

	/**
	 * Extrai e valida nascimento + responsável a partir do POST.
	 *
	 * @return array{ok:bool,erro?:string,dados?:array{nascimento:string,responsavel_nome?:string,responsavel_consentimento_em?:string},idade?:int}
	 */
	public static function extrairValidarNascimento(array $post, bool $obrigatorio = true): array {
		$raw = trim((string)($post['nascimento'] ?? $post['dataNascimento'] ?? $post['data_nascimento'] ?? ''));
		if ($raw === '') {
			if (!$obrigatorio) {
				return ['ok' => true, 'dados' => []];
			}
			return ['ok' => false, 'erro' => 'Informe sua data de nascimento.'];
		}

		$val = self::validarElegibilidade($raw);
		if (!$val['ok']) {
			return ['ok' => false, 'erro' => $val['erro'] ?? 'Data inválida.'];
		}

		$nasc = self::normalizarNascimento($raw);
		$idade = (int)$val['idade'];
		$dados = ['nascimento' => (string)$nasc];

		if (self::isMenor($idade)) {
			$resp = trim((string)($post['responsavelNome'] ?? $post['responsavel_nome'] ?? ''));
			if ($resp === '' || mb_strlen($resp) < 3) {
				return [
					'ok' => false,
					'erro' => 'Para menores de 18 anos, informe o nome completo do responsável legal.',
				];
			}
			$consent = !empty($post['responsavelConsentimento'])
				|| !empty($post['responsavel_consentimento'])
				|| !empty($post['consentimentoResponsavel']);
			if (!$consent) {
				return [
					'ok' => false,
					'erro' => 'É necessário confirmar o consentimento do responsável legal para menores de 18 anos.',
				];
			}
			$dados['responsavel_nome'] = mb_substr($resp, 0, 191);
			$dados['responsavel_consentimento_em'] = date('Y-m-d H:i:s');
		} else {
			$dados['responsavel_nome'] = null;
			$dados['responsavel_consentimento_em'] = null;
		}

		return ['ok' => true, 'dados' => $dados, 'idade' => $idade];
	}

	/** Campos de idade para API (sem expor nascimento/responsável). */
	public static function payloadPublico(?string $nascimento): array {
		$idade = self::calcularIdade($nascimento);
		return [
			'idade'   => $idade,
			'isMenor' => self::isMenor($idade),
		];
	}
}
