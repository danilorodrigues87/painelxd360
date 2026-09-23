<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use App\Common\Helpers\CryptoHelper;

/**
 * Config do Assistente Telegram nativo por escola (token, allowlist, flags).
 * Fonte da verdade da chave LLM: escola_integracoes.ai_* (Configurações de IA).
 */
class AgentEscolaConfig {

	private static $ultimoErro = null;
	private static $tabelaOk = null;

	public $id_admin;
	public $agent_ativo = 0;
	public $llm_ativo = 0;
	public $llm_provider;
	public $llm_model;
	public $llm_api_key;
	public $telegram_bot_token;
	public $telegram_bot_username;
	public $telegram_chat_id;
	public $telegram_notas;
	public $telegram_update_offset = 0;
	public $telegram_ia_ativo = 1;
	public $atualizado_em;
	public $criado_em;

	public static function getUltimoErro(): ?string {
		return self::$ultimoErro;
	}

	public static function tabelaExiste(): bool {
		if (self::$tabelaOk !== null) {
			return self::$tabelaOk;
		}
		try {
			$row = (new Database())->execute("SHOW TABLES LIKE 'agent_escola_config'")->fetch(\PDO::FETCH_NUM);
			self::$tabelaOk = !empty($row);
		} catch (\Throwable $e) {
			self::$tabelaOk = false;
		}
		return self::$tabelaOk;
	}

