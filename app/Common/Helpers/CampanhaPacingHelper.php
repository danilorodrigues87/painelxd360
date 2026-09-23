<?php

namespace App\Common\Helpers;

use App\Common\Communication\WhatsappEscolaService;
use App\Model\Entity\Campanhas;
use App\Model\Entity\EscolaIntegracoes;

/**
 * Pacing por campanha (segmento.pacing) com fallback em escola_integracoes.
 */
class CampanhaPacingHelper {

	/**
	 * @return array{
	 *   personalizado: bool,
	 *   delay_1a1_segundos: int,
	 *   grupo_delay_segundos: int,
	 *   max_hora: int,
	 *   email_delay_segundos: int
	 * }
	 */
	public static function resolver(Campanhas $campanha, ?EscolaIntegracoes $config = null): array {
		$idAdmin = (int)$campanha->id_admin;
		if (!$config instanceof EscolaIntegracoes) {
			$config = EscolaIntegracoes::getByIdAdmin($idAdmin);
		}

		$canal = ($campanha->canal ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';
		$defaults = self::defaultsEscola($config, $canal);

		$segmento = json_decode($campanha->segmento ?? '{}', true) ?: [];
		$pacing = is_array($segmento['pacing'] ?? null) ? $segmento['pacing'] : [];
		if (empty($pacing['personalizado'])) {
			return array_merge($defaults, ['personalizado' => false]);
		}

		if ($canal === 'whatsapp') {
			return [
				'personalizado'          => true,
				'delay_1a1_segundos'   => max(
					WhatsappPacingHelper::FLOOR_DELAY_1A1,
					(int)($pacing['delay_1a1_segundos'] ?? $defaults['delay_1a1_segundos'])
				),
				'grupo_delay_segundos' => max(
					60,
					(int)($pacing['grupo_delay_segundos'] ?? $defaults['grupo_delay_segundos'])
				),
				'max_hora'             => max(1, (int)($pacing['max_hora'] ?? $defaults['max_hora'])),
				'email_delay_segundos' => $defaults['email_delay_segundos'],
			];
		}

		return [
			'personalizado'          => true,
			'delay_1a1_segundos'   => $defaults['delay_1a1_segundos'],
			'grupo_delay_segundos' => $defaults['grupo_delay_segundos'],
			'max_hora'             => max(1, (int)($pacing['max_hora'] ?? $defaults['max_hora'])),
			'email_delay_segundos' => max(1, (int)($pacing['email_delay_segundos'] ?? $defaults['email_delay_segundos'])),
		];
	}

	/** @return array{delay_1a1_segundos:int,grupo_delay_segundos:int,max_hora:int,email_delay_segundos:int} */
	public static function defaultsEscola(?EscolaIntegracoes $config, string $canal): array {
		if ($canal === 'whatsapp') {
			$delay1a1 = ($config instanceof EscolaIntegracoes)
				? (int)($config->whatsapp_delay_segundos ?? WhatsappPacingHelper::DEFAULT_DELAY_1A1)
				: WhatsappPacingHelper::DEFAULT_DELAY_1A1;
			$grupo = WhatsappPacingHelper::DEFAULT_DELAY_GRUPO;
			if ($config instanceof EscolaIntegracoes && EscolaIntegracoes::temColunaWhatsappGrupoDelay()) {
				$grupo = (int)($config->whatsapp_grupo_delay_segundos ?? WhatsappPacingHelper::DEFAULT_DELAY_GRUPO);
			}
			$maxHora = ($config instanceof EscolaIntegracoes)
				? (int)($config->whatsapp_max_hora ?? WhatsappPacingHelper::DEFAULT_MAX_HORA)
				: WhatsappPacingHelper::DEFAULT_MAX_HORA;

			return [
				'delay_1a1_segundos'   => max(WhatsappPacingHelper::FLOOR_DELAY_1A1, $delay1a1),
				'grupo_delay_segundos' => max(60, $grupo),
				'max_hora'             => max(1, $maxHora),
				'email_delay_segundos' => 3,
			];
		}

		$delayEmail = ($config instanceof EscolaIntegracoes)
			? max(1, (int)($config->email_delay_segundos ?? 3))
			: 3;
		$maxHora = ($config instanceof EscolaIntegracoes)
			? max(1, (int)($config->email_max_hora ?? 80))
			: 80;

		return [
			'delay_1a1_segundos'   => WhatsappPacingHelper::DEFAULT_DELAY_1A1,
			'grupo_delay_segundos' => WhatsappPacingHelper::DEFAULT_DELAY_GRUPO,
			'max_hora'             => $maxHora,
			'email_delay_segundos' => $delayEmail,
		];
	}

	/** Monta segmento.pacing a partir do POST do modal. */
	public static function parseFromPost(array $post, string $canal): array {
		if (empty($post['pacing_personalizado'])) {
			return ['personalizado' => false];
		}

		$out = ['personalizado' => true];
		if ($canal === 'whatsapp') {
			$out['delay_1a1_segundos'] = max(
				WhatsappPacingHelper::FLOOR_DELAY_1A1,
				(int)($post['pacing_delay_1a1'] ?? WhatsappPacingHelper::DEFAULT_DELAY_1A1)
			);
			$minGrupo = max(1, (int)($post['pacing_grupo_minutos'] ?? 10));
			$out['grupo_delay_segundos'] = max(60, $minGrupo * 60);
			$out['max_hora'] = max(1, (int)($post['pacing_max_hora'] ?? WhatsappPacingHelper::DEFAULT_MAX_HORA));
		} else {
			$out['email_delay_segundos'] = max(1, (int)($post['pacing_email_delay'] ?? 3));
			$out['max_hora'] = max(1, (int)($post['pacing_max_hora'] ?? 80));
		}

		return $out;
	}

	public static function respeitarExpediente(?EscolaIntegracoes $config): bool {
		if (!$config instanceof EscolaIntegracoes || !EscolaIntegracoes::temColunaCampanhaRespeitarExpediente()) {
			return false;
		}
		return !empty($config->campanha_respeitar_expediente);
	}

	/**
	 * Resumo para UI (listagem / detalhes).
	 * @return array{personalizado:bool,label:string,delay_1a1_segundos?:int,grupo_delay_minutos?:int,max_hora?:int,email_delay_segundos?:int}
	 */
	public static function resumoParaUi(Campanhas $campanha, ?EscolaIntegracoes $config = null): array {
		$p = self::resolver($campanha, $config);
		$canal = ($campanha->canal ?? 'email') === 'whatsapp' ? 'whatsapp' : 'email';

		if (!$p['personalizado']) {
			return [
				'personalizado' => false,
				'label'         => 'Padrão da escola (Comunicação)',
			];
		}

		if ($canal === 'whatsapp') {
			$minGrupo = (int)max(1, round($p['grupo_delay_segundos'] / 60));
			return [
				'personalizado'        => true,
				'label'                => sprintf(
					'Personalizado: 1:1 %ds · grupos %d min · máx. %d/h',
					$p['delay_1a1_segundos'],
					$minGrupo,
					$p['max_hora']
				),
				'delay_1a1_segundos'   => $p['delay_1a1_segundos'],
				'grupo_delay_minutos'  => $minGrupo,
				'max_hora'             => $p['max_hora'],
			];
		}

		return [
			'personalizado'          => true,
			'label'                  => sprintf(
				'Personalizado: intervalo %ds · máx. %d/h',
				$p['email_delay_segundos'],
				$p['max_hora']
			),
			'email_delay_segundos'   => $p['email_delay_segundos'],
			'max_hora'               => $p['max_hora'],
		];
	}

	/**
	 * Estado do expediente para campanhas WhatsApp.
	 * @return array{
	 *   respeitar: bool,
	 *   respeitar_ok: bool,
	 *   configurado: bool,
	 *   fora: bool,
	 *   horario_inicio: ?string,
	 *   horario_fim: ?string
	 * }
	 */
	public static function infoExpediente(int $idAdmin): array {
		$config = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$respeitar = self::respeitarExpediente($config);
		$fora = WhatsappEscolaService::estaForaExpediente($idAdmin);

		return [
			'respeitar'       => $respeitar,
			'respeitar_ok'    => EscolaIntegracoes::temColunaCampanhaRespeitarExpediente(),
			'configurado'     => !empty($fora['configurado']),
			'fora'            => $respeitar && !empty($fora['fora']),
			'horario_inicio'  => ($config instanceof EscolaIntegracoes)
				? (substr((string)($config->whatsapp_horario_inicio ?? ''), 0, 5) ?: null)
				: null,
			'horario_fim'     => ($config instanceof EscolaIntegracoes)
				? (substr((string)($config->whatsapp_horario_fim ?? ''), 0, 5) ?: null)
				: null,
		];
	}
}
