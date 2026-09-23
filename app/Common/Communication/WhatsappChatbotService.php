<?php

namespace App\Common\Communication;

use App\Model\Entity\WhatsappConversa;
use App\Model\Entity\WhatsappMensagem;
use App\Model\Entity\WhatsappNumero;
use App\Model\Entity\WhatsappSetor;
use App\Model\Entity\EscolaIntegracoes;

/**
 * Chatbot simples: menu de setores → fila humana.
 */
class WhatsappChatbotService {

	private static $lastError = null;

	public static function getLastError(): ?string {
		return self::$lastError;
	}

	public static function aoReceberMensagem(WhatsappConversa $conversa, ?string $texto, bool $fromMe): void {
		if ($fromMe) {
			return;
		}
		if (!WhatsappConversa::temColunasChatbot()) {
			return;
		}

		// Conversa já iniciada pelo negócio (celular ou inbox): não mandar menu automático
		if (self::conversaEmAtendimentoHumano($conversa)) {
			return;
		}

		// Fluxos configuráveis (Fase A) — se tratar, não roda o menu de setores
		if (WhatsappFlowRunner::aoReceberMensagem($conversa, $texto, $fromMe)) {
			return;
		}

		if (!WhatsappSetor::tabelaExiste()) {
			return;
		}

		$estado = (string)($conversa->chatbot_estado ?: 'novo');
		$status = (string)($conversa->status ?: '');

		// Atendimento humano ativo: não interferir
		if ($estado === 'humano' && $status !== 'fechada') {
			return;
		}
		if ($conversa->id_atendente && $estado !== 'encerrado' && $status !== 'fechada') {
			return;
		}

		$texto = trim((string)$texto);
		$idAdmin = (int)$conversa->id_admin;
		$menuCfg = WhatsappEscolaService::getConfigMenu($idAdmin);

		// Fora do expediente: responde e não abre fila (exceto se já estava em fila)
		if (in_array($estado, ['novo', '', 'aguardando_setor', 'encerrado'], true) || $status === 'fechada') {
			$exp = WhatsappEscolaService::estaForaExpediente($idAdmin);
			if (!empty($exp['fora'])) {
				self::enviarTexto($conversa, $exp['mensagem']);
				$conversa->atualizar([
					'chatbot_estado' => 'novo',
					'status'         => 'aberta',
				]);
				return;
			}
		}

		WhatsappSetor::garantirPadroes($idAdmin);
		$setores = WhatsappSetor::listarAtivos($idAdmin);

		// Após encerrar: qualquer nova mensagem do cliente reinicia o fluxo
		if ($estado === 'encerrado' || $status === 'fechada') {
			self::reiniciarAtendimento($conversa, $setores, $menuCfg, $texto);
			return;
		}

		if ($estado === 'novo' || $estado === '' || $estado === 'aguardando_setor') {
			if ($estado === 'aguardando_setor') {
				// Imagem/áudio sem texto: só aguarda o número do setor
				if ($texto === '') {
					return;
				}
				$escolha = self::interpretarEscolha($texto, $setores);
				if ($escolha !== null) {
					self::enviarParaSetor($conversa, $escolha);
					return;
				}
				if (self::pedeMenu($texto, $menuCfg)) {
					self::enviarMenu($conversa, $setores, $menuCfg);
					return;
				}
				self::enviarTexto($conversa, $menuCfg['msg_invalida']);
				self::enviarMenu($conversa, $setores, $menuCfg);
				return;
			}

			if (!$menuCfg['menu_ativo']) {
				self::manterSilencioso($conversa, $texto, $setores, $menuCfg);
				return;
			}

			self::enviarMenu($conversa, $setores, $menuCfg);
			return;
		}

		if ($estado === 'fila' && self::pedeMenu($texto, $menuCfg)) {
			$conversa->atualizar([
				'chatbot_estado' => 'aguardando_setor',
				'setor_id'       => null,
				'status'         => 'aberta',
			]);
			self::enviarMenu($conversa, $setores, $menuCfg);
		}
	}