	public static function getByIdAdmin(int $idAdmin): ?self {
		if (!self::tabelaExiste() || $idAdmin <= 0) {
			return null;
		}
		$row = (new Database('agent_escola_config'))
			->select('id_admin = '.(int)$idAdmin)
			->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function isAgentAtivo(int $idAdmin): bool {
		$cfg = self::getByIdAdmin($idAdmin);
		return $cfg instanceof self && (int)$cfg->agent_ativo === 1;
	}

	public static function temColunaTelegramIa(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('agent_escola_config'))->execute(
				"SHOW COLUMNS FROM agent_escola_config LIKE 'telegram_ia_ativo'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	/** IA livre ligada? Sem coluna = true (comportamento antigo). */
	public function iaLivreAtiva(): bool {
		if (!self::temColunaTelegramIa()) {
			return true;
		}
		return (int)($this->telegram_ia_ativo ?? 1) === 1;
	}

	public function getLlmApiKeyDescriptografada(): ?string {
		return CryptoHelper::decrypt($this->llm_api_key ?? null);
	}

	public function getTelegramBotTokenDescriptografado(): ?string {
		return CryptoHelper::decrypt($this->telegram_bot_token ?? null);
	}

	public static function maskSecret(?string $plain): string {
		if ($plain === null || $plain === '') {
			return '';
		}
		$len = strlen($plain);
		if ($len <= 8) {
			return '********';
		}
		return substr($plain, 0, 4).str_repeat('*', max(4, $len - 8)).substr($plain, -4);
	}

	/**
	 * Diretor salva LLM + Telegram (não altera agent_ativo).
	 * @param ?string $llmKeyNova null/'' = manter
	 * @param ?string $tgTokenNovo null/'' = manter
	 */
	public function salvarPelaEscola(?string $llmKeyNova, ?string $tgTokenNovo): bool {
		self::$ultimoErro = null;
		if (!self::tabelaExiste()) {
			self::$ultimoErro = 'Execute database/agent_escola_config.sql.';
			return false;
		}
		$idAdmin = (int)$this->id_admin;
		if ($idAdmin <= 0) {
			self::$ultimoErro = 'Escola inválida.';
			return false;
		}

		$dados = [
			'llm_ativo' => (int)$this->llm_ativo,
			'llm_provider' => $this->llm_provider ?: null,
			'llm_model' => $this->llm_model ?: null,
			'telegram_bot_username' => $this->telegram_bot_username
				? ltrim(trim((string)$this->telegram_bot_username), '@')
				: null,
			'telegram_chat_id' => $this->telegram_chat_id ? trim((string)$this->telegram_chat_id) : null,
			'telegram_notas' => $this->telegram_notas ? mb_substr(trim((string)$this->telegram_notas), 0, 255) : null,
		];
		if (self::temColunaTelegramIa()) {
			$dados['telegram_ia_ativo'] = !empty($this->telegram_ia_ativo) ? 1 : 0;
		}

		if ($llmKeyNova !== null && $llmKeyNova !== '') {
			$cript = CryptoHelper::encrypt($llmKeyNova);
			if ($cript === null) {
				self::$ultimoErro = 'Não foi possível criptografar a chave LLM.';
				return false;
			}
			$dados['llm_api_key'] = $cript;
		}

		if ($tgTokenNovo !== null && $tgTokenNovo !== '') {
			$cript = CryptoHelper::encrypt($tgTokenNovo);
			if ($cript === null) {
				self::$ultimoErro = 'Não foi possível criptografar o token do Telegram.';
				return false;
			}
			$dados['telegram_bot_token'] = $cript;
		}

		$db = new Database('agent_escola_config');
		$existente = self::getByIdAdmin($idAdmin);
		if ($existente instanceof self) {
			$db->update('id_admin = '.$idAdmin, $dados);
			return true;
		}

		$dados['id_admin'] = $idAdmin;
		$dados['agent_ativo'] = 0;
		$db->insert($dados);
		return true;
	}

	/** Liga/desliga flag agent_ativo (legado; bot nativo usa llm_ativo). */
	public static function setAgentAtivo(int $idAdmin, bool $ativo): bool {
		self::$ultimoErro = null;
		if (!self::tabelaExiste() || $idAdmin <= 0) {
			self::$ultimoErro = 'Config do Assistente indisponível.';
			return false;
		}
		$db = new Database('agent_escola_config');
		$existente = self::getByIdAdmin($idAdmin);
		if ($existente instanceof self) {
			$db->update('id_admin = '.$idAdmin, ['agent_ativo' => $ativo ? 1 : 0]);
			return true;
		}
		$db->insert([
			'id_admin' => $idAdmin,
			'agent_ativo' => $ativo ? 1 : 0,
			'llm_ativo' => 0,
		]);
		return true;
	}

	/** Payload seguro para a escola (sem plaintext). */
	public function toEscolaPublicArray(): array {
		$llmPlain = $this->getLlmApiKeyDescriptografada();
		$tgPlain = $this->getTelegramBotTokenDescriptografado();
		return [
			'agent_ativo' => (int)$this->agent_ativo === 1,
			'llm_ativo' => (int)$this->llm_ativo === 1,
			'llm_provider' => (string)($this->llm_provider ?? ''),
			'llm_model' => (string)($this->llm_model ?? ''),
			'llm_key_salva' => $llmPlain !== null && $llmPlain !== '',
			'llm_key_mask' => self::maskSecret($llmPlain),
			'telegram_bot_username' => (string)($this->telegram_bot_username ?? ''),
			'telegram_chat_id' => (string)($this->telegram_chat_id ?? ''),
			'telegram_notas' => (string)($this->telegram_notas ?? ''),
			'telegram_token_salvo' => $tgPlain !== null && $tgPlain !== '',
			'telegram_token_mask' => self::maskSecret($tgPlain),
			'telegram_ia_ativo' => $this->iaLivreAtiva(),
			'telegram_ia_coluna_ok' => self::temColunaTelegramIa(),
			'llm_pronto' => (int)$this->llm_ativo === 1 && $llmPlain,
			'telegram_pronto' => $tgPlain !== null && $tgPlain !== '',
		];
	}

	/** Payload Master (inclui revelar secrets sob demanda no controller). */
	public function toMasterResumoArray(): array {
		$base = $this->toEscolaPublicArray();
		$base['id_admin'] = (int)$this->id_admin;
		return $base;
	}
}
