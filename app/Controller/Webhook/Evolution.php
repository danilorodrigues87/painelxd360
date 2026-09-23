<?php

namespace App\Controller\Webhook;

use App\Common\Communication\EvolutionApiService;
use App\Common\Communication\WhatsappEscolaService;
use App\Common\Communication\WhatsappChatbotService;
use App\Common\Communication\WhatsappMediaStorage;
use App\Common\Helpers\StaffNotificacaoService;
use App\Common\Environment;
use App\Model\Entity\WhatsappConversa;
use App\Model\Entity\WhatsappMensagem;
use App\Model\Entity\WhatsappNumero;

class Evolution {

	public static function receber($request, $idAdmin, $token) {
		$idAdmin = (int)$idAdmin;
		$esperado = EvolutionApiService::webhookToken($idAdmin);

		if (!hash_equals($esperado, (string)$token)) {
			self::logWebhook($idAdmin, 'token_invalido', []);
			return json_encode(['success' => false, 'message' => 'Token inválido.']);
		}

		$raw = file_get_contents('php://input');
		$payload = json_decode((string)$raw, true);
		if (!is_array($payload)) {
			$post = $request->getPostVars();
			$payload = is_array($post) ? $post : [];
		}

		$event = self::normalizarEvento((string)($payload['event'] ?? $payload['type'] ?? ''));
		$data = $payload['data'] ?? $payload;
		$instanceName = (string)($payload['instance'] ?? $payload['instanceName'] ?? '');

		self::logWebhook($idAdmin, $event ?: 'sem_evento', [
			'instance' => $instanceName,
			'has_data' => is_array($data),
		]);

		if (strpos($event, 'connection') !== false || isset($data['state']) || isset($data['connection'])) {
			$estado = EvolutionApiService::extrairEstado(is_array($data) ? $data : $payload);
			$numero = null;
			if (isset($data['instance']['owner'])) {
				$numero = EvolutionApiService::normalizarTelefone((string)$data['instance']['owner']);
			} elseif (isset($data['wuid'])) {
				$numero = EvolutionApiService::normalizarTelefone((string)$data['wuid']);
			}
			WhatsappEscolaService::atualizarStatusConexao($idAdmin, $estado ?: 'unknown', $numero);
		}

		if (self::eventoEhMensagem($event, $data, $payload)) {
			self::processarMensagens($idAdmin, $data, $instanceName);
			self::logWebhook($idAdmin, 'msg_processada', ['instance' => $instanceName], true);
		}

		return json_encode(['success' => true]);
	}

	private static function normalizarEvento(string $event): string {
		$event = strtolower(trim($event));
		return str_replace(['-', ' '], ['.', ''], $event);
	}

	private static function eventoEhMensagem(string $event, $data, array $payload): bool {
		if ($event !== '' && strpos($event, 'messages') !== false && strpos($event, 'upsert') !== false) {
			return true;
		}
		if (!is_array($data)) {
			return false;
		}
		if (isset($data['messages']) || isset($data['key']) || isset($data['message'])) {
			return true;
		}
		if (isset($data[0]) && is_array($data[0]) && (isset($data[0]['key']) || isset($data[0]['message']))) {
			return true;
		}
		return isset($payload['key']) || isset($payload['message']);
	}

	private static function logWebhook(int $idAdmin, string $evento, array $extra = [], bool $force = false): void {
		if (!$force && !(bool)Environment::get('EVOLUTION_WEBHOOK_DEBUG', false) && $evento !== 'token_invalido') {
			return;
		}
		$linha = '[EvolutionWebhook] id_admin='.$idAdmin.' event='.$evento;
		if ($extra) {
			$linha .= ' '.json_encode($extra, JSON_UNESCAPED_UNICODE);
		}
		error_log($linha);
	}