	private static function manterSilencioso(
		WhatsappConversa $conversa,
		string $texto,
		array $setores,
		array $menuCfg
	): void {
		$conversa->atualizar([
			'chatbot_estado' => 'novo',
			'status'         => 'aberta',
		]);

		if ($texto !== '' && !empty($menuCfg['menu_manual_ativo']) && self::pedeMenu($texto, $menuCfg)) {
			self::enviarMenu($conversa, $setores, $menuCfg);
		}
	}

	private static function reiniciarAtendimento(
		WhatsappConversa $conversa,
		array $setores,
		array $menuCfg,
		string $texto = ''
	): void {
		$conversa->atualizar([
			'chatbot_estado' => 'novo',
			'status'         => 'aberta',
			'setor_id'       => null,
			'id_atendente'   => null,
			'assigned_at'    => null,
		]);

		if (!$menuCfg['menu_ativo']) {
			self::manterSilencioso($conversa, $texto, $setores, $menuCfg);
			return;
		}

		self::enviarMenu($conversa, $setores, $menuCfg);
	}

	private static function pedeMenu(string $texto, array $menuCfg): bool {
		$t = mb_strtolower(trim($texto), 'UTF-8');
		if ($t === '') {
			return false;
		}
		return in_array($t, $menuCfg['palavras'], true);
	}

	private static function interpretarEscolha(string $texto, array $setores): ?array {
		$t = trim($texto);
		if (preg_match('/^(\d{1,2})$/', $t, $m)) {
			$idx = (int)$m[1] - 1;
			if (isset($setores[$idx])) {
				return $setores[$idx];
			}
		}

		$tNorm = self::normalizar($t);
		foreach ($setores as $s) {
			if (self::normalizar((string)$s['nome']) === $tNorm
				|| self::normalizar((string)$s['slug']) === $tNorm) {
				return $s;
			}
		}
		return null;
	}

	private static function normalizar(string $s): string {
		$s = mb_strtolower(trim($s), 'UTF-8');
		$s = preg_replace('/\s+/', '', $s) ?? $s;
		return $s;
	}

	private static function enviarMenu(WhatsappConversa $conversa, array $setores, array $menuCfg): void {
		if (!$setores) {
			self::enviarTexto($conversa, 'Olá! No momento não há setores configurados. Aguarde um atendente.');
			$conversa->atualizar(['chatbot_estado' => 'fila', 'status' => 'aberta']);
			return;
		}

		$linhas = [rtrim($menuCfg['titulo'])."\n"];
		foreach ($setores as $i => $s) {
			$linhas[] = '*'.($i + 1).'* - '.$s['nome'];
		}
		if (trim($menuCfg['rodape']) !== '') {
			$linhas[] = "\n".trim($menuCfg['rodape']);
		}

		self::enviarTexto($conversa, implode("\n", $linhas));
		$conversa->atualizar([
			'chatbot_estado' => 'aguardando_setor',
			'status'         => 'aberta',
			'setor_id'       => null,
			'id_atendente'   => null,
		]);
	}

	private static function enviarParaSetor(WhatsappConversa $conversa, array $setor): void {
		$msg = trim((string)($setor['mensagem_fila'] ?? ''));
		if ($msg === '') {
			$msg = 'Você foi direcionado para *'.$setor['nome'].'*. Aguarde, em breve um atendente irá responder.';
		}

		$conversa->atualizar([
			'chatbot_estado' => 'fila',
			'setor_id'       => (int)$setor['id'],
			'status'         => 'aberta',
			'id_atendente'   => null,
		]);

		self::enviarTexto($conversa, $msg);
	}

