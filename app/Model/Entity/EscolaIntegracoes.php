<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use App\Common\Helpers\CryptoHelper;

class EscolaIntegracoes {

	private static $ultimoErro = null;

	public static function getUltimoErro(): ?string {
		return self::$ultimoErro;
	}

	public $id_admin;
	public $smtp_host;
	public $smtp_port = 587;
	public $smtp_user;
	public $smtp_pass;
	public $smtp_from_email;
	public $smtp_from_name;
	public $smtp_encryption = 'tls';
	public $smtp_ativo = 0;
	public $email_delay_segundos = 3;
	public $email_max_hora = 80;
	public $cobranca_ativo = 0;
	public $cobranca_dias_antes = '3,5';
	public $cobranca_aviso_vencimento = 1;
	public $cobranca_dias_depois = '1,3,7';
	public $cobranca_enviar_responsavel = 1;
	public $cobranca_whatsapp_ativo = 0;
	public $cobranca_assunto_antes;
	public $cobranca_assunto_vencimento;
	public $cobranca_assunto_atraso;
	public $cobranca_msg_antes;
	public $cobranca_msg_vencimento;
	public $cobranca_msg_atraso;
	public $aniversario_ativo = 0;
	public $aniversario_apenas_matriculados = 1;
	public $aniversario_whatsapp_ativo = 0;
	public $aniversario_assunto;
	public $aniversario_mensagem;
	public $crm_wa_automacao_ativo = 1;
	public $crm_wa_enviar_novo = 1;
	public $crm_wa_enviar_em_atendimento = 1;
	public $crm_wa_enviar_matriculado = 1;
	public $crm_wa_msg_novo;
	public $crm_wa_msg_em_atendimento;
	public $crm_wa_msg_matriculado;
	public $evolution_instance;
	public $evolution_status = 'disconnected';
	public $evolution_ativo = 0;
	public $evolution_numero;
	public $whatsapp_delay_segundos = 60;
	public $whatsapp_max_hora = 20;
	public $whatsapp_grupo_delay_segundos = 600;
	public $whatsapp_variar_texto = 0;
	public $whatsapp_horario_inicio;
	public $whatsapp_horario_fim;
	public $whatsapp_dias = '1,2,3,4,5';
	public $whatsapp_msg_fora;
	public $campanha_respeitar_expediente = 0;
	public $whatsapp_menu_ativo = 1;
	public $whatsapp_menu_manual_ativo = 1;
	public $whatsapp_menu_titulo;
	public $whatsapp_menu_rodape;
	public $whatsapp_menu_msg_invalida;
	public $whatsapp_menu_palavras;
	public $diario_wa_lembrete_mensagem;
	public $diario_wa_faltas_mensagem;
	/** Quando true, o salvar() também grava campos Evolution/WhatsApp. */
	public $touchEvolution = false;
	public $mp_ativo = 0;
	public $mp_access_token;
	public $mp_webhook_secret;
	public $mp_webhook_token;
	public $ai_ativo = 0;
	public $ai_provider;
	public $ai_api_key;
	public $ai_model;
	public $bunny_ativo = 0;
	public $bunny_library_id;
	public $bunny_cdn_hostname;
	public $bunny_api_key;
	public $bunny_token_key;
	public $meta_fb_ativo = 0;
	public $meta_ig_ativo = 0;
	public $meta_page_id;
	public $meta_page_name;
	public $meta_ig_user_id;
	public $meta_ig_username;
	public $meta_page_token;
	public $meta_token_expires_at;
	public $meta_webhook_token;
	public $meta_conectado_em;
	public $meta_auto_ativo = 0;
	public $updated_at;