	private static function processarMensagens(int $idAdmin, $data, string $instanceName = ''): void {
		if (!is_array($data)) {
			return;
		}

		$numeroId = null;
		$default = WhatsappNumero::getDefault($idAdmin);
		if ($default) {
			$numeroId = (int)$default->id;
			if ($instanceName === '' && !empty($default->evolution_instance)) {
				$instanceName = (string)$default->evolution_instance;
			}
		}
		if ($instanceName === '') {
			$instanceName = EvolutionApiService::nomeInstancia($idAdmin);
		}

		$itens = self::normalizarItensMensagem($data);
		$processadas = 0;
		foreach ($itens as $msg) {
			if (!is_array($msg)) {
				continue;
			}

			$key = $msg['key'] ?? [];
			$fromMe = !empty($key['fromMe']);
			$remoteJid = self::resolverRemoteJid($msg);
			if ($remoteJid === '') {
				continue;
			}

			$telefone = self::resolverTelefoneContato($msg, $remoteJid);
			if ($telefone === '') {
				self::logWebhook($idAdmin, 'telefone_vazio', ['jid' => $remoteJid]);
				continue;
			}

			if (self::ignorarMensagemSistema($msg)) {
				continue;
			}

			$nome = null;
			if (!$fromMe) {
				$push = trim((string)($msg['pushName'] ?? ''));
				$nome = $push !== '' ? $push : WhatsappConversa::resolverNomePorTelefone($idAdmin, $telefone);
			}

			$tipo = self::extrairTipo($msg);
			$corpo = self::extrairTexto($msg);
			$waId = $key['id'] ?? ($msg['id'] ?? null);
			$ecoSistema = $fromMe && WhatsappMensagem::existeWaMessageId(is_string($waId) ? $waId : null);
			$mediaUrl = self::salvarMidiaRecebida($idAdmin, $instanceName, $msg, $tipo);

			// Reação sem emoji = remoção; ainda registramos com texto amigável
			if ($tipo === 'reaction' && ($corpo === null || $corpo === '')) {
				$corpo = '';
			}

			// Evita bolha vazia de eventos sem conteúdo útil
			if ($tipo === 'text' && ($corpo === null || trim((string)$corpo) === '') && $mediaUrl === null) {
				continue;
			}

			$conversa = WhatsappConversa::findOrCreate($idAdmin, $telefone, $nome, $numeroId, !$fromMe);
			if (!$conversa) {
				continue;
			}

			WhatsappMensagem::registrar([
				'id_admin'      => $idAdmin,
				'conversa_id'   => (int)$conversa->id,
				'direction'     => $fromMe ? 'out' : 'in',
				'tipo'          => $tipo,
				'corpo'         => $corpo,
				'media_url'     => $mediaUrl,
				'wa_message_id' => $waId,
				'status'        => $fromMe ? 'sent' : 'received',
			]);

			$conversa->tocarUltimaMensagem(!$fromMe);

			// Mensagem enviada pelo celular/WhatsApp Web: handoff humano (não eco do bot/inbox)
			if ($fromMe && !$ecoSistema && $tipo !== 'reaction') {
				WhatsappChatbotService::marcarHandoffHumanoExterno($conversa);
			}

			// Reações não disparam o chatbot de menu
			if (!$fromMe && $tipo !== 'reaction') {
				WhatsappChatbotService::aoReceberMensagem($conversa, $corpo, false);
				$preview = trim((string)$corpo);
				if ($preview === '' && $mediaUrl) {
					$preview = $tipo === 'audio' ? '[áudio]' : ($tipo === 'image' ? '[imagem]' : '['.$tipo.']');
				}
				StaffNotificacaoService::novaMensagemWhatsapp(
					$idAdmin,
					(int)$conversa->id,
					$conversa->nome_contato ?? $nome,
					$preview !== '' ? $preview : '[mensagem]',
					is_string($waId) ? $waId : null
				);
			}
			$processadas++;
		}
		if ($processadas > 0) {
			self::logWebhook($idAdmin, 'msgs_salvas', ['qtd' => $processadas, 'instance' => $instanceName], true);
		}
	}