	public static function enviarTexto(WhatsappConversa $conversa, string $texto): bool {
		self::$lastError = null;
		$idAdmin = (int)$conversa->id_admin;
		$instance = self::instanceDaConversa($conversa);
		if ($instance === '') {
			self::$lastError = 'Instância WhatsApp não encontrada.';
			return false;
		}

		$api = EvolutionApiService::fromEnv();
		$res = $api->sendText($instance, (string)$conversa->telefone, $texto);
		$ok = $res !== null && $api->getLastHttpCode() < 400;
		if (!$ok) {
			self::$lastError = $api->getLastError() ?: 'Falha ao enviar texto.';
			return false;
		}

		WhatsappMensagem::registrar([
			'id_admin'      => $idAdmin,
			'conversa_id'   => (int)$conversa->id,
			'direction'     => 'out',
			'tipo'          => 'text',
			'corpo'         => $texto,
			'wa_message_id' => $res['key']['id'] ?? ($res['message']['key']['id'] ?? null),
			'status'        => 'sent',
		]);

		$conversa->tocarUltimaMensagem();
		return true;
	}

	/**
	 * @param array{relative:string,url:string,mimetype?:?string} $arquivo
	 */
	public static function enviarImagem(WhatsappConversa $conversa, array $arquivo, ?string $caption = null): bool {
		return self::enviarArquivoMidia($conversa, $arquivo, 'image', $caption, null);
	}

	/**
	 * @param array{relative:string,url:string,mimetype?:?string} $arquivo
	 */
	public static function enviarDocumento(WhatsappConversa $conversa, array $arquivo, ?string $caption = null, ?string $fileName = null): bool {
		return self::enviarArquivoMidia($conversa, $arquivo, 'document', $caption, $fileName);
	}

	/**
	 * @param array{relative:string,url:string,mimetype?:?string} $arquivo
	 */
	public static function enviarAudio(WhatsappConversa $conversa, array $arquivo): bool {
		self::$lastError = null;
		$instance = self::instanceDaConversa($conversa);
		if ($instance === '') {
			self::$lastError = 'Instância WhatsApp não encontrada.';
			return false;
		}

		$path = self::caminhoAbsoluto($arquivo);
		if ($path === null) {
			self::$lastError = 'Arquivo de áudio não encontrado no servidor.';
			return false;
		}

		$relative = ltrim((string)($arquivo['relative'] ?? ''), '/');
		$publicUrl = !empty($arquivo['url'])
			? (string)$arquivo['url']
			: WhatsappMediaStorage::urlPublica($relative);

		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$mimeMap = [
			'wav'  => 'audio/wav',
			'mp3'  => 'audio/mpeg',
			'mpeg' => 'audio/mpeg',
			'ogg'  => 'audio/ogg; codecs=opus',
			'opus' => 'audio/ogg; codecs=opus',
			'm4a'  => 'audio/mp4',
			'aac'  => 'audio/aac',
			'webm' => 'audio/webm',
		];
		$mime = $arquivo['mimetype'] ?? ($mimeMap[$ext] ?? 'audio/ogg');

		// Converte para OGG/Opus quando possível (formato nativo de nota de voz)
		$tmpOgg = self::converterAudioParaOggOpus($path);
		$enviarPath = $tmpOgg ?: $path;
		$enviarMime = $tmpOgg ? 'audio/ogg; codecs=opus' : $mime;

		$api = EvolutionApiService::fromEnv();
		$phone = (string)$conversa->telefone;
		$tentativas = [];

		// Status "gravando áudio..." ~2s (se a Evolution aceitar; senão segue o envio)
		$pres = $api->sendPresence($instance, $phone, 'recording');
		if ($pres !== null && $api->getLastHttpCode() < 400) {
			usleep(2000000);
		}

		// Só nota de voz (PTT) — nunca documento
		$res = $api->sendAudio($instance, $phone, $enviarPath, $enviarMime);
		$tentativas[] = 'ptt-file:HTTP '.$api->getLastHttpCode().' '.($api->getLastError() ?: 'ok');
		if ($res !== null && $api->getLastHttpCode() < 400) {
			$api->sendPresence($instance, $phone, 'paused');
			if ($tmpOgg) {
				@unlink($tmpOgg);
			}
			return self::registrarAudioEnviado($conversa, $arquivo, $res);
		}

		if ($publicUrl !== '') {
			$res = $api->sendAudio($instance, $phone, $publicUrl, $enviarMime);
			$tentativas[] = 'ptt-url:HTTP '.$api->getLastHttpCode().' '.($api->getLastError() ?: 'ok');
			if ($res !== null && $api->getLastHttpCode() < 400) {
				$api->sendPresence($instance, $phone, 'paused');
				if ($tmpOgg) {
					@unlink($tmpOgg);
				}
				return self::registrarAudioEnviado($conversa, $arquivo, $res);
			}
		}

		if ($tmpOgg) {
			@unlink($tmpOgg);
		}

		self::$lastError = 'Não foi possível enviar como nota de voz. Detalhes: '.implode(' · ', $tentativas);
		return false;
	}

