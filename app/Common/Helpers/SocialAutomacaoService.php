<?php

namespace App\Common\Helpers;

use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\MetaWebhookLog;
use App\Model\Entity\SocialAutomacao;
use App\Model\Entity\SocialAutomacaoLog;

/**
 * Webhook Meta: comentário com keyword → private reply (DM).
 */
class SocialAutomacaoService {

	/**
	 * @return array{processados:int,enviados:int,ignorados:int,erros:int}
	 */
	public static function processarPayload(?int $idAdminFixo, array $payload): array {
		$resumo = ['processados' => 0, 'enviados' => 0, 'ignorados' => 0, 'erros' => 0];
		if (!SocialAutomacao::tabelaExiste()) {
			return $resumo;
		}

		if (self::parecePayloadComentario($payload)) {
			self::logDebugWebhook($idAdminFixo, 'recebido', $payload, null);
			if (self::payloadComentarioSemValores($payload)) {
				self::logDebugWebhook(
					$idAdminFixo,
					'sem_valores',
					$payload,
					'Meta enviou só changed_fields — ative Include Values no App Dashboard'
				);
			}
		}

		$eventos = self::extrairComentarios($payload);
		if (self::parecePayloadComentario($payload)) {
			self::logDebugWebhook(
				$idAdminFixo,
				count($eventos) > 0 ? 'extraido' : 'sem_parse',
				$payload,
				count($eventos).' comentário(s) extraído(s)'
			);
		}

		foreach ($eventos as $ev) {
			$resumo['processados']++;
			$cfg = null;
			if ($idAdminFixo && $idAdminFixo > 0) {
				$cfg = EscolaIntegracoes::getByIdAdmin($idAdminFixo);
			} else {
				$cfg = EscolaIntegracoes::getByMetaPageOrIg($ev['page_id'] ?? null, $ev['ig_id'] ?? null);
			}
			if (!$cfg instanceof EscolaIntegracoes) {
				$resumo['ignorados']++;
				self::logDebugWebhook(
					$idAdminFixo,
					'escola_nao_encontrada',
					$ev,
					'page='.($ev['page_id'] ?? '').' ig='.($ev['ig_id'] ?? '')
				);
				continue;
			}
			$r = self::processarEvento($cfg, $ev);
			if (($r['status'] ?? '') === 'ok') {
				$resumo['enviados']++;
			} elseif (($r['status'] ?? '') === 'erro') {
				$resumo['erros']++;
			} else {
				$resumo['ignorados']++;
			}
		}
		return $resumo;
	}

	/**
	 * @param array{comment_id:string,texto:string,canal:string,page_id?:string,ig_id?:string} $ev
	 * @return array{status:string,message?:string}
	 */
	public static function processarEvento(EscolaIntegracoes $cfg, array $ev): array {
		$idAdmin = (int)$cfg->id_admin;
		$commentId = trim((string)($ev['comment_id'] ?? ''));
		$texto = (string)($ev['texto'] ?? '');
		$canal = (string)($ev['canal'] ?? 'instagram');

		if ($commentId === '') {
			return ['status' => 'ignorado', 'message' => 'Sem comment_id'];
		}
		if (SocialAutomacaoLog::jaProcessou($commentId)) {
			return ['status' => 'ignorado', 'message' => 'Já processado'];
		}

		$autoOn = EscolaIntegracoes::temColunaMetaAuto()
			? ((int)($cfg->meta_auto_ativo ?? 0) === 1)
			: true;
		if (!$autoOn) {
			SocialAutomacaoLog::registrar($idAdmin, null, $commentId, $canal, 'ignorado', $texto, 'Automações desligadas');
			return ['status' => 'ignorado', 'message' => 'Automações desligadas'];
		}

		$token = $cfg->getMetaPageTokenDescriptografada();
		$pageId = trim((string)($cfg->meta_page_id ?? ''));
		if (!$token || $pageId === '') {
			SocialAutomacaoLog::registrar($idAdmin, null, $commentId, $canal, 'erro', $texto, 'Page/token ausentes');
			return ['status' => 'erro', 'message' => 'Page/token ausentes'];
		}

		$regra = null;
		foreach (SocialAutomacao::listByAdmin($idAdmin, true) as $auto) {
			$canalOk = $auto->canais === 'ambos'
				|| ($auto->canais === 'instagram' && $canal === 'instagram')
				|| ($auto->canais === 'facebook' && $canal === 'facebook');
			if (!$canalOk) {
				continue;
			}
			if ($auto->bateCom($texto)) {
				$regra = $auto;
				break;
			}
		}
		if (!$regra) {
			SocialAutomacaoLog::registrar($idAdmin, null, $commentId, $canal, 'ignorado', $texto, 'Sem keyword');
			return ['status' => 'ignorado', 'message' => 'Sem keyword'];
		}

		$msg = trim((string)$regra->mensagem_dm);
		if ($msg === '') {
			SocialAutomacaoLog::registrar($idAdmin, (int)$regra->id, $commentId, $canal, 'erro', $texto, 'Mensagem vazia');
			return ['status' => 'erro', 'message' => 'Mensagem vazia'];
		}

		if ($canal === 'facebook') {
			$api = MetaGraphHelper::privateReplyFacebook($commentId, $token, $msg);
		} else {
			$api = MetaGraphHelper::privateReplyInstagram($pageId, $token, $commentId, $msg);
		}

		if (empty($api['ok'])) {
			SocialAutomacaoLog::registrar(
				$idAdmin,
				(int)$regra->id,
				$commentId,
				$canal,
				'erro',
				$texto,
				(string)($api['message'] ?? 'Falha Meta')
			);
			return ['status' => 'erro', 'message' => $api['message'] ?? 'Falha'];
		}

		SocialAutomacaoLog::registrar($idAdmin, (int)$regra->id, $commentId, $canal, 'ok', $texto, null);
		return ['status' => 'ok', 'message' => 'DM enviada'];
	}

