<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use App\Common\Helpers\CryptoHelper;

/**
 * Credenciais Bunny globais (Master) — Stream + Storage.
 * Uma linha id=1 para toda a plataforma.
 */
class PlataformaBunny {

	public $id = 1;
	public $stream_ativo = 0;
	public $stream_library_id;
	public $stream_cdn_hostname;
	public $stream_api_key;
	public $stream_token_key;
	public $storage_ativo = 0;
	public $storage_zone;
	public $storage_access_key;
	public $storage_endpoint = 'storage.bunnycdn.com';
	public $storage_cdn_hostname;
	public $storage_token_key;
	public $atualizado_em;

	private static $ultimoErro = '';

	public static function getUltimoErro(): string {
		return self::$ultimoErro;
	}

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'plataforma_bunny'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function get(): self {
		$row = new self();
		if (!self::tabelaExiste()) {
			return $row;
		}
		try {
			$found = (new Database('plataforma_bunny'))->select('id = 1', null, 1)->fetchObject(self::class);
			if ($found instanceof self) {
				return $found;
			}
			(new Database('plataforma_bunny'))->insert(['id' => 1]);
		} catch (\Throwable $e) {
			self::$ultimoErro = $e->getMessage();
		}
		return $row;
	}

	public function getStreamApiKey(): ?string {
		return CryptoHelper::decrypt($this->stream_api_key ?? null);
	}

	public function getStreamTokenKey(): ?string {
		return CryptoHelper::decrypt($this->stream_token_key ?? null);
	}

	public function getStorageAccessKey(): ?string {
		return CryptoHelper::decrypt($this->storage_access_key ?? null);
	}

	public function getStorageTokenKey(): ?string {
		return CryptoHelper::decrypt($this->storage_token_key ?? null);
	}

	public function streamPronto(): bool {
		return $this->streamDiagnostico() === null;
	}

	/**
	 * Null se Stream estiver pronto; senão texto do que falta.
	 */
	public function streamDiagnostico(): ?string {
		if (!self::tabelaExiste()) {
			return 'Execute database/plataforma_bunny.sql no phpMyAdmin.';
		}
		if ((int)$this->stream_ativo !== 1) {
			return 'Ative o switch "Ativar Stream" no Master → Bunny.';
		}
		if (trim((string)($this->stream_library_id ?? '')) === '') {
			return 'Informe o Video Library ID.';
		}
		if (trim((string)($this->stream_cdn_hostname ?? '')) === '') {
			return 'Informe o CDN Hostname do Stream.';
		}
		if (!$this->getStreamApiKey()) {
			return 'Informe (ou salve de novo) a API AccessKey do Stream. Confira APP_KEY/SYSTEM_TOKEN no .env.';
		}
		if (!$this->getStreamTokenKey()) {
			return 'Informe a Token Authentication Key (Library → Security).';
		}
		return null;
	}

	public function storagePronto(): bool {
		return $this->storageDiagnostico() === null;
	}

	public function storageDiagnostico(): ?string {
		if (!self::tabelaExiste()) {
			return 'Execute database/plataforma_bunny.sql no phpMyAdmin.';
		}
		if ((int)$this->storage_ativo !== 1) {
			return 'Ative o switch "Ativar Storage" no Master → Bunny.';
		}
		if (trim((string)($this->storage_zone ?? '')) === '') {
			return 'Informe o Storage Zone name.';
		}
		if (trim((string)($this->storage_cdn_hostname ?? '')) === '') {
			return 'Informe o Pull Zone CDN Hostname.';
		}
		if (!$this->getStorageAccessKey()) {
			return 'Informe (ou salve de novo) a Access Key do Storage (API/HTTP). Confira APP_KEY/SYSTEM_TOKEN no .env.';
		}
		return null;
	}