	/**
	 * Converte áudio para OGG/Opus via ffmpeg (opcional). Retorna caminho temp ou null.
	 */
	private static function converterAudioParaOggOpus(string $path): ?string {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (in_array($ext, ['ogg', 'opus'], true)) {
			return null;
		}

		$ffmpeg = self::binarioFfmpeg();
		if ($ffmpeg === null) {
			return null;
		}

		$out = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
			.DIRECTORY_SEPARATOR.'wa-ptt-'.uniqid('', true).'.ogg';
		$cmd = escapeshellarg($ffmpeg)
			.' -y -i '.escapeshellarg($path)
			.' -c:a libopus -b:a 32k -ar 48000 -ac 1 '
			.escapeshellarg($out).' 2>&1';

		$lines = [];
		$code = 1;
		@exec($cmd, $lines, $code);
		if ($code === 0 && is_file($out) && filesize($out) > 64) {
			return $out;
		}
		if (is_file($out)) {
			@unlink($out);
		}
		return null;
	}

	private static function binarioFfmpeg(): ?string {
		static $cached = false;
		static $bin = null;
		if ($cached) {
			return $bin;
		}
		$cached = true;

		$cmds = stripos(PHP_OS, 'WIN') === 0
			? ['where ffmpeg']
			: ['command -v ffmpeg', 'which ffmpeg'];
		foreach ($cmds as $cmd) {
			$out = [];
			$code = 1;
			@exec($cmd, $out, $code);
			$cand = trim((string)($out[0] ?? ''));
			if ($code === 0 && $cand !== '' && (is_file($cand) || stripos(PHP_OS, 'WIN') === 0)) {
				$bin = $cand;
				return $bin;
			}
		}
		return null;
	}

	/** @param array{relative?:string} $arquivo */
	private static function registrarAudioEnviado(WhatsappConversa $conversa, array $arquivo, array $res): bool {
		WhatsappMensagem::registrar([
			'id_admin'      => (int)$conversa->id_admin,
			'conversa_id'   => (int)$conversa->id,
			'direction'     => 'out',
			'tipo'          => 'audio',
			'corpo'         => null,
			'media_url'     => $arquivo['relative'] ?? null,
			'wa_message_id' => $res['key']['id'] ?? ($res['message']['key']['id'] ?? null),
			'status'        => 'sent',
		]);
		$conversa->tocarUltimaMensagem();
		return true;
	}