	/**
	 * @return array<int,array{comment_id:string,texto:string,canal:string,page_id?:string,ig_id?:string}>
	 */
	public static function extrairComentarios(array $payload): array {
		$out = [];
		$object = (string)($payload['object'] ?? '');
		$entries = $payload['entry'] ?? [];
		if (!is_array($entries)) {
			return $out;
		}

		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$entryId = (string)($entry['id'] ?? '');

			// Instagram API: field/value direto no entry (sem changes)
			$flatField = (string)($entry['field'] ?? '');
			if ($flatField !== '' && isset($entry['value']) && is_array($entry['value'])) {
				$parsed = self::parseCommentChange($flatField, $entry['value'], $object, $entryId);
				if ($parsed !== null) {
					$out[] = $parsed;
				}
			}

			foreach (($entry['changes'] ?? []) as $change) {
				if (!is_array($change)) {
					continue;
				}
				$field = (string)($change['field'] ?? '');
				$value = $change['value'] ?? [];
				if (!is_array($value)) {
					continue;
				}
				$parsed = self::parseCommentChange($field, $value, $object, $entryId);
				if ($parsed !== null) {
					$out[] = $parsed;
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $value
	 * @return array{comment_id:string,texto:string,canal:string,page_id?:string,ig_id?:string}|null
	 */
	private static function parseCommentChange(string $field, array $value, string $object, string $entryId): ?array {
		if ($field === 'comments' || $field === 'live_comments') {
			$cid = trim((string)($value['id'] ?? $value['comment_id'] ?? ''));
			$text = trim((string)($value['text'] ?? $value['message'] ?? ''));
			if ($cid === '') {
				return null;
			}
			return [
				'comment_id' => $cid,
				'texto'      => $text,
				'canal'      => 'instagram',
				'ig_id'      => $entryId,
				'page_id'    => '',
			];
		}

		if ($field !== 'feed') {
			return null;
		}

		$item = (string)($value['item'] ?? '');
		$verb = strtolower(trim((string)($value['verb'] ?? '')));
		if ($item !== 'comment') {
			return null;
		}
		if ($verb !== '' && !in_array($verb, ['add', 'edited', 'remove'], true)) {
			return null;
		}
		if ($verb === 'remove') {
			return null;
		}

		$cid = trim((string)($value['comment_id'] ?? $value['id'] ?? ''));
		$text = trim((string)($value['message'] ?? $value['text'] ?? ''));
		if ($cid === '') {
			return null;
		}

		return [
			'comment_id' => $cid,
			'texto'      => $text,
			'canal'      => 'facebook',
			'page_id'    => $entryId,
			'ig_id'      => '',
		];
	}

	/** @param array<string,mixed> $payload */
	private static function parecePayloadComentario(array $payload): bool {
		foreach (($payload['entry'] ?? []) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$flat = (string)($entry['field'] ?? '');
			if (in_array($flat, ['comments', 'live_comments', 'feed'], true)) {
				return true;
			}
			if (!empty($entry['changed_fields']) && is_array($entry['changed_fields'])) {
				foreach ($entry['changed_fields'] as $f) {
					if (in_array((string)$f, ['comments', 'live_comments', 'feed'], true)) {
						return true;
					}
				}
			}
			foreach (($entry['changes'] ?? []) as $change) {
				if (!is_array($change)) {
					continue;
				}
				$f = (string)($change['field'] ?? '');
				if (in_array($f, ['comments', 'live_comments', 'feed'], true)) {
					return true;
				}
			}
		}
		return false;
	}

	/** @param array<string,mixed> $payload */
	private static function payloadComentarioSemValores(array $payload): bool {
		foreach (($payload['entry'] ?? []) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			if (!empty($entry['changed_fields']) && empty($entry['changes']) && !isset($entry['value'])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed>|null $context
	 */
	private static function logDebugWebhook(?int $idAdmin, string $evento, $context, ?string $nota): void {
		$json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json)) {
			$json = '{}';
		}
		$object = is_array($context) ? (string)($context['object'] ?? '') : '';
		if ($object === '' && is_array($context)) {
			$object = (string)($context['canal'] ?? 'comentario');
		}

		MetaWebhookLog::registrar(
			$idAdmin && $idAdmin > 0 ? $idAdmin : null,
			'comentario',
			'debug',
			$evento,
			null,
			'object='.$object.($nota ? ' · '.$nota : ''),
			$json
		);
	}
}