	/** Resolve JID do contato (inclui fallback para @lid / privacidade). */
	private static function resolverRemoteJid(array $msg): string {
		$key = $msg['key'] ?? [];
		$remoteJid = (string)($key['remoteJid'] ?? $msg['remoteJid'] ?? '');

		if ($remoteJid !== '' && strpos($remoteJid, '@g.us') !== false) {
			return '';
		}

		if ($remoteJid !== '' && strpos($remoteJid, '@s.whatsapp.net') !== false) {
			return $remoteJid;
		}

		$ctx = $msg['contextInfo'] ?? $key['contextInfo'] ?? null;
		$candidatos = [
			$key['remoteJidAlt'] ?? null,
			$msg['remoteJidAlt'] ?? null,
			$key['senderPn'] ?? null,
			$msg['senderPn'] ?? null,
			$key['participant'] ?? null,
			$msg['participant'] ?? null,
			$msg['sender'] ?? null,
			is_array($ctx) ? ($ctx['participant'] ?? null) : null,
			is_array($ctx) ? ($ctx['remoteJid'] ?? null) : null,
			$remoteJid !== '' ? $remoteJid : null,
		];

		foreach ($candidatos as $alt) {
			if (!is_string($alt) || trim($alt) === '') {
				continue;
			}
			$alt = trim($alt);
			if (strpos($alt, '@g.us') !== false) {
				continue;
			}
			if (strpos($alt, '@') !== false) {
				return $alt;
			}
			$norm = EvolutionApiService::normalizarTelefone($alt);
			if ($norm !== '') {
				return $norm.'@s.whatsapp.net';
			}
		}

		return '';
	}

	/** Prefere telefone real (senderPn) antes de cair no identificador @lid. */
	private static function resolverTelefoneContato(array $msg, string $remoteJid): string {
		$key = $msg['key'] ?? [];
		$candidatos = [
			$key['senderPn'] ?? null,
			$key['remoteJidAlt'] ?? null,
			$msg['senderPn'] ?? null,
			$msg['remoteJidAlt'] ?? null,
			$remoteJid,
		];

		foreach ($candidatos as $jid) {
			if (!is_string($jid) || trim($jid) === '') {
				continue;
			}
			$jid = trim($jid);
			if (strpos($jid, '@g.us') !== false) {
				continue;
			}
			if (strpos($jid, '@s.whatsapp.net') !== false) {
				$tel = EvolutionApiService::normalizarTelefone(explode('@', $jid)[0] ?? '');
				if ($tel !== '') {
					return $tel;
				}
			}
			if (strpos($jid, '@') === false) {
				$tel = EvolutionApiService::normalizarTelefone($jid);
				if ($tel !== '') {
					return $tel;
				}
			}
		}

		return self::jidParaTelefone($remoteJid);
	}

	private static function jidParaTelefone(string $remoteJid): string {
		$parte = explode('@', $remoteJid)[0] ?? '';
		if (strpos($remoteJid, '@lid') !== false) {
			$digitos = preg_replace('/\D+/', '', $parte) ?? '';
			return $digitos !== '' ? ('lid:'.$digitos) : '';
		}
		return EvolutionApiService::normalizarTelefone($parte);
	}

	/** Normaliza payload Evolution (array, objeto único ou data.messages). */
	private static function normalizarItensMensagem(array $data): array {
		if (isset($data['messages']) && is_array($data['messages'])) {
			return $data['messages'];
		}
		if (isset($data[0]) && is_array($data[0])) {
			return $data;
		}
		return [$data];
	}

	/** Eventos internos do WhatsApp que não devem virar mensagem no inbox. */
	private static function ignorarMensagemSistema(array $msg): bool {
		$message = self::desembrulharMensagem($msg['message'] ?? []);
		if (!is_array($message) || $message === []) {
			return false;
		}

		$chavesConteudo = [
			'conversation',
			'extendedTextMessage',
			'imageMessage',
			'audioMessage',
			'videoMessage',
			'documentMessage',
			'stickerMessage',
			'reactionMessage',
			'buttonsResponseMessage',
			'listResponseMessage',
			'templateButtonReplyMessage',
			'contactMessage',
			'locationMessage',
		];
		foreach ($chavesConteudo as $k) {
			if (isset($message[$k])) {
				return false;
			}
		}

		$chavesIgnorar = [
			'protocolMessage',
			'senderKeyDistributionMessage',
			'assocChildMessage',
			'deviceSentMessage',
		];
		foreach ($chavesIgnorar as $k) {
			if (isset($message[$k])) {
				return true;
			}
		}

		$keys = array_keys($message);
		$keys = array_filter($keys, static function ($k) {
			return $k !== 'messageContextInfo';
		});
		return count($keys) === 0;
	}

	/** Desembrulha ephemeral / viewOnce para checar conteúdo real. */
	private static function desembrulharMensagem($message): array {
		if (!is_array($message)) {
			return [];
		}
		foreach (['ephemeralMessage', 'viewOnceMessage'] as $wrap) {
			if (isset($message[$wrap]['message']) && is_array($message[$wrap]['message'])) {
				return $message[$wrap]['message'];
			}
		}
		return $message;
	}

