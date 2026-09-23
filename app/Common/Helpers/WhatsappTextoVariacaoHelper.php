<?php

namespace App\Common\Helpers;

use App\Model\Entity\EscolaIntegracoes;

/**
 * Variação leve de texto de campanha WhatsApp (anti-template).
 * Usa credencial compartilhada de Configurações de IA; fallback local se IA falhar.
 */
class WhatsappTextoVariacaoHelper {

	public static function escolaQuerVariar(int $idAdmin): bool {
		if (!EscolaIntegracoes::temColunaWhatsappVariarTexto()) {
			return false;
		}
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		return $cfg instanceof EscolaIntegracoes && (int)($cfg->whatsapp_variar_texto ?? 0) === 1;
	}

	/**
	 * @return string texto final (nunca vazio se $original não for)
	 */
	public static function variar(int $idAdmin, string $original): string {
		$original = trim($original);
		if ($original === '') {
			return $original;
		}

		$viaIa = self::variarComIa($idAdmin, $original);
		if ($viaIa !== null && $viaIa !== '') {
			return $viaIa;
		}

		return self::variarLocal($original);
	}

	private static function variarComIa(int $idAdmin, string $original): ?string {
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return null;
		}
		$key = $cfg->getAiApiKeyDescriptografada();
		if ($key === null || $key === '') {
			return null;
		}

		$system = 'Você reescreve mensagens de WhatsApp para marketing escolar. '
			.'Mantenha o mesmo sentido, ofertas, preços, links e variáveis entre chaves como {nome}. '
			.'Altere apenas conectivos, ordem de frases curtas ou sinônimos. '
			.'Não invente promoções. Não adicione hashtags. Responda SÓ com o texto final, sem aspas.';

		$out = LmsAiService::chatComCredencial(
			$idAdmin,
			[['role' => 'user', 'content' => $original]],
			$system,
			'whatsapp_variacao'
		);
		if ($out === null) {
			return null;
		}
		$out = trim($out);
		if ($out === '' || mb_strlen($out) < 5) {
			return null;
		}
		// Evita respostas stub do serviço pedagógico
		if (stripos($out, 'Resposta simulada') !== false || stripos($out, 'Configure a IA') !== false) {
			return null;
		}
		return $out;
	}

	/** Micro-variação determinística sem IA. */
	private static function variarLocal(string $original): string {
		$t = $original;
		$saudacoes = [
			'Olá' => ['Oi', 'Olá', 'Ei'],
			'Oi' => ['Olá', 'Oi', 'Oiê'],
			'Bom dia' => ['Bom dia', 'Bom dia!', 'Ótimo dia'],
			'Boa tarde' => ['Boa tarde', 'Boa tarde!', 'Ótima tarde'],
			'Boa noite' => ['Boa noite', 'Boa noite!', 'Ótima noite'],
		];
		foreach ($saudacoes as $de => $ops) {
			if (preg_match('/^'.preg_quote($de, '/').'\b/ui', $t)) {
				$pick = $ops[random_int(0, count($ops) - 1)];
				$t = preg_replace('/^'.preg_quote($de, '/').'\b/ui', $pick, $t, 1);
				break;
			}
		}

		// Pontuação final leve
		if (preg_match('/[.!?]$/u', $t)) {
			$ends = ['.', '!', '.'];
			$t = preg_replace('/[.!?]+$/u', $ends[random_int(0, 2)], $t);
		} elseif (random_int(0, 1) === 1) {
			$t .= '!';
		}

		// Embaralha blocos separados por linha em branco (máx. 3)
		$blocos = preg_split("/\n\s*\n/u", $t);
		if (is_array($blocos) && count($blocos) >= 2 && count($blocos) <= 3 && random_int(0, 1) === 1) {
			$primeiro = array_shift($blocos);
			shuffle($blocos);
			array_unshift($blocos, $primeiro);
			$t = implode("\n\n", $blocos);
		}

		return $t;
	}
}