	public static function temColunasCobranca(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'cobranca_ativo'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasAniversario(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'aniversario_ativo'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasDiarioWhatsapp(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'diario_wa_lembrete_mensagem'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public function salvarDiarioWhatsapp(): bool {
		if (!self::temColunasDiarioWhatsapp()) {
			return false;
		}
		$dados = [
			'diario_wa_lembrete_mensagem' => $this->diario_wa_lembrete_mensagem,
			'diario_wa_faltas_mensagem'   => $this->diario_wa_faltas_mensagem,
		];
		$existente = self::getByIdAdmin((int)$this->id_admin);
		$db = new Database('escola_integracoes');
		if ($existente instanceof self) {
			return (bool)$db->update('id_admin = '.(int)$this->id_admin, $dados);
		}
		$dados['id_admin'] = (int)$this->id_admin;
		return (bool)$db->insert($dados);
	}

	public static function temColunasHorarioWhatsapp(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'whatsapp_horario_inicio'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasWhatsappAutomacao(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'cobranca_whatsapp_ativo'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasCrmAutomacao(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'crm_wa_automacao_ativo'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	/** Grava só campos de automação CRM (upsert mínimo). */
	public static function salvarCrmAutomacao(int $idAdmin, array $dados): bool {
		if (!self::temColunasCrmAutomacao() || $idAdmin <= 0) {
			return false;
		}

		$permitidos = [
			'crm_wa_automacao_ativo',
			'crm_wa_enviar_novo',
			'crm_wa_enviar_em_atendimento',
			'crm_wa_enviar_matriculado',
			'crm_wa_msg_novo',
			'crm_wa_msg_em_atendimento',
			'crm_wa_msg_matriculado',
		];
		$gravar = [];
		foreach ($permitidos as $col) {
			if (array_key_exists($col, $dados)) {
				$gravar[$col] = $dados[$col];
			}
		}
		if ($gravar === []) {
			return false;
		}

		$existente = self::getByIdAdmin($idAdmin);
		$db = new Database('escola_integracoes');
		if ($existente instanceof self) {
			return (bool)$db->update('id_admin = '.(int)$idAdmin, $gravar);
		}

		$gravar['id_admin'] = (int)$idAdmin;
		return (bool)$db->insert($gravar);
	}

	public static function temColunasEvolution(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'evolution_instance'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunaWhatsappGrupoDelay(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'whatsapp_grupo_delay_segundos'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunaWhatsappVariarTexto(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'whatsapp_variar_texto'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunaCampanhaRespeitarExpediente(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'campanha_respeitar_expediente'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasMenuWhatsapp(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'whatsapp_menu_ativo'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasMercadoPago(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'mp_ativo'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasAi(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'ai_ativo'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasBunny(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'bunny_ativo'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function tabelaExiste(): bool {
		try {
			$host = getenv('DB_HOST');
			$name = getenv('DB_NAME');
			$user = getenv('DB_USER');
			$pass = getenv('DB_PASS');
			$pdo = new \PDO(
				'mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',
				$user,
				$pass,
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW TABLES LIKE 'escola_integracoes'");
			return $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public static function getByIdAdmin(int $idAdmin) {
		if (!self::tabelaExiste()) {
			return null;
		}
		return self::get('id_admin = '.(int)$idAdmin)->fetchObject(self::class);
	}

	public static function get($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database('escola_integracoes'))->select($where, $order, $limit, $fields);
	}

	public function getSenhaDescriptografada(): ?string {
		return CryptoHelper::decrypt($this->smtp_pass ?? null);
	}

	public function getMpAccessTokenDescriptografado(): ?string {
		return CryptoHelper::decrypt($this->mp_access_token ?? null);
	}

	public function getMpWebhookSecretDescriptografado(): ?string {
		return CryptoHelper::decrypt($this->mp_webhook_secret ?? null);
	}

	public function temMercadoPagoAtivo(): bool {
		return self::temColunasMercadoPago()
			&& (int)$this->mp_ativo === 1
			&& !empty($this->getMpAccessTokenDescriptografado());
	}

	/** Garante linha + token de webhook. */
	public static function garantirRegistroMp(int $idAdmin): ?self {
		if (!self::temColunasMercadoPago() || $idAdmin <= 0) {
			return null;
		}
		$cfg = self::getByIdAdmin($idAdmin);
		if (!$cfg instanceof self) {
			$cfg = new self;
			$cfg->id_admin = $idAdmin;
			$cfg->mp_ativo = 0;
			$cfg->mp_webhook_token = bin2hex(random_bytes(24));
			if (!$cfg->salvarMercadoPago(null, null)) {
				return null;
			}
			$cfg = self::getByIdAdmin($idAdmin);
		}
		if ($cfg instanceof self && empty($cfg->mp_webhook_token)) {
			$cfg->mp_webhook_token = bin2hex(random_bytes(24));
			$cfg->salvarMercadoPago(null, null);
			$cfg = self::getByIdAdmin($idAdmin);
		}
		return $cfg instanceof self ? $cfg : null;
	}

	/**
	 * Atualiza só campos Mercado Pago.
	 * @param ?string $accessTokenNovo null = manter; string = criptografar e gravar
	 * @param ?string $webhookSecretNovo null = manter
	 */
	public function salvarMercadoPago(?string $accessTokenNovo, ?string $webhookSecretNovo): bool {
		self::$ultimoErro = null;
		if (!self::temColunasMercadoPago()) {
			self::$ultimoErro = 'Colunas do Mercado Pago ausentes.';
			return false;
		}

		$dados = [
			'mp_ativo' => (int)$this->mp_ativo,
		];
		if (!empty($this->mp_webhook_token)) {
			$dados['mp_webhook_token'] = $this->mp_webhook_token;
		}

		if ($accessTokenNovo !== null && $accessTokenNovo !== '') {
			$cript = CryptoHelper::encrypt($accessTokenNovo);
			if ($cript === null) {
				self::$ultimoErro = 'Não foi possível criptografar o Access Token.';
				return false;
			}
			$dados['mp_access_token'] = $cript;
		}

		if ($webhookSecretNovo !== null && $webhookSecretNovo !== '') {
			$cript = CryptoHelper::encrypt($webhookSecretNovo);
			if ($cript === null) {
				self::$ultimoErro = 'Não foi possível criptografar o webhook secret.';
				return false;
			}
			$dados['mp_webhook_secret'] = $cript;
		}

		$existente = self::getByIdAdmin((int)$this->id_admin);
		$db = new Database('escola_integracoes');

		if ($existente instanceof self) {
			$db->update('id_admin = '.(int)$this->id_admin, $dados);
			return true;
		}

		$dados['id_admin'] = (int)$this->id_admin;
		$dados['smtp_pass'] = null;
		$dados['smtp_port'] = 587;
		$dados['smtp_encryption'] = 'tls';
		$dados['smtp_ativo'] = 0;
		if (empty($dados['mp_webhook_token'])) {
			$dados['mp_webhook_token'] = bin2hex(random_bytes(24));
			$this->mp_webhook_token = $dados['mp_webhook_token'];
		}
		$db->insert($dados);
		return true;
	}

	/**
	 * Atualiza só campos de IA pedagógica.
	 * @param ?string $apiKeyNova null = manter; string = criptografar
	 */
	public function salvarAi(?string $apiKeyNova): bool {
		self::$ultimoErro = null;
		if (!self::temColunasAi()) {
			self::$ultimoErro = 'Colunas de IA ausentes. Execute database/lms_ead.sql.';
			return false;
		}

		$dados = [
			'ai_ativo' => (int)$this->ai_ativo,
			'ai_provider' => $this->ai_provider ?: null,
			'ai_model' => $this->ai_model ?: null,
		];

		if ($apiKeyNova !== null && $apiKeyNova !== '') {
			$cript = CryptoHelper::encrypt($apiKeyNova);
			if ($cript === null) {
				self::$ultimoErro = 'Não foi possível criptografar a API key.';
				return false;
			}
			$dados['ai_api_key'] = $cript;
		}

		$existente = self::getByIdAdmin((int)$this->id_admin);
		$db = new Database('escola_integracoes');

		if ($existente instanceof self) {
			$db->update('id_admin = '.(int)$this->id_admin, $dados);
			return true;
		}

		$dados['id_admin'] = (int)$this->id_admin;
		$dados['smtp_pass'] = null;
		$dados['smtp_port'] = 587;
		$dados['smtp_encryption'] = 'tls';
		$dados['smtp_ativo'] = 0;
		$db->insert($dados);
		return true;
	}

	public function getAiApiKeyDescriptografada(): ?string {
		return CryptoHelper::decrypt($this->ai_api_key ?? null);
	}

	public function temAiAtivo(): bool {
		return self::temColunasAi()
			&& (int)$this->ai_ativo === 1
			&& !empty($this->getAiApiKeyDescriptografada());
	}

	/**
	 * @param ?string $apiKeyNova null = manter
	 * @param ?string $tokenKeyNova null = manter
	 */
	public function salvarBunny(?string $apiKeyNova, ?string $tokenKeyNova): bool {
		self::$ultimoErro = null;
		if (!self::temColunasBunny()) {
			self::$ultimoErro = 'Colunas Bunny ausentes. Execute database/escola_integracoes_bunny.sql.';
			return false;
		}

		$dados = [
			'bunny_ativo' => (int)$this->bunny_ativo,
			'bunny_library_id' => $this->bunny_library_id ?: null,
			'bunny_cdn_hostname' => $this->bunny_cdn_hostname ?: null,
		];

		if ($apiKeyNova !== null && $apiKeyNova !== '') {
			$cript = CryptoHelper::encrypt($apiKeyNova);
			if ($cript === null) {
				self::$ultimoErro = 'Não foi possível criptografar a AccessKey.';
				return false;
			}
			$dados['bunny_api_key'] = $cript;
		}
		if ($tokenKeyNova !== null && $tokenKeyNova !== '') {
			$cript = CryptoHelper::encrypt($tokenKeyNova);
			if ($cript === null) {
				self::$ultimoErro = 'Não foi possível criptografar o Token Key.';
				return false;
			}
			$dados['bunny_token_key'] = $cript;
		}

		$existente = self::getByIdAdmin((int)$this->id_admin);
		$db = new Database('escola_integracoes');

		if ($existente instanceof self) {
			$db->update('id_admin = '.(int)$this->id_admin, $dados);
			return true;
		}

		$dados['id_admin'] = (int)$this->id_admin;
		$dados['smtp_pass'] = null;
		$dados['smtp_port'] = 587;
		$dados['smtp_encryption'] = 'tls';
		$dados['smtp_ativo'] = 0;
		$db->insert($dados);
		return true;
	}

	public function getBunnyApiKeyDescriptografada(): ?string {
		return CryptoHelper::decrypt($this->bunny_api_key ?? null);
	}

	public function getBunnyTokenKeyDescriptografada(): ?string {
		return CryptoHelper::decrypt($this->bunny_token_key ?? null);
	}

	public function temBunnyAtivo(): bool {
		return self::temColunasBunny()
			&& (int)$this->bunny_ativo === 1
			&& trim((string)($this->bunny_library_id ?? '')) !== ''
			&& trim((string)($this->bunny_cdn_hostname ?? '')) !== ''
			&& !empty($this->getBunnyApiKeyDescriptografada())
			&& !empty($this->getBunnyTokenKeyDescriptografada());
	}

	public static function temColunasMeta(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW COLUMNS FROM escola_integracoes LIKE 'meta_page_token'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunaMetaAuto(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('escola_integracoes'))->execute(
				"SHOW COLUMNS FROM escola_integracoes LIKE 'meta_auto_ativo'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	/** Localiza escola pelo Page ID ou IG User ID (webhook global). */
	public static function getByMetaPageOrIg(?string $pageId, ?string $igId): ?self {
		if (!self::temColunasMeta()) {
			return null;
		}
		$pageId = trim((string)$pageId);
		$igId = trim((string)$igId);
		$parts = [];
		if ($pageId !== '') {
			$parts[] = 'meta_page_id = "'.addslashes($pageId).'"';
		}
		if ($igId !== '') {
			$parts[] = 'meta_ig_user_id = "'.addslashes($igId).'"';
		}
		if (!$parts) {
			return null;
		}
		$row = (new Database('escola_integracoes'))->select(
			'('.implode(' OR ', $parts).')',
			null,
			1
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/**
	 * @param ?string $pageTokenNovo null = manter
	 */
	public function salvarMeta(?string $pageTokenNovo): bool {
		self::$ultimoErro = null;
		if (!self::temColunasMeta()) {
			self::$ultimoErro = 'Colunas Meta ausentes. Execute database/escola_integracoes_meta.sql.';
			return false;
		}

		if (empty($this->meta_webhook_token)) {
			$this->meta_webhook_token = bin2hex(random_bytes(16));
		}

		$dados = [
			'meta_fb_ativo' => (int)$this->meta_fb_ativo,
			'meta_ig_ativo' => (int)$this->meta_ig_ativo,
			'meta_page_id' => $this->meta_page_id ?: null,
			'meta_page_name' => $this->meta_page_name ?: null,
			'meta_ig_user_id' => $this->meta_ig_user_id ?: null,
			'meta_ig_username' => $this->meta_ig_username ?: null,
			'meta_token_expires_at' => $this->meta_token_expires_at ?: null,
			'meta_webhook_token' => $this->meta_webhook_token ?: null,
			'meta_conectado_em' => $this->meta_conectado_em ?: null,
		];
		if (self::temColunaMetaAuto()) {
			$dados['meta_auto_ativo'] = (int)$this->meta_auto_ativo ? 1 : 0;
		}

		if ($pageTokenNovo !== null && $pageTokenNovo !== '') {
			$cript = CryptoHelper::encrypt($pageTokenNovo);
			if ($cript === null) {
				self::$ultimoErro = 'Não foi possível criptografar o Page Token.';
				return false;
			}
			$dados['meta_page_token'] = $cript;
		}

		$existente = self::getByIdAdmin((int)$this->id_admin);
		$db = new Database('escola_integracoes');

		if ($existente instanceof self) {
			$db->update('id_admin = '.(int)$this->id_admin, $dados);
			return true;
		}

		$dados['id_admin'] = (int)$this->id_admin;
		$dados['smtp_pass'] = null;
		$dados['smtp_port'] = 587;
		$dados['smtp_encryption'] = 'tls';
		$dados['smtp_ativo'] = 0;
		$db->insert($dados);
		return true;
	}

	public function getMetaPageTokenDescriptografada(): ?string {
		return CryptoHelper::decrypt($this->meta_page_token ?? null);
	}

	public function temMetaPronto(): bool {
		if (!self::temColunasMeta()) {
			return false;
		}
		$token = $this->getMetaPageTokenDescriptografada();
		$pageOk = trim((string)($this->meta_page_id ?? '')) !== '' && $token;
		$fb = (int)$this->meta_fb_ativo === 1 && $pageOk;
		$ig = (int)$this->meta_ig_ativo === 1 && $pageOk && trim((string)($this->meta_ig_user_id ?? '')) !== '';
		return $fb || $ig;
	}

	public function salvar(): bool {
		self::$ultimoErro = null;

		$dados = [
			'smtp_host'            => $this->smtp_host,
			'smtp_port'            => (int)$this->smtp_port,
			'smtp_user'            => $this->smtp_user,
			'smtp_from_email'      => $this->smtp_from_email,
			'smtp_from_name'       => $this->smtp_from_name,
			'smtp_encryption'      => $this->smtp_encryption,
			'smtp_ativo'           => (int)$this->smtp_ativo,
			'email_delay_segundos' => (int)$this->email_delay_segundos,
			'email_max_hora'       => (int)$this->email_max_hora,
		];

		if ($this->smtp_pass !== null && $this->smtp_pass !== '') {
			$criptografada = CryptoHelper::encrypt($this->smtp_pass);
			if ($criptografada === null) {
				self::$ultimoErro = 'Não foi possível criptografar a senha SMTP.';
				return false;
			}
			$dados['smtp_pass'] = $criptografada;
		}

		if (self::temColunasCobranca()) {
			$dados['cobranca_ativo'] = (int)$this->cobranca_ativo;
			$dados['cobranca_dias_antes'] = $this->cobranca_dias_antes;
			$dados['cobranca_aviso_vencimento'] = (int)$this->cobranca_aviso_vencimento;
			$dados['cobranca_dias_depois'] = $this->cobranca_dias_depois;
			$dados['cobranca_enviar_responsavel'] = (int)$this->cobranca_enviar_responsavel;
			$dados['cobranca_assunto_antes'] = $this->cobranca_assunto_antes;
			$dados['cobranca_assunto_vencimento'] = $this->cobranca_assunto_vencimento;
			$dados['cobranca_assunto_atraso'] = $this->cobranca_assunto_atraso;
			$dados['cobranca_msg_antes'] = $this->cobranca_msg_antes;
			$dados['cobranca_msg_vencimento'] = $this->cobranca_msg_vencimento;
			$dados['cobranca_msg_atraso'] = $this->cobranca_msg_atraso;
			if (self::temColunasWhatsappAutomacao()) {
				$dados['cobranca_whatsapp_ativo'] = (int)$this->cobranca_whatsapp_ativo;
			}
		}

		if (self::temColunasAniversario()) {
			$dados['aniversario_ativo'] = (int)$this->aniversario_ativo;
			$dados['aniversario_apenas_matriculados'] = (int)$this->aniversario_apenas_matriculados;
			$dados['aniversario_assunto'] = $this->aniversario_assunto;
			$dados['aniversario_mensagem'] = $this->aniversario_mensagem;
			if (self::temColunasWhatsappAutomacao()) {
				$dados['aniversario_whatsapp_ativo'] = (int)$this->aniversario_whatsapp_ativo;
			}
		}

		if (self::temColunasEvolution() && $this->touchEvolution) {
			$dados['evolution_instance'] = $this->evolution_instance;
			$dados['evolution_status'] = $this->evolution_status ?: 'disconnected';
			$dados['evolution_ativo'] = (int)$this->evolution_ativo;
			$dados['evolution_numero'] = $this->evolution_numero;
			$dados['whatsapp_delay_segundos'] = max(30, (int)($this->whatsapp_delay_segundos ?? 60));
			$dados['whatsapp_max_hora'] = max(1, (int)($this->whatsapp_max_hora ?? 20));
			if (self::temColunaWhatsappGrupoDelay()) {
				$dados['whatsapp_grupo_delay_segundos'] = max(60, (int)($this->whatsapp_grupo_delay_segundos ?? 600));
			}
			if (self::temColunasHorarioWhatsapp()) {
				$dados['whatsapp_horario_inicio'] = $this->whatsapp_horario_inicio ?: null;
				$dados['whatsapp_horario_fim'] = $this->whatsapp_horario_fim ?: null;
				$dados['whatsapp_dias'] = $this->whatsapp_dias ?: '1,2,3,4,5';
				$dados['whatsapp_msg_fora'] = $this->whatsapp_msg_fora;
			}
			if (self::temColunaCampanhaRespeitarExpediente()) {
				$dados['campanha_respeitar_expediente'] = !empty($this->campanha_respeitar_expediente) ? 1 : 0;
			}
			if (self::temColunasMenuWhatsapp()) {
				$dados['whatsapp_menu_ativo'] = !empty($this->whatsapp_menu_ativo) ? 1 : 0;
				$dados['whatsapp_menu_manual_ativo'] = !empty($this->whatsapp_menu_manual_ativo) ? 1 : 0;
				$dados['whatsapp_menu_titulo'] = $this->whatsapp_menu_titulo;
				$dados['whatsapp_menu_rodape'] = $this->whatsapp_menu_rodape;
				$dados['whatsapp_menu_msg_invalida'] = $this->whatsapp_menu_msg_invalida;
				$dados['whatsapp_menu_palavras'] = $this->whatsapp_menu_palavras;
			}
		}

		if (self::temColunaWhatsappVariarTexto()) {
			$dados['whatsapp_variar_texto'] = !empty($this->whatsapp_variar_texto) ? 1 : 0;
		}

		$existente = self::getByIdAdmin((int)$this->id_admin);
		$db = new Database('escola_integracoes');

		if ($existente instanceof self) {
			$db->update('id_admin = '.(int)$this->id_admin, $dados);
			return true;
		}

		$dados['id_admin'] = (int)$this->id_admin;
		if (!isset($dados['smtp_pass'])) {
			$dados['smtp_pass'] = null;
		}

		$db->insert($dados);
		return true;
	}

	public function temSmtpConfigurado(): bool {
		return (int)$this->smtp_ativo === 1
			&& !empty($this->smtp_host)
			&& !empty($this->smtp_user)
			&& !empty($this->smtp_from_email)
			&& !empty($this->getSenhaDescriptografada());
	}
}