	private static function salvarMidiaRecebida(int $idAdmin, string $instance, array $msg, string $tipo): ?string {
		if (!in_array($tipo, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
			return null;
		}

		$base64 = self::extrairBase64($msg);
		$mime = self::extrairMime($msg, $tipo);

		if ($base64 === null || $base64 === '') {
			$api = EvolutionApiService::fromEnv();
			$payload = [
				'key'     => $msg['key'] ?? [],
				'message' => $msg['message'] ?? [],
			];
			$res = $api->getBase64FromMediaMessage($instance, $payload);
			if (is_array($res)) {
				$base64 = $res['base64'] ?? $res['data']['base64'] ?? null;
				$mime = $res['mimetype'] ?? $res['mimeType'] ?? $mime;
			}
		}

		if (!$base64) {
			return null;
		}

		$saved = WhatsappMediaStorage::salvarBase64($idAdmin, (string)$base64, $tipo, $mime);
		return $saved['relative'] ?? null;
	}

	private static function extrairBase64(array $msg): ?string {
		$paths = [
			$msg['base64'] ?? null,
			$msg['message']['base64'] ?? null,
			$msg['data']['base64'] ?? null,
			$msg['message']['imageMessage']['base64'] ?? null,
			$msg['message']['audioMessage']['base64'] ?? null,
			$msg['message']['stickerMessage']['base64'] ?? null,
			$msg['message']['videoMessage']['base64'] ?? null,
			$msg['message']['documentMessage']['base64'] ?? null,
		];
		foreach ($paths as $v) {
			if (is_string($v) && $v !== '') {
				return $v;
			}
		}
		return null;
	}

	private static function extrairMime(array $msg, string $tipo): ?string {
		$message = $msg['message'] ?? [];
		$map = [
			'image'    => $message['imageMessage']['mimetype'] ?? null,
			'audio'    => $message['audioMessage']['mimetype'] ?? null,
			'video'    => $message['videoMessage']['mimetype'] ?? null,
			'document' => $message['documentMessage']['mimetype'] ?? null,
			'sticker'  => $message['stickerMessage']['mimetype'] ?? null,
		];
		$mime = $map[$tipo] ?? null;
		return is_string($mime) && $mime !== '' ? $mime : null;
	}

	private static function extrairTexto(array $msg): ?string {
		$message = self::desembrulharMensagem($msg['message'] ?? []);
		if (!is_array($message)) {
			return null;
		}
		if (isset($message['reactionMessage'])) {
			$react = $message['reactionMessage'];
			if (is_array($react)) {
				$text = $react['text'] ?? $react['reaction'] ?? '';
				return is_string($text) ? $text : '';
			}
			return '';
		}
		if (!empty($message['conversation'])) {
			return (string)$message['conversation'];
		}
		if (!empty($message['extendedTextMessage']['text'])) {
			return (string)$message['extendedTextMessage']['text'];
		}
		if (!empty($message['imageMessage']['caption'])) {
			return (string)$message['imageMessage']['caption'];
		}
		if (!empty($message['videoMessage']['caption'])) {
			return (string)$message['videoMessage']['caption'];
		}
		if (!empty($message['documentMessage']['caption'])) {
			return (string)$message['documentMessage']['caption'];
		}
		if (!empty($msg['text']['message'])) {
			return (string)$msg['text']['message'];
		}
		if (!empty($msg['body'])) {
			return (string)$msg['body'];
		}
		return null;
	}

	private static function extrairTipo(array $msg): string {
		$message = self::desembrulharMensagem($msg['message'] ?? []);
		if (!is_array($message)) {
			return 'text';
		}
		if (isset($message['reactionMessage'])) {
			return 'reaction';
		}
		if (isset($message['imageMessage'])) {
			return 'image';
		}
		if (isset($message['audioMessage'])) {
			return 'audio';
		}
		if (isset($message['videoMessage'])) {
			return 'video';
		}
		if (isset($message['documentMessage'])) {
			return 'document';
		}
		if (isset($message['stickerMessage'])) {
			return 'sticker';
		}
		return 'text';
	}
}
