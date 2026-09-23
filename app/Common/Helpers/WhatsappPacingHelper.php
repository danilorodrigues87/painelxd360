<?php

namespace App\Common\Helpers;

/**
 * Pacing WhatsApp: jitter ±20% e pisos seguros para campanhas.
 */
class WhatsappPacingHelper {

	/** Default intervalo entre grupos (10 min). */
	public const DEFAULT_DELAY_GRUPO = 600;

	/** Piso duro entre mensagens 1:1 em campanhas (segundos). */
	public const FLOOR_DELAY_1A1 = 30;

	/** Default recomendado intervalo 1:1. */
	public const DEFAULT_DELAY_1A1 = 60;

	/** Default máximo por hora. */
	public const DEFAULT_MAX_HORA = 20;

	/**
	 * Aplica jitter ±20% em torno da base (mínimo 1s).
	 * Ex.: base 600 → ~480–720; base 60 → ~48–72.
	 */
	public static function comJitter(int $baseSegundos): int {
		$base = max(1, $baseSegundos);
		$jitter = (int)floor($base * 0.2);
		if ($jitter < 1) {
			return $base;
		}
		$jitter = min($jitter, $base - 1);
		return max(1, $base + random_int(-$jitter, $jitter));
	}

	/** Delay 1:1 para campanhas WhatsApp (piso 30s + jitter). */
	public static function delayCampanha1a1(int $configSegundos): int {
		$base = max(self::FLOOR_DELAY_1A1, $configSegundos);
		return self::comJitter($base);
	}

	/** Intervalo entre grupos (mín. 60s na config) + jitter. */
	public static function delayGrupoComJitter(int $configSegundos): int {
		$base = max(60, $configSegundos);
		return self::comJitter($base);
	}
}