	/**
	 * @param array{relative:string,url:string,mimetype?:?string} $arquivo
	 */
	private static function enviarArquivoMidia(
		WhatsappConversa $conversa,
		array $arquivo,
		string $tipo,
		?string $caption,
		?string $fileName
	): bool {
		self::$lastError = null;
		$instance = self::instanceDaConversa($conversa);
		if ($instance === '') {
			self::$lastError = 'Instância WhatsApp não encontrada.';
			return false;
		}

		$path = self::caminhoAbsoluto($arquivo);
		if ($path === null) {
			self::$lastError = 'Arquivo de mídia não encontrado no servidor.';
			return false;
		}

		$mime = $arquivo['mimetype'] ?? null;
		if (!$fileName) {
			$fileName = basename((string)($arquivo['relative'] ?? $path));
		}

		$api = EvolutionApiService::fromEnv();
		$res = $api->sendMedia(
			$instance,
			(string)$conversa->telefone,
			$path,
			$tipo,
			$mime,
			$caption,
			$fileName
		);
		$ok = $res !== null && $api->getLastHttpCode() < 400;
		if (!$ok) {
			self::$lastError = $api->getLastError() ?: ('Falha ao enviar '.$tipo.'.');
			return false;
		}

		WhatsappMensagem::registrar([
			'id_admin'      => (int)$conversa->id_admin,
			'conversa_id'   => (int)$conversa->id,
			'direction'     => 'out',
			'tipo'          => $tipo,
			'corpo'         => $caption ?: ($tipo === 'document' ? $fileName : null),
			'media_url'     => $arquivo['relative'] ?? null,
			'wa_message_id' => $res['key']['id'] ?? ($res['message']['key']['id'] ?? null),
			'status'        => 'sent',
		]);
		$conversa->tocarUltimaMensagem();
		return true;
	}

	/** @param array{relative?:string} $arquivo */
	private static function caminhoAbsoluto(array $arquivo): ?string {
		$root = rtrim(str_replace('\\', '/', realpath(__DIR__.'/../../../') ?: (__DIR__.'/../../..')), '/');
		$relative = ltrim((string)($arquivo['relative'] ?? ''), '/');
		if ($relative === '') {
			return null;
		}
		$path = $root.'/'.$relative;
		return is_file($path) ? $path : null;
	}

	public static function instanceDaConversa(WhatsappConversa $conversa): string {
		if (!empty($conversa->numero_id) && WhatsappNumero::tabelaExiste()) {
			$row = (new \App\Model\Db\Database('whatsapp_numeros'))
				->select('id = '.(int)$conversa->numero_id, null, 1)
				->fetch(\PDO::FETCH_ASSOC);
			if (!empty($row['evolution_instance'])) {
				return (string)$row['evolution_instance'];
			}
		}

		$num = WhatsappNumero::getDefault((int)$conversa->id_admin);
		if ($num && !empty($num->evolution_instance)) {
			return (string)$num->evolution_instance;
		}

		$int = EscolaIntegracoes::getByIdAdmin((int)$conversa->id_admin);
		if ($int && !empty($int->evolution_instance)) {
			return (string)$int->evolution_instance;
		}

		return EvolutionApiService::nomeInstancia((int)$conversa->id_admin);
	}

	/**
	 * Mensagem enviada pelo celular/WhatsApp Web (fromMe) — tratar como atendimento humano
	 * para não disparar menu quando o cliente responder.
	 */
	public static function marcarHandoffHumanoExterno(WhatsappConversa $conversa): void {
		if (!WhatsappConversa::temColunasChatbot()) {
			return;
		}
		if (self::conversaEmAtendimentoHumano($conversa)) {
			return;
		}

		if (\App\Model\Entity\WhatsappFluxoSessao::tabelaExiste()) {
			\App\Model\Entity\WhatsappFluxoSessao::apagarPorConversa((int)$conversa->id);
		}

		$conversa->atualizar([
			'chatbot_estado' => 'humano',
			'status'         => 'em_atendimento',
		]);
	}

	private static function conversaEmAtendimentoHumano(WhatsappConversa $conversa): bool {
		$estado = (string)($conversa->chatbot_estado ?: '');
		$status = (string)($conversa->status ?: '');
		if ($estado === 'humano' && $status !== 'fechada') {
			return true;
		}
		return (int)($conversa->id_atendente ?? 0) > 0
			&& $estado !== 'encerrado'
			&& $status !== 'fechada';
	}
}
