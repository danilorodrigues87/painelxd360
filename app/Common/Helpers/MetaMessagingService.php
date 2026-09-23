<?php

namespace App\Common\Helpers;

use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\MetaConversa;
use App\Model\Entity\MetaMensagem;
use App\Model\Entity\MetaWebhookLog;

/**
 * Webhook Meta: Messenger + Instagram Direct → inbox persistido.
 * Comentários continuam em SocialAutomacaoService.
 */
class MetaMessagingService {

	/**
	 * @return array{processados:int,salvos:int,ignorados:int,erros:int}
	 */
	public static function processarPayload(?int $idAdminFixo, array $payload): array {
		$resumo = ['processados' => 0, 'salvos' => 0, 'ignorados' => 0, 'erros' => 0];

		if (!MetaConversa::tabelaExiste() || !MetaMensagem::tabelaExiste()) {
			return $resumo;
		}

		$eventos = self::extrairMessaging($payload);
		foreach ($eventos as $ev) {
			$resumo['processados']++;
			$r = self::processarEvento($idAdminFixo, $ev, (string)($payload['object'] ?? ''));
			if ($r === 'salvo') {
				$resumo['salvos']++;
			} elseif ($r === 'erro') {
				$resumo['erros']++;
			} else {
				$resumo['ignorados']++;
			}
		}

		return $resumo;
	}

	/**
	 * @param array<string,mixed> $ev
	 */
	private static function processarEvento(?int $idAdminFixo, array $ev, string $object): string {
		$tipoEvento = (string)($ev['tipo_evento'] ?? 'message');

		if ($tipoEvento !== 'message') {
			MetaWebhookLog::registrar(
				$idAdminFixo,
				'messaging',
				'ignorado',
				$tipoEvento,
				null,
				'Evento não-mensagem'
			);
			return 'ignorado';
		}

		if (!empty($ev['is_echo'])) {
			return 'ignorado';
		}

		$participantId = trim((string)($ev['participant_id'] ?? ''));
		$pageId = trim((string)($ev['page_id'] ?? ''));
		$igId = trim((string)($ev['ig_id'] ?? ''));
		$canal = MetaConversa::normalizarCanal((string)($ev['canal'] ?? 'messenger'));
		$mid = trim((string)($ev['meta_message_id'] ?? ''));

		if ($participantId === '') {
			return 'ignorado';
		}

		if ($mid !== '' && MetaMensagem::existeMessageId($mid)) {
			return 'ignorado';
		}

		$cfg = null;
		if ($idAdminFixo && $idAdminFixo > 0) {
			$cfg = EscolaIntegracoes::getByIdAdmin($idAdminFixo);
		} else {
			$cfg = EscolaIntegracoes::getByMetaPageOrIg($pageId, $igId !== '' ? $igId : null);
		}

		if (!$cfg instanceof EscolaIntegracoes) {
			MetaWebhookLog::registrar(
				$idAdminFixo,
				'messaging',
				'ignorado',
				'inbound',
				$mid !== '' ? $mid : null,
				'Escola não encontrada',
				'page='.$pageId.' ig='.$igId
			);
			return 'ignorado';
		}

		$idAdmin = (int)$cfg->id_admin;
		$cfgPageId = trim((string)($cfg->meta_page_id ?? ''));
		if ($cfgPageId !== '') {
			$pageId = $cfgPageId;
		} elseif ($pageId === '') {
			$pageId = $cfgPageId;
		}

		$perfil = self::resolverPerfil($cfg, $participantId, $canal);
		$conversa = MetaConversa::findOrCreate($idAdmin, $canal, $participantId, $pageId, $perfil);
		if (!$conversa instanceof MetaConversa) {
			MetaWebhookLog::registrar($idAdmin, 'messaging', 'erro', 'inbound', $mid, 'Falha ao criar conversa');
			return 'erro';
		}

		$texto = trim((string)($ev['texto'] ?? ''));
		$tipoMsg = (string)($ev['tipo_mensagem'] ?? 'text');
		$anexoJson = !empty($ev['anexo_json']) ? (string)$ev['anexo_json'] : null;

		if ($texto === '' && $anexoJson === null) {
			MetaWebhookLog::registrar($idAdmin, 'messaging', 'ignorado', 'inbound', $mid, 'Sem texto/anexo');
			return 'ignorado';
		}

		$msgId = MetaMensagem::registrar([
			'id_admin'        => $idAdmin,
			'conversa_id'     => (int)$conversa->id,
			'direction'       => 'in',
			'tipo'            => $tipoMsg,
			'corpo'           => $texto !== '' ? $texto : null,
			'anexo_json'      => $anexoJson,
			'meta_message_id' => $mid !== '' ? $mid : null,
			'status_envio'    => 'received',
		]);

		if (!$msgId) {
			MetaWebhookLog::registrar($idAdmin, 'messaging', 'erro', 'inbound', $mid, 'Falha ao gravar mensagem');
			return 'erro';
		}

		$conversa->registrarMensagemRecebida($texto, $mid, $tipoMsg, $anexoJson);
		MetaWebhookLog::registrar(
			$idAdmin,
			'messaging',
			'ok',
			'inbound_'.$canal,
			$mid,
			mb_substr($texto !== '' ? $texto : '[anexo]', 0, 120)
		);

		$preview = $texto !== '' ? $texto : ($tipoMsg !== 'text' ? '['.$tipoMsg.']' : '[mensagem]');
		StaffNotificacaoService::novaMensagemMeta(
			$idAdmin,
			(int)$conversa->id,
			$canal,
			(string)($conversa->nome_contato ?? ''),
			$preview,
			$mid !== '' ? $mid : null
		);

		return 'salvo';
	}