	/**
	 * @param array{
	 *   stream_ativo?:int|bool,
	 *   stream_library_id?:?string,
	 *   stream_cdn_hostname?:?string,
	 *   stream_api_key?:?string,
	 *   stream_token_key?:?string,
	 *   storage_ativo?:int|bool,
	 *   storage_zone?:?string,
	 *   storage_access_key?:?string,
	 *   storage_endpoint?:?string,
	 *   storage_cdn_hostname?:?string,
	 *   storage_token_key?:?string
	 * } $in
	 */
	public function salvar(array $in): bool {
		self::$ultimoErro = '';
		if (!self::tabelaExiste()) {
			self::$ultimoErro = 'Execute database/plataforma_bunny.sql no phpMyAdmin.';
			return false;
		}

		$hostStream = trim((string)($in['stream_cdn_hostname'] ?? $this->stream_cdn_hostname ?? ''));
		$hostStream = preg_replace('#^https?://#i', '', $hostStream);
		$hostStream = rtrim((string)$hostStream, '/');

		$hostStorage = trim((string)($in['storage_cdn_hostname'] ?? $this->storage_cdn_hostname ?? ''));
		$hostStorage = preg_replace('#^https?://#i', '', $hostStorage);
		$hostStorage = rtrim((string)$hostStorage, '/');

		$endpoint = trim((string)($in['storage_endpoint'] ?? $this->storage_endpoint ?? 'storage.bunnycdn.com'));
		$endpoint = preg_replace('#^https?://#i', '', $endpoint);
		$endpoint = rtrim((string)$endpoint, '/');
		if ($endpoint === '') {
			$endpoint = 'storage.bunnycdn.com';
		}

		$dados = [
			'stream_ativo' => !empty($in['stream_ativo']) ? 1 : 0,
			'stream_library_id' => trim((string)($in['stream_library_id'] ?? '')) ?: null,
			'stream_cdn_hostname' => $hostStream !== '' ? $hostStream : null,
			'storage_ativo' => !empty($in['storage_ativo']) ? 1 : 0,
			'storage_zone' => trim((string)($in['storage_zone'] ?? '')) ?: null,
			'storage_endpoint' => $endpoint,
			'storage_cdn_hostname' => $hostStorage !== '' ? $hostStorage : null,
		];

		$apiNova = trim((string)($in['stream_api_key'] ?? ''));
		$tokenNova = trim((string)($in['stream_token_key'] ?? ''));
		$storageKeyNova = trim((string)($in['storage_access_key'] ?? ''));
		$storageTokenNova = trim((string)($in['storage_token_key'] ?? ''));

		if ($apiNova !== '') {
			$cript = CryptoHelper::encrypt($apiNova);
			if (!$cript) {
				self::$ultimoErro = 'Falha ao criptografar Stream AccessKey.';
				return false;
			}
			$dados['stream_api_key'] = $cript;
		}
		if ($tokenNova !== '') {
			$cript = CryptoHelper::encrypt($tokenNova);
			if (!$cript) {
				self::$ultimoErro = 'Falha ao criptografar Stream Token Key.';
				return false;
			}
			$dados['stream_token_key'] = $cript;
		}
		if ($storageKeyNova !== '') {
			$cript = CryptoHelper::encrypt($storageKeyNova);
			if (!$cript) {
				self::$ultimoErro = 'Falha ao criptografar Storage Access Key.';
				return false;
			}
			$dados['storage_access_key'] = $cript;
		}
		if ($storageTokenNova !== '') {
			$cript = CryptoHelper::encrypt($storageTokenNova);
			if (!$cript) {
				self::$ultimoErro = 'Falha ao criptografar Storage Token Key.';
				return false;
			}
			$dados['storage_token_key'] = $cript;
		}

		try {
			$exist = (new Database('plataforma_bunny'))->select('id = 1', null, 1)->fetchObject(self::class);
			if ($exist instanceof self) {
				(new Database('plataforma_bunny'))->update('id = 1', $dados);
			} else {
				$dados['id'] = 1;
				(new Database('plataforma_bunny'))->insert($dados);
			}
			return true;
		} catch (\Throwable $e) {
			self::$ultimoErro = $e->getMessage();
			return false;
		}
	}
}