	/**
	 * @return array{nome_contato?:string,foto_url?:string}
	 */
	private static function resolverPerfil(EscolaIntegracoes $cfg, string $participantId, string $canal): array {
		$token = $cfg->getMetaPageTokenDescriptografada();
		if (!$token) {
			return [];
		}

		$fields = $canal === 'instagram'
			? 'name,username,profile_pic'
			: 'first_name,last_name,profile_pic';

		$res = MetaGraphHelper::getUserProfile($participantId, $token, $fields);
		if (empty($res['ok'])) {
			return [];
		}

		$data = is_array($res['data'] ?? null) ? $res['data'] : [];
		$nome = '';
		if ($canal === 'instagram') {
			$nome = trim((string)($data['name'] ?? $data['username'] ?? ''));
		} else {
			$nome = trim(trim((string)($data['first_name'] ?? '')).' '.trim((string)($data['last_name'] ?? '')));
		}

		$out = [];
		if ($nome !== '') {
			$out['nome_contato'] = $nome;
		}
		$pic = trim((string)($data['profile_pic'] ?? ''));
		if ($pic !== '') {
			$out['foto_url'] = $pic;
		}
		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function extrairMessaging(array $payload): array {
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
			$pageId = $object === 'page' ? $entryId : '';
			$igId = $object === 'instagram' ? $entryId : '';

			foreach (($entry['messaging'] ?? []) as $item) {
				if (!is_array($item)) {
					continue;
				}
				$parsed = self::parseMessagingItem($item, $object, $pageId, $igId);
				if ($parsed !== null) {
					$out[] = $parsed;
				}
			}

			// Handover protocol / secondary channel
			foreach (($entry['standby'] ?? []) as $item) {
				if (!is_array($item)) {
					continue;
				}
				$parsed = self::parseMessagingItem($item, $object, $pageId, $igId);
				if ($parsed !== null) {
					$out[] = $parsed;
				}
			}

			// Instagram standalone: alguns payloads usam messaging em changes
			foreach (($entry['changes'] ?? []) as $change) {
				if (!is_array($change)) {
					continue;
				}
				$field = (string)($change['field'] ?? '');
				if ($field !== 'messages') {
					continue;
				}
				$value = $change['value'] ?? [];
				if (!is_array($value)) {
					continue;
				}
				$parsed = self::parseInstagramChangeMessage($value, $entryId);
				if ($parsed !== null) {
					$out[] = $parsed;
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>|null
	 */
	private static function parseMessagingItem(array $item, string $object, string $pageId, string $igId): ?array {
		if (isset($item['message']) && is_array($item['message'])) {
			return self::parseMessageBlock($item, $object, $pageId, $igId);
		}
		if (isset($item['read']) || isset($item['delivery'])) {
			return [
				'tipo_evento' => isset($item['read']) ? 'read' : 'delivery',
				'page_id'     => $pageId,
				'ig_id'       => $igId,
			];
		}
		if (isset($item['postback'])) {
			return [
				'tipo_evento' => 'postback',
				'page_id'     => $pageId,
				'ig_id'       => $igId,
			];
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>|null
	 */
	private static function parseMessageBlock(array $item, string $object, string $pageId, string $igId): ?array {
		$msg = $item['message'];
		if (!empty($msg['is_deleted'])) {
			return null;
		}

		$senderId = trim((string)($item['sender']['id'] ?? ''));
		$recipientId = trim((string)($item['recipient']['id'] ?? ''));
		$isEcho = !empty($msg['is_echo']);

		// Echo: remetente é a Page — ignorar inbound (outbound será gravado na Fase B ao enviar)
		if ($isEcho) {
			return [
				'tipo_evento'     => 'message',
				'is_echo'         => true,
				'meta_message_id' => (string)($msg['mid'] ?? ''),
			];
		}

		$participantId = $senderId;
		if ($participantId === '' || $participantId === $pageId || $participantId === $recipientId) {
			return null;
		}

		$canal = self::detectarCanal($object, $item);

		$texto = trim((string)($msg['text'] ?? ''));
		$tipo = 'text';
		$anexoJson = null;

		if (!empty($msg['attachments']) && is_array($msg['attachments'])) {
			$tipo = 'attachment';
			$anexoJson = json_encode($msg['attachments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($texto === '') {
				$first = $msg['attachments'][0] ?? [];
				$tipoAnexo = is_array($first) ? (string)($first['type'] ?? 'attachment') : 'attachment';
				$texto = '['.$tipoAnexo.']';
			}
		}

		$resolvedPageId = $pageId;
		if ($resolvedPageId === '' && $canal === 'messenger') {
			$resolvedPageId = $recipientId;
		}

		return [
			'tipo_evento'     => 'message',
			'canal'           => $canal,
			'page_id'         => $resolvedPageId,
			'ig_id'           => $igId !== '' ? $igId : ($canal === 'instagram' ? $recipientId : ''),
			'participant_id'  => $participantId,
			'meta_message_id' => (string)($msg['mid'] ?? ''),
			'texto'           => $texto,
			'tipo_mensagem'   => $tipo,
			'anexo_json'      => $anexoJson,
			'is_echo'         => false,
		];
	}

	/**
	 * @param array<string,mixed> $value
	 * @return array<string,mixed>|null
	 */
	private static function parseInstagramChangeMessage(array $value, string $igEntryId): ?array {
		$senderId = trim((string)($value['from']['id'] ?? $value['sender_id'] ?? ''));
		$mid = trim((string)($value['id'] ?? $value['mid'] ?? ''));
		$texto = trim((string)($value['text'] ?? $value['message'] ?? ''));

		if ($senderId === '' || ($texto === '' && empty($value['attachments']))) {
			return null;
		}

		$anexoJson = null;
		$tipo = 'text';
		if (!empty($value['attachments']) && is_array($value['attachments'])) {
			$tipo = 'attachment';
			$anexoJson = json_encode($value['attachments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($texto === '') {
				$texto = '[anexo]';
			}
		}

		return [
			'tipo_evento'     => 'message',
			'canal'           => 'instagram',
			'page_id'         => '',
			'ig_id'           => $igEntryId,
			'participant_id'  => $senderId,
			'meta_message_id' => $mid,
			'texto'           => $texto,
			'tipo_mensagem'   => $tipo,
			'anexo_json'      => $anexoJson,
			'is_echo'         => false,
		];
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private static function detectarCanal(string $object, array $item): string {
		$product = strtolower(trim((string)($item['messaging_product'] ?? '')));
		if ($product === 'instagram') {
			return 'instagram';
		}
		if ($product === 'messenger' || $product === 'facebook') {
			return 'messenger';
		}
		if ($object === 'instagram') {
			return 'instagram';
		}
		return 'messenger';
	}

	/**
	 * Envia resposta pelo inbox (usado na Fase B; disponível na Fase A para testes).
	 *
	 * @return array{ok:bool,message?:string,meta_message_id?:string}
	 */
	public static function enviarResposta(MetaConversa $conversa, string $texto, ?int $idUsuario = null): array {
		$texto = trim($texto);
		if ($texto === '') {
			return ['ok' => false, 'message' => 'Mensagem vazia.'];
		}

		$cfg = EscolaIntegracoes::getByIdAdmin((int)$conversa->id_admin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return ['ok' => false, 'message' => 'Integração Meta não encontrada.'];
		}

		$token = $cfg->getMetaPageTokenDescriptografada();
		$pageId = trim((string)($cfg->meta_page_id ?? ''));
		if ($pageId === '') {
			$pageId = trim((string)($conversa->page_id ?? ''));
		}
		$storedPageId = trim((string)($conversa->page_id ?? ''));
		$cfgPageId = trim((string)($cfg->meta_page_id ?? ''));
		if ($cfgPageId !== '' && MetaConversa::pageIdPrecisaCorrigir($storedPageId, $cfgPageId)) {
			(new \App\Model\Db\Database('meta_conversas'))->update('id = '.(int)$conversa->id, ['page_id' => $cfgPageId]);
			$conversa->page_id = $cfgPageId;
			$pageId = $cfgPageId;
		}
		$participantId = trim((string)($conversa->participant_id ?? ''));
		$canal = MetaConversa::normalizarCanal((string)($conversa->canal ?? 'messenger'));

		if (!$token || $pageId === '' || $participantId === '') {
			return ['ok' => false, 'message' => 'Page/token/participante ausentes.'];
		}

		$api = MetaGraphHelper::sendMessage($pageId, $token, $participantId, $texto, null, $canal);
		if (empty($api['ok'])) {
			return ['ok' => false, 'message' => (string)($api['message'] ?? 'Falha ao enviar.')];
		}

		$mid = (string)($api['id'] ?? $api['message_id'] ?? '');

		MetaMensagem::registrar([
			'id_admin'        => (int)$conversa->id_admin,
			'conversa_id'     => (int)$conversa->id,
			'direction'       => 'out',
			'tipo'            => 'text',
			'corpo'           => $texto,
			'meta_message_id' => $mid !== '' ? $mid : null,
			'status_envio'    => 'sent',
			'id_usuario'      => $idUsuario,
		]);

		$conversa->registrarMensagemEnviada($texto);

		return [
			'ok'              => true,
			'meta_message_id' => $mid,
		];
	}
}
